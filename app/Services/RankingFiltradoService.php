<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\AtendimentoRepository;
use App\Repositories\ContratoRepository;
use App\Repositories\EquipeRepository;
use App\Repositories\MetaRepository;
use App\Repositories\VendedorRepository;

/**
 * Ranking de closers ou de equipes de UMA semana ou UM mês escolhido (aba Rankings do admin, get_rankings_filtered). As regras são as
 * do ranking ao vivo: meta efetiva por período, desempate do admin; closers desligados só aparecem se tiveram contrato ou atendimento
 * no período (e vão para o fim); os contratos dos ex-membros somam na equipe. Reproduz o get_rankings_filtered do api.php.
 */
final class RankingFiltradoService
{
    public function __construct(
        private Calendario $calendario,
        private DesempateService $desempate,
        private VendedorRepository $vendedores,
        private EquipeRepository $equipes,
        private ContratoRepository $contratos,
        private AtendimentoRepository $atendimentos,
        private MetaRepository $metas
    ) {
    }

    /**
     * @param string $tipo 'semana' ou 'mes'
     * @param string $periodo número da semana (tipo semana) ou "AAAA-MM" (tipo mes)
     * @param string $referencia 'closer' ou 'equipe'
     * @param int $anoSemana ano (opcional) da semana, para semanas de OUTRO mês; só vale no tipo semana
     * @param int $mesSemana mês (opcional) da semana; só vale no tipo semana
     * @return array<string, mixed> {success, data} ou {success: false, message}
     */
    public function consultar(string $tipo, string $periodo, string $referencia, int $anoSemana = 0, int $mesSemana = 0): array
    {
        $agora = Relogio::agora();
        $anoAtual = (int) $agora->format('Y');
        $mesAtual = (int) $agora->format('n');
        if ($tipo === 'semana' && $anoSemana >= 2000 && $mesSemana >= 1 && $mesSemana <= 12) {
            $anoAtual = $anoSemana;
            $mesAtual = $mesSemana;
        }
        $ordem = $this->desempate->ordem();

        if ($tipo === 'semana') {
            return $this->semana((int) $periodo, $anoAtual, $mesAtual, $referencia === 'equipe', $ordem);
        }
        if ($tipo === 'mes') {
            return $this->mes($periodo, $referencia === 'equipe', $ordem);
        }
        return ['success' => false, 'message' => 'Parâmetros inválidos'];
    }

    /**
     * @param array<int, mixed> $ordem
     * @return array<string, mixed>
     */
    private function semana(int $semNum, int $curY, int $curM, bool $porEquipe, array $ordem): array
    {
        $semana = $this->calendario->limitesDaSemana($curY, $curM, $semNum);
        if (!$semana) {
            return ['success' => false, 'message' => 'Semana não encontrada'];
        }
        $start = $semana['start'];
        $end = $semana['end'];

        $tipoMap = $this->contratos->tiposPorVendedor($start, $end);
        $vendMap = $this->contratos->totaisPorVendedor($start, $end);
        $atendMap = $this->atendimentos->totaisPorVendedor($start, $end);
        $periodoMetasMap = $this->metas->doCloserNaSemana($curY, $curM, $semNum);

        $data = [];
        foreach ($this->vendedores->naoSuspensosPorNome() as $v) {
            $vid = (int) $v['id'];
            $vend = $vendMap[$vid] ?? 0;
            $atend = $atendMap[$vid] ?? 0;
            $metasSem = max(1, (int) $v['metas']);
            $metaEff = (isset($periodoMetasMap[$vid]) && $periodoMetasMap[$vid] > 0) ? $periodoMetasMap[$vid] : $metasSem;
            $exCloser = $v['deleted_at'] !== null;
            if ($exCloser && $vend === 0 && $atend === 0) {
                continue;        // ex-closer sem contrato no período não aparece
            }
            $data[] = [
                'id' => $vid,
                'name' => $v['nome'],
                'ex_closer' => $exCloser,
                'vendas' => $vend,
                'score' => (int) round($vend / max(1, $metaEff) * 100),
                'meta_eff' => $metaEff,
                'atendimentos' => $atend,
                'conv_rate' => $atend > 0 ? round($vend / $atend * 100, 1) : 0.0,
                'tipo_totals' => $tipoMap[$vid] ?? [],
                'equipe_id' => $v['equipe_id'] ? (int) $v['equipe_id'] : null,
            ];
        }
        usort($data, function ($a, $b) use ($ordem) {
            if ($a['ex_closer'] !== $b['ex_closer']) {
                return $a['ex_closer'] ? 1 : -1;      // ex-closers no fim
            }
            return $this->ordenar($a, $b, $ordem, 'score', 'tipo_totals', 'conv_rate', 'name');
        });

        if (!$porEquipe) {
            return ['success' => true, 'data' => $data];
        }

        $equipesMap = [];
        foreach ($data as $v) {
            if (!$v['equipe_id'] || $v['ex_closer']) {
                continue;     // ex-membros entram pelo laço abaixo
            }
            $eid = $v['equipe_id'];
            if (!isset($equipesMap[$eid])) {
                $equipesMap[$eid] = ['id' => $eid, 'vendas' => 0, 'metas' => 0, 'atendimentos' => 0, 'tipo_totals' => []];
            }
            $equipesMap[$eid]['vendas'] += $v['vendas'];
            $equipesMap[$eid]['metas'] += $v['meta_eff'];
            $equipesMap[$eid]['atendimentos'] += $v['atendimentos'];
            foreach ($v['tipo_totals'] as $tp => $qty) {
                $equipesMap[$eid]['tipo_totals'][$tp] = ($equipesMap[$eid]['tipo_totals'][$tp] ?? 0) + $qty;
            }
        }
        // Ex-closers continuam contribuindo com os contratos históricos para a equipe a que pertenciam
        foreach ($this->vendedores->inativosComEquipe() as $ev) {
            $eid = (int) $ev['equipe_id'];
            $vid = (int) $ev['id'];
            if (!isset($equipesMap[$eid])) {
                continue;
            }
            $equipesMap[$eid]['vendas'] += $vendMap[$vid] ?? 0;
            $equipesMap[$eid]['atendimentos'] += $atendMap[$vid] ?? 0;
            foreach ($tipoMap[$vid] ?? [] as $tp => $qty) {
                $equipesMap[$eid]['tipo_totals'][$tp] = ($equipesMap[$eid]['tipo_totals'][$tp] ?? 0) + $qty;
            }
        }

        $metasEquipe = $this->metas->daEquipeNaSemana($curY, $curM, $semNum);
        $saida = [];
        foreach ($this->equipes->todasParaRanking() as $t) {
            $tid = (int) $t['id'];
            $agg = $equipesMap[$tid] ?? ['vendas' => 0, 'metas' => 0, 'atendimentos' => 0, 'tipo_totals' => []];
            $ms = (isset($metasEquipe[$tid]) && $metasEquipe[$tid] > 0) ? $metasEquipe[$tid]
                : ((int) $t['meta_semanal'] > 0 ? (int) $t['meta_semanal'] : max(1, $agg['metas']));
            $saida[] = [
                'id' => $tid,
                'name' => $t['nome'],
                'vendas' => $agg['vendas'],
                'meta' => $ms,
                'score' => (int) round($agg['vendas'] / max(1, $ms) * 100),
                'atendimentos' => $agg['atendimentos'],
                'conv_rate' => $agg['atendimentos'] > 0 ? round($agg['vendas'] / $agg['atendimentos'] * 100, 1) : 0.0,
                'tipo_totals' => $agg['tipo_totals'],
            ];
        }
        usort($saida, fn($a, $b) => $this->ordenar($a, $b, $ordem, 'score', 'tipo_totals', 'conv_rate', 'name'));
        return ['success' => true, 'data' => $saida];
    }

    /**
     * @param array<int, mixed> $ordem
     * @return array<string, mixed>
     */
    private function mes(string $periodo, bool $porEquipe, array $ordem): array
    {
        $partes = explode('-', $periodo);
        $y = (int) ($partes[0] ?? 0);
        $m = (int) ($partes[1] ?? 0);   // sem "-" no período o mês vira 0, como sempre foi
        $ym = sprintf('%04d-%02d', $y, $m);
        $nSemanas = $this->calendario->quantidadeDeSemanas($y, $m);

        $mesTipoMap = $this->contratos->tiposPorVendedorNoMes($ym);
        $vendMap = $this->contratos->totaisPorVendedorNoMes($ym);
        $atendMap = $this->atendimentos->totaisPorVendedorNoMes($ym);
        $periodoMetasMap = $this->metas->mensalDosClosers($y, $m);

        $data = [];
        foreach ($this->vendedores->naoSuspensosPorNome() as $v) {
            $vid = (int) $v['id'];
            $vend = $vendMap[$vid] ?? 0;
            $atend = $atendMap[$vid] ?? 0;
            $metasSem = max(1, (int) $v['metas']);
            $metasMen = max(0, (int) $v['meta_mensal']);
            $metaMEff = (isset($periodoMetasMap[$vid]) && $periodoMetasMap[$vid] > 0) ? $periodoMetasMap[$vid] : ($metasMen > 0 ? $metasMen : null);
            $metaMVal = $metaMEff !== null ? $metaMEff : $metasSem * max(1, $nSemanas);
            $exCloser = $v['deleted_at'] !== null;
            if ($exCloser && $vend === 0 && $atend === 0) {
                continue;        // ex-closer sem contrato no período não aparece
            }
            $data[] = [
                'id' => $vid,
                'name' => $v['nome'],
                'ex_closer' => $exCloser,
                'vendas_mes' => $vend,
                'meta_mes' => $metaMVal,
                'score_mensal' => $metaMVal > 0 ? (int) round($vend / $metaMVal * 100) : 0,
                'atendimentos_mes' => $atend,
                'conv_rate_mes' => $atend > 0 ? round($vend / $atend * 100, 1) : 0.0,
                'mes_tipo_totals' => $mesTipoMap[$vid] ?? [],
                'equipe_id' => $v['equipe_id'] ? (int) $v['equipe_id'] : null,
            ];
        }
        usort($data, function ($a, $b) use ($ordem) {
            if ($a['ex_closer'] !== $b['ex_closer']) {
                return $a['ex_closer'] ? 1 : -1;      // ex-closers no fim
            }
            return $this->ordenar($a, $b, $ordem, 'score_mensal', 'mes_tipo_totals', 'conv_rate_mes', 'name');
        });

        if (!$porEquipe) {
            return ['success' => true, 'data' => $data];
        }

        $equipesMap = [];
        foreach ($data as $v) {
            if (!$v['equipe_id'] || $v['ex_closer']) {
                continue;     // ex-membros entram pelo laço abaixo
            }
            $eid = $v['equipe_id'];
            if (!isset($equipesMap[$eid])) {
                $equipesMap[$eid] = ['vendas_mes' => 0, 'metas' => 0, 'atendimentos_mes' => 0, 'mes_tipo_totals' => []];
            }
            $equipesMap[$eid]['vendas_mes'] += $v['vendas_mes'];
            $equipesMap[$eid]['atendimentos_mes'] += $v['atendimentos_mes'];
            foreach ($v['mes_tipo_totals'] as $tp => $qty) {
                $equipesMap[$eid]['mes_tipo_totals'][$tp] = ($equipesMap[$eid]['mes_tipo_totals'][$tp] ?? 0) + $qty;
            }
        }
        foreach ($this->vendedores->inativosComEquipe() as $ev) {
            $eid = (int) $ev['equipe_id'];
            $vid = (int) $ev['id'];
            if (!isset($equipesMap[$eid])) {
                continue;
            }
            $equipesMap[$eid]['vendas_mes'] += $vendMap[$vid] ?? 0;
            $equipesMap[$eid]['atendimentos_mes'] += $atendMap[$vid] ?? 0;
            foreach ($mesTipoMap[$vid] ?? [] as $tp => $qty) {
                $equipesMap[$eid]['mes_tipo_totals'][$tp] = ($equipesMap[$eid]['mes_tipo_totals'][$tp] ?? 0) + $qty;
            }
        }

        $metasEquipe = $this->metas->mensalDasEquipes($y, $m);
        $saida = [];
        foreach ($this->equipes->todasParaRanking() as $t) {
            $tid = (int) $t['id'];
            $agg = $equipesMap[$tid] ?? ['vendas_mes' => 0, 'atendimentos_mes' => 0, 'mes_tipo_totals' => []];
            $somaMetasMembros = $this->vendedores->somaMetasDosMembros($tid);
            $mm = (isset($metasEquipe[$tid]) && $metasEquipe[$tid] > 0) ? $metasEquipe[$tid]
                : ((int) $t['meta_mensal'] > 0 ? (int) $t['meta_mensal'] : max(1, $somaMetasMembros * max(1, $nSemanas)));
            $saida[] = [
                'id' => $tid,
                'name' => $t['nome'],
                'vendas_mes' => $agg['vendas_mes'],
                'meta_mes' => $mm,
                'score_mensal' => (int) round($agg['vendas_mes'] / max(1, $mm) * 100),
                'atendimentos_mes' => $agg['atendimentos_mes'],
                'conv_rate_mes' => $agg['atendimentos_mes'] > 0 ? round($agg['vendas_mes'] / $agg['atendimentos_mes'] * 100, 1) : 0.0,
                'mes_tipo_totals' => $agg['mes_tipo_totals'],
            ];
        }
        usort($saida, fn($a, $b) => $this->ordenar($a, $b, $ordem, 'score_mensal', 'mes_tipo_totals', 'conv_rate_mes', 'name'));
        return ['success' => true, 'data' => $saida];
    }

    /**
     * Pontuação → critérios de desempate do admin → nome.
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param array<int, mixed> $ordem
     */
    private function ordenar(array $a, array $b, array $ordem, string $chavePontos, string $chaveTipos, string $chaveConversao, string $chaveNome): int
    {
        if ($b[$chavePontos] !== $a[$chavePontos]) {
            return $b[$chavePontos] - $a[$chavePontos];
        }
        foreach ($ordem as $criterio) {
            $cmp = $this->desempate->comparar($a, $b, $criterio, $chaveTipos, $chaveConversao);
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return strcmp((string) $a[$chaveNome], (string) $b[$chaveNome]);
    }
}
