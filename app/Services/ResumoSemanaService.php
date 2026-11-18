<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ContratoRepository;
use App\Repositories\EquipeRepository;
use App\Repositories\MetaRepository;
use App\Repositories\SemanaRepository;
use App\Repositories\VendedorRepository;
use DateTime;

/**
 * Tabela "Visão do Time — Semana" do admin (get_week_summary): um closer por linha, uma coluna por dia de uma semana configurada
 * no Calendário. A pontuação aqui para em 100%. Com $incluirDesligados, entram também os desligados que tiveram contrato na semana.
 * Reproduz o get_week_summary do api.php.
 */
final class ResumoSemanaService
{
    private const DIAS_CURTOS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    public function __construct(
        private Calendario $calendario,
        private SemanaRepository $semanas,
        private VendedorRepository $vendedores,
        private EquipeRepository $equipes,
        private ContratoRepository $contratos,
        private MetaRepository $metas,
        private ImagemService $imagens
    ) {
    }

    /** @return array<string, mixed> {success, data, dates, labels, weekStart, weekEnd} ou {success: false, message} */
    public function semana(int $ano, int $mes, int $semanaNum, bool $incluirDesligados): array
    {
        if (!$ano || $mes < 1 || $mes > 12 || $semanaNum < 1) {
            return ['success' => false, 'message' => 'Parâmetros inválidos'];
        }
        $linha = $this->semanas->daSemana($ano, $mes, $semanaNum);
        if (!$linha) {
            return ['success' => false, 'message' => 'Semana não encontrada'];
        }
        $inicio = (string) $linha['data_inicio'];
        $fim = (string) $linha['data_fim'];

        $datas = [];
        $rotulos = [];
        $tz = \App\Core\Relogio::fuso();
        $p = new DateTime($inicio, $tz);
        $e = new DateTime($fim, $tz);
        while ($p <= $e) {
            $datas[] = $p->format('Y-m-d');
            $rotulos[] = self::DIAS_CURTOS[(int) $p->format('w')] . ' ' . $p->format('d');
            $p->modify('+1 day');
        }

        $vendedores = $this->vendedores->paraResumoSemanal($incluirDesligados);
        $porDia = $this->contratos->porVendedorEDia($inicio, $fim);
        if ($incluirDesligados) {
            // desligado só entra se teve contrato no período
            $vendedores = array_values(array_filter($vendedores, fn($v) => $v['deleted_at'] === null || isset($porDia[(int) $v['id']])));
        }
        $nomesEquipe = $this->equipes->nomes();
        $metasPeriodo = $this->metas->doTipoNoMes('closer', $ano, $mes);

        $resultado = array_map(function ($v) use ($datas, $porDia, $metasPeriodo, $semanaNum, $nomesEquipe) {
            $vid = (int) $v['id'];
            $diario = array_map(fn($dt) => $porDia[$vid][$dt] ?? 0, $datas);
            $total = array_sum($diario);
            $pm = $metasPeriodo[$vid] ?? [];
            $meta = (isset($pm[$semanaNum]) && $pm[$semanaNum] > 0) ? $pm[$semanaNum] : max(1, (int) $v['metas']);
            $score = min(100, (int) round($total / max(1, $meta) * 100));
            return [
                'id' => $vid,
                'name' => $v['nome'],
                'daily' => $diario,
                'vendas' => $total,
                'metas' => $meta,
                'score' => $score,
                'color' => $v['cor'],
                'img' => $v['img_pravatar'] ? (int) $v['img_pravatar'] : null,
                'imageUrl' => $this->imagens->url($v['img_url']),
                'equipe_nome' => $v['equipe_id'] ? ($nomesEquipe[(int) $v['equipe_id']] ?? null) : null,
                'deleted' => $v['deleted_at'] !== null,
            ];
        }, $vendedores);

        usort($resultado, function ($a, $b) {
            if ($a['deleted'] !== $b['deleted']) {
                return $a['deleted'] ? 1 : -1;
            }
            if ($b['score'] != $a['score']) {
                return $b['score'] - $a['score'];
            }
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return ['success' => true, 'data' => $resultado, 'dates' => $datas, 'labels' => $rotulos, 'weekStart' => $inicio, 'weekEnd' => $fim];
    }
}
