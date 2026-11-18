<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\CerimoniaRepository;
use App\Repositories\ContratoRepository;
use App\Repositories\MetaRepository;
use App\Repositories\VendedorRepository;
use DateTime;

/**
 * Cerimônia de entrega das metas: quem bateu a meta na semana anterior (em ordem de pontuação, com o desempate do admin) e o
 * que a TV lê (os homenageados disparados, a música e a duração da tela final).
 * Reproduz computePreviousWeekWinners, get_ceremony e get_ceremony_preview do api.php.
 */
final class CerimoniaService
{
    /** Quantas semanas completas aparecem para escolha na cerimônia. */
    private const SEMANAS_NA_ESCOLHA = 12;

    public function __construct(
        private Calendario $calendario,
        private DesempateService $desempate,
        private VendedorRepository $vendedores,
        private ContratoRepository $contratos,
        private MetaRepository $metas,
        private CerimoniaRepository $cerimonia,
        private ImagemService $imagens
    ) {
    }

    /**
     * Closers que bateram a meta semanal na semana anterior, do 1º ao último.
     * @return array{weekStart: string, weekEnd: string, winners: array<int, array<string, mixed>>}
     */
    public function vencedoresDaSemanaAnterior(): array
    {
        return $this->vencedoresDaSemana($this->calendario->semanaAnterior());
    }

    /**
     * Closers que bateram a meta semanal numa semana escolhida (uma das de semanasAnteriores), do 1º ao último.
     * @param array{start: string, end: string, semana_num: ?int, ano: ?int, mes: ?int, configured: bool} $prev
     * @return array{weekStart: string, weekEnd: string, winners: array<int, array<string, mixed>>}
     */
    public function vencedoresDaSemana(array $prev): array
    {
        $ini = $prev['start'];
        $fim = $prev['end'];
        $semanaNum = $prev['semana_num'];
        $ano = $prev['ano'];
        $mes = $prev['mes'];
        if (!$ano || !$mes) {
            $d = new DateTime($ini);
            $ano = (int) $d->format('Y');
            $mes = (int) $d->format('n');
        }

        $ordem = $this->desempate->ordem();
        $vendasPorCloser = $this->contratos->totaisPorVendedor($ini, $fim);
        $tiposPorCloser = $this->contratos->tiposPorVendedor($ini, $fim);
        $metasDoPeriodo = $this->metas->doCloserNoMes($ano, $mes);

        $vencedores = [];
        foreach ($this->vendedores->ativosParaRanking() as $v) {
            $vid = (int) $v['id'];
            $vendas = $vendasPorCloser[$vid] ?? 0;
            $metaSemanal = max(1, (int) $v['metas']);
            $pm = $metasDoPeriodo[$vid] ?? [];
            $metaEfetiva = ($semanaNum !== null && isset($pm[$semanaNum]) && $pm[$semanaNum] > 0) ? $pm[$semanaNum] : $metaSemanal;
            if ($metaEfetiva > 0 && $vendas >= $metaEfetiva) {
                $tipos = $tiposPorCloser[$vid] ?? [];
                $vencedores[] = [
                    'id' => $vid,
                    'name' => $v['nome'],
                    'vendas' => $vendas,
                    'meta' => $metaEfetiva,
                    'score' => (int) round($vendas / $metaEfetiva * 100),
                    'imageUrl' => $this->imagens->url($v['img_url']),
                    'equipeNome' => $v['equipe_nome'],
                    'equipeCor' => $v['equipe_cor'],
                    'tipo_totals' => $tipos,
                    'conv_rate' => $vendas > 0 ? array_sum($tipos) / $vendas : 0,
                ];
            }
        }
        usort($vencedores, function ($a, $b) use ($ordem) {
            if ($b['score'] !== $a['score']) {
                return $b['score'] - $a['score'];
            }
            foreach ($ordem as $criterio) {
                $cmp = $this->desempate->comparar($a, $b, $criterio, 'tipo_totals', 'conv_rate');
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp($a['name'], $b['name']);
        });
        foreach ($vencedores as $i => &$w) {
            $w['pos'] = $i + 1;
        }
        unset($w);

        return ['weekStart' => $ini, 'weekEnd' => $fim, 'winners' => $vencedores];
    }

    /**
     * A prévia que o admin vê antes de disparar: os vencedores de agora mais a música e a duração configuradas.
     * @return array<string, mixed>
     */
    public function previa(?string $inicio = null): ?array
    {
        $semana = $this->semanaEscolhida($inicio);
        if ($semana === null) {
            return null;
        }
        $cfg = $this->cerimonia->configuracao();
        return $this->vencedoresDaSemana($semana) + [
            'musica' => ($cfg && $cfg['musica']) ? 'music/ceremony/' . $cfg['musica'] : null,
            'final_duration' => $cfg ? (int) $cfg['final_duration'] : 15,
            'semanas' => $this->opcoesDeSemana(),
        ];
    }

    /** As semanas que se podem escolher (as últimas completas), da mais recente para a mais antiga. @return array<int, array{start: string, end: string}> */
    public function opcoesDeSemana(): array
    {
        return array_map(fn(array $s): array => ['start' => $s['start'], 'end' => $s['end']], $this->calendario->semanasAnteriores(self::SEMANAS_NA_ESCOLHA));
    }

    /** A semana pedida pelo início (como vem das opções), ou a anterior se não veio nenhuma. null se o início não for uma das opções. */
    public function semanaEscolhida(?string $inicio): ?array
    {
        $semanas = $this->calendario->semanasAnteriores(self::SEMANAS_NA_ESCOLHA);
        if ($inicio === null || $inicio === '') {
            return $semanas[0];
        }
        foreach ($semanas as $s) {
            if ($s['start'] === $inicio) {
                return $s;
            }
        }
        return null;
    }

    /**
     * O que a TV lê: os homenageados do último disparo, quando foi, a música e a duração da tela final.
     * @return array<string, mixed>
     */
    public function estado(): array
    {
        $linha = $this->cerimonia->estado();
        $payload = $linha && $linha['payload'] ? json_decode((string) $linha['payload'], true) : null;
        return [
            'winners' => $payload['winners'] ?? [],
            'weekStart' => $payload['weekStart'] ?? null,
            'weekEnd' => $payload['weekEnd'] ?? null,
            'triggered_at' => $linha['triggered_at'] ?? null,
            'musica' => ($linha && $linha['musica']) ? 'music/ceremony/' . $linha['musica'] : null,
            'final_duration' => $linha ? (int) $linha['final_duration'] : 15,
        ];
    }
}
