<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\AtendimentoRepository;
use App\Repositories\ContratoRepository;
use App\Repositories\EquipeRepository;
use App\Repositories\MetaRepository;
use App\Repositories\PropagandaRepository;
use App\Repositories\RotacaoRepository;
use App\Repositories\SemanaRepository;
use App\Repositories\VendedorRepository;
use DateTime;

/**
 * Tudo que o painel da TV precisa numa chamada só (get_data): o ranking semanal e o mensal de closers e de equipes já prontos
 * (pontuação, metas efetivas, desempate aplicado), mais a rotação, as propagandas, a próxima música e o CAC. Reproduz o get_data do
 * api.php, passo a passo; as consultas ficam nos Repositories e o cálculo, aqui.
 *
 * Regras que valem saber: a meta efetiva da semana é a do período (aba Metas) e, sem ela, a cadastrada no closer; a mensal, a do
 * período, depois a cadastrada, depois a projeção (meta semanal × nº de semanas do mês). Closers desligados ou suspensos não aparecem
 * no ranking, mas os contratos deles continuam somando na equipe a que pertenciam. Closers de equipe oculta da TV não aparecem no ranking: a equipe vai por último, sem disputar, com os contratos deles.
 */
final class RankingAoVivoService
{
    private const DIAS_CURTOS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    public function __construct(
        private Calendario $calendario,
        private DesempateService $desempate,
        private VendedorRepository $vendedores,
        private EquipeRepository $equipes,
        private ContratoRepository $contratos,
        private AtendimentoRepository $atendimentos,
        private MetaRepository $metas,
        private SemanaRepository $semanas,
        private PropagandaRepository $propagandas,
        private RotacaoRepository $rotacao,
        private ImagemService $imagens,
        private InvestimentoService $investimento
    ) {
    }

    /** @return array<string, mixed> o corpo do get_data, sem o "success" */
    public function dados(): array
    {
        // ── Semana atual: a configurada ou a automática ──────────────────────────────────────────
        $semanaAtual = $this->calendario->semanaAtual();
        $weekStart = $semanaAtual['start'];
        $weekEnd = $semanaAtual['end'];

        $tz = Relogio::fuso();
        $curWeekDates = $this->calendario->datas($weekStart, $weekEnd);
        $weekDays = count($curWeekDates);

        $computedLabels = array_map(fn($dt) => self::DIAS_CURTOS[(int) (new DateTime($dt, $tz))->format('w')], $curWeekDates);
        $computedDates = array_map(fn($dt) => (new DateTime($dt, $tz))->format('d'), $curWeekDates);

        $vendedores = $this->vendedores->ativosParaTv();   // inclui os de equipe oculta da TV: eles não disputam, mas os contratos deles somam na equipe
        $dailyMap = $this->contratos->porVendedorEDia($weekStart, $weekEnd, true);
        // Metas diárias específicas (por dia do mês) dentro da semana atual — uma semana raramente cruza o mês, mas quando cruza os dois meses entram
        $dailyMetasMap = $this->metas->diariasPorCloser(array_unique(array_map(fn($dt) => substr($dt, 0, 7), $curWeekDates)));
        $tipoMap = $this->contratos->tiposPorVendedor($weekStart, $weekEnd, true);
        $atendMap = $this->atendimentos->totaisPorVendedor($weekStart, $weekEnd);
        $tbOrder = $this->desempate->ordem();

        // ── Mês corrente ─────────────────────────────────────────────────────────────────────────
        $agora = Relogio::agora();
        $mesAtual = $agora->format('Y-m');
        $curYrMes = (int) $agora->format('Y');
        $curMoMes = (int) $agora->format('n');
        $mesMap = $this->contratos->totaisPorVendedorNoMes($mesAtual, true);
        $atendMesMap = $this->atendimentos->totaisPorVendedorNoMes($mesAtual);
        $periodoMetasMap = $this->metas->doCloserNoMes($curYrMes, $curMoMes);   // [closer][0|semana_num] = meta (0 = mensal)

        // Semanas do mês: as configuradas em semanas_mes ou, sem configuração, as quartas-feiras que caem no mês
        $monthWeeks = $this->calendario->semanasDoMesParaTv($curYrMes, $curMoMes);

        // week_scores por closer (contratos agrupados por semana do mês)
        $weekScoreMap = [];
        if ($monthWeeks) {
            $mhDays = $this->contratos->porVendedorEDia($monthWeeks[0]['start'], end($monthWeeks)['end'], true);
            foreach ($monthWeeks as $wki => $wk) {
                $dPtr = new DateTime($wk['start'], $tz);
                $dEnd = new DateTime($wk['end'], $tz);
                while ($dPtr <= $dEnd) {
                    $ds = $dPtr->format('Y-m-d');
                    foreach ($mhDays as $vid2 => $days) {
                        if (isset($days[$ds])) {
                            $weekScoreMap[$vid2][$wki] = ($weekScoreMap[$vid2][$wki] ?? 0) + $days[$ds];
                        }
                    }
                    $dPtr->modify('+1 day');
                }
            }
        }

        $mesTipoMap = $this->contratos->tiposPorVendedorNoMes($mesAtual, true);

        // ── A que semana (e a que ano/mês) a semana atual pertence ───────────────────────────────
        $nWeeks = count($monthWeeks);
        $curSemNumGlobal = null;
        $semMetaAno = $curYrMes;
        $semMetaMes = $curMoMes;
        if ($semanaAtual['configured']) {
            // Semana configurada: pergunta direto a semanas_mes (vale mesmo quando ela está arquivada no mês anterior)
            $linha = $this->semanas->porPeriodo($weekStart, $weekEnd);
            if ($linha) {
                $curSemNumGlobal = (int) $linha['semana_num'];
                $semMetaAno = (int) $linha['ano'];
                $semMetaMes = (int) $linha['mes'];
            }
        } else {
            // Semana automática: acha o número entre as semanas do mês, para as metas por semana valerem sem o Calendário configurado
            foreach ($monthWeeks as $wk) {
                if ($wk['start'] === $weekStart && $wk['end'] === $weekEnd) {
                    $curSemNumGlobal = (int) $wk['semana_num'];
                    break;
                }
            }
        }
        // Metas por período do (ano, mês) a que a semana atual realmente pertence
        if ($semMetaAno !== $curYrMes || $semMetaMes !== $curMoMes) {
            $periodoMetasMap = $this->metas->doCloserNoMes($semMetaAno, $semMetaMes);
        }

        // ── Closers ──────────────────────────────────────────────────────────────────────────────
        $linhas = array_map(function ($v) use ($curWeekDates, $dailyMap, $dailyMetasMap, $tipoMap, $mesMap, $weekScoreMap, $mesTipoMap, $nWeeks, $periodoMetasMap, $curSemNumGlobal, $atendMap, $atendMesMap) {
            $vid = (int) $v['id'];
            $daily = array_map(fn($dt) => $dailyMap[$vid][$dt] ?? 0, $curWeekDates);
            // Meta específica de cada dia da semana (null = nenhuma definida naquele dia; mesmo índice de $daily)
            $dailyMetas = array_map(fn($dt) => $dailyMetasMap[$vid][$dt] ?? null, $curWeekDates);
            $weekTotal = array_sum($daily);
            $metas_sem = max(1, (int) $v['metas']);
            $metas_men = max(0, (int) $v['meta_mensal']);
            $curSemNum = $curSemNumGlobal;
            $pm = $periodoMetasMap[$vid] ?? [];
            $meta_s_eff = ($curSemNum !== null && isset($pm[$curSemNum]) && $pm[$curSemNum] > 0) ? $pm[$curSemNum] : $metas_sem;
            $meta_m_eff = (isset($pm[0]) && $pm[0] > 0) ? $pm[0] : ($metas_men > 0 ? $metas_men : null);
            $score = (int) round($weekTotal / max(1, $meta_s_eff) * 100);
            $faltam = max(0, $meta_s_eff - $weekTotal);
            // Meta mensal EFETIVA do score_mensal: a cadastrada quando existe, senão a projeção meta_semanal × nº de semanas. Nunca é null.
            $meta_m_val = $meta_m_eff !== null ? $meta_m_eff : $metas_sem * max(1, $nWeeks);
            $score_mensal = $meta_m_val > 0 ? (int) round(($mesMap[$vid] ?? 0) / $meta_m_val * 100) : 0;
            $tipos = $tipoMap[$vid] ?? [];
            $meTipos = $mesTipoMap[$vid] ?? [];
            $wScores = [];
            for ($i = 0; $i < $nWeeks; $i++) {
                $wScores[] = $weekScoreMap[$vid][$i] ?? 0;
            }
            $atend_sem = $atendMap[$vid] ?? 0;
            $atend_mes = $atendMesMap[$vid] ?? 0;
            $conv_sem = $atend_sem > 0 ? round($weekTotal / $atend_sem * 100, 1) : 0.0;
            $conv_mes = $atend_mes > 0 ? round(($mesMap[$vid] ?? 0) / $atend_mes * 100, 1) : 0.0;
            return [
                'id' => $vid,
                'name' => $v['nome'],
                'score' => $score,
                'score_mensal' => $score_mensal,
                'metas' => $meta_s_eff,
                'meta_semanal' => $metas_sem,
                'meta_mensal' => $metas_men,
                'meta_diaria' => (int) $v['meta_diaria'],
                'meta_s_eff' => $meta_s_eff,
                'meta_m_eff' => $meta_m_eff,
                'meta_m_val' => $meta_m_val,
                'vendas' => $weekTotal,
                'faltam' => $faltam,
                'color' => $v['cor'],
                'img' => $v['img_pravatar'] ? (int) $v['img_pravatar'] : null,
                'imageUrl' => $this->imagens->url($v['img_url']),
                'daily' => $daily,
                'daily_metas' => $dailyMetas,
                'equipe_id' => $v['equipe_id'] ? (int) $v['equipe_id'] : null,
                'equipe_nome' => $v['equipe_nome'],
                'equipe_cor' => $v['equipe_cor'],
                'tipo_totals' => $tipos,
                'mes_tipo_totals' => $meTipos,
                'aux_acidente' => $tipos['aux_acidente'] ?? 0,
                'vendas_mes' => $mesMap[$vid] ?? 0,
                'week_scores' => $wScores,
                'atendimentos' => $atend_sem,
                'atendimentos_mes' => $atend_mes,
                'conv_rate' => $conv_sem,
                'conv_rate_mes' => $conv_mes,
                'oculta_tv' => (int) $v['equipe_oculta'],
            ];
        }, $vendedores);
        // Closers de equipe oculta da TV não disputam: saem da lista; os contratos deles entram só no total da equipe
        $data = array_values(array_filter($linhas, static fn(array $r): bool => $r['oculta_tv'] === 0));

        // Ordem semanal: pontuação → desempate → nome
        usort($data, fn($a, $b) => $this->comparar($a, $b, $tbOrder, 'score', 'tipo_totals', 'conv_rate', 'name'));
        // Ordem mensal: pontuação do mês → desempate mensal → nome
        $data_mensal = $data;
        usort($data_mensal, fn($a, $b) => $this->comparar($a, $b, $tbOrder, 'score_mensal', 'mes_tipo_totals', 'conv_rate_mes', 'name'));

        // ── Equipes ──────────────────────────────────────────────────────────────────────────────
        $teamMap = [];
        foreach ($linhas as $v) {
            if (!$v['equipe_id']) {
                continue;
            }
            $eid = $v['equipe_id'];
            if (!isset($teamMap[$eid])) {
                $teamMap[$eid] = [
                    'id' => $eid, 'nome' => $v['equipe_nome'], 'cor' => $v['equipe_cor'], 'oculta_tv' => $v['oculta_tv'],
                    'vendas' => 0, 'metas' => 0, 'vendas_mes' => 0, 'members' => 0,
                    'imageUrl' => null, 'meta_semanal' => 0, 'meta_mensal' => 0,
                    'daily' => array_fill(0, $weekDays, 0),
                    'week_scores' => array_fill(0, $nWeeks, 0),
                    'tipo_totals' => [], 'mes_tipo_totals' => [],
                ];
            }
            $t = &$teamMap[$eid];
            $t['vendas'] += $v['vendas'];
            $t['metas'] += $v['metas'];
            $t['vendas_mes'] += $v['vendas_mes'];
            $t['members'] += 1;
            foreach ($v['daily'] as $di => $qty) {
                $t['daily'][$di] += $qty;
            }
            foreach ($v['week_scores'] as $wki => $qty) {
                $t['week_scores'][$wki] += $qty;
            }
            foreach ($v['tipo_totals'] as $tp => $qty) {
                $t['tipo_totals'][$tp] = ($t['tipo_totals'][$tp] ?? 0) + $qty;
            }
            foreach ($v['mes_tipo_totals'] as $tp => $qty) {
                $t['mes_tipo_totals'][$tp] = ($t['mes_tipo_totals'][$tp] ?? 0) + $qty;
            }
        }
        unset($t);

        foreach ($this->equipes->logoEMetas(array_keys($teamMap)) as $tr) {
            $eid = (int) $tr['id'];
            if (!isset($teamMap[$eid])) {
                continue;
            }
            $teamMap[$eid]['imageUrl'] = $this->imagens->url($tr['img_url']);
            $teamMap[$eid]['meta_semanal'] = (int) $tr['meta_semanal'];
            $teamMap[$eid]['meta_mensal'] = (int) $tr['meta_mensal'];
        }

        // Contratos dos ex-membros (desligados ou suspensos) somam na equipe em que estavam. Os mapas de contratos já têm todos os
        // closers, porque as consultas não filtram por deleted_at.
        foreach ($this->vendedores->inativosComEquipe() as $ev) {
            $vid = (int) $ev['id'];
            $eid = (int) $ev['equipe_id'];
            if (!isset($teamMap[$eid])) {
                continue;
            }
            $t = &$teamMap[$eid];
            $exDaily = array_map(fn($dt) => $dailyMap[$vid][$dt] ?? 0, $curWeekDates);
            $t['vendas'] += array_sum($exDaily);
            $t['vendas_mes'] += $mesMap[$vid] ?? 0;
            foreach ($exDaily as $di => $qty) {
                $t['daily'][$di] += $qty;
            }
            for ($wi = 0; $wi < $nWeeks; $wi++) {
                $t['week_scores'][$wi] += $weekScoreMap[$vid][$wi] ?? 0;
            }
            foreach ($tipoMap[$vid] ?? [] as $tp => $qty) {
                $t['tipo_totals'][$tp] = ($t['tipo_totals'][$tp] ?? 0) + $qty;
            }
            foreach ($mesTipoMap[$vid] ?? [] as $tp => $qty) {
                $t['mes_tipo_totals'][$tp] = ($t['mes_tipo_totals'][$tp] ?? 0) + $qty;
            }
        }
        unset($t);

        // Metas por período das equipes (mesma semana/mês usados nos closers)
        $eqPeriodoMap = $teamMap ? $this->metas->doTipoNoMes('equipe', $semMetaAno, $semMetaMes) : [];

        $teams = array_values($teamMap);
        foreach ($teams as &$t) {
            $eid = $t['id'];
            $ep = $eqPeriodoMap[$eid] ?? [];
            // Meta semanal efetiva: do período → padrão da equipe → soma das metas dos membros
            $ms = ($curSemNumGlobal !== null && isset($ep[$curSemNumGlobal]) && $ep[$curSemNumGlobal] > 0)
                ? $ep[$curSemNumGlobal]
                : ($t['meta_semanal'] > 0 ? $t['meta_semanal'] : max(1, $t['metas']));
            // Meta mensal efetiva: do período (semana 0) → padrão da equipe → soma × nº de semanas
            $mm = (isset($ep[0]) && $ep[0] > 0)
                ? $ep[0]
                : ($t['meta_mensal'] > 0 ? $t['meta_mensal'] : max(1, $t['metas'] * max(1, $nWeeks)));
            $t['score'] = (int) round($t['vendas'] / $ms * 100);
            $t['score_mensal'] = (int) round($t['vendas_mes'] / $mm * 100);
            $t['faltam'] = max(0, $ms - $t['vendas']);
            $t['faltam_mes'] = max(0, $mm - $t['vendas_mes']);
            $t['meta_s_eff'] = $ms;
            $t['meta_m_eff'] = $mm;
        }
        unset($t);

        usort($teams, fn($a, $b) => ($a['oculta_tv'] <=> $b['oculta_tv']) ?: $this->comparar($a, $b, $tbOrder, 'score', 'tipo_totals', 'conv_rate', 'nome'));
        $teams_mensal = $teams;
        usort($teams_mensal, fn($a, $b) => ($a['oculta_tv'] <=> $b['oculta_tv']) ?: $this->comparar($a, $b, $tbOrder, 'score_mensal', 'mes_tipo_totals', 'conv_rate', 'nome'));

        // ── Totais ───────────────────────────────────────────────────────────────────────────────
        $totalMes = $this->contratos->totalDoMes($mesAtual);
        $totalMesComOcultas = $this->contratos->totalDoMes($mesAtual, true);   // para a página da campanha da Super Meta (propaganda): soma também a equipe oculta   // contratos do mês de quem disputa, inclusive ex-closers (sem os de equipe oculta da TV)

        // Total da semana e meta: o admin pode sobrepor a soma automática com a meta "geral" do período
        $totalSemana = array_sum(array_column($data, 'vendas'));
        $metaSemanaTotal = array_sum(array_column($data, 'meta_s_eff'));
        if ($curSemNumGlobal !== null) {
            $geral = $this->metas->geral($semMetaAno, $semMetaMes, $curSemNumGlobal);
            if ($geral !== null) {
                $metaSemanaTotal = $geral;
            }
        }

        // Total do dia e meta do dia: vendas de hoje de todos os closers × soma das metas diárias de quem tem meta para hoje. Sem nenhuma
        // meta diária configurada hoje, o total fica 0 e o pop-up de meta geral do dia simplesmente não dispara.
        $hojeIdx = array_search($agora->format('Y-m-d'), $curWeekDates, true);
        $totalHoje = 0;
        $metaHojeTotal = 0;
        foreach ($data as $d) {
            if ($hojeIdx !== false) {
                $totalHoje += $d['daily'][$hojeIdx] ?? 0;
                $metaHojeTotal += (int) ($d['daily_metas'][$hojeIdx] ?? 0);
            }
        }

        // CAC: Meta Ads quando configurado, senão o valor digitado na aba Investimento
        $inv = $this->investimento->paraTv($curYrMes, $curMoMes);
        $investimento = $inv['investimento'];
        $cac = ($investimento > 0 && $totalMes > 0) ? round($investimento / $totalMes, 2) : null;

        return [
            'data' => $data,
            'tiebreaker' => $tbOrder,   // ordem dos critérios de desempate (aba Rankings); a TV e a página de campanha desempatam igual
            'data_mensal' => $data_mensal,
            'teams' => $teams,
            'teams_mensal' => $teams_mensal,
            'monthWeeks' => $monthWeeks,
            'weekDays' => $weekDays,
            'weekLabels' => $computedLabels,
            'weekDates' => $computedDates,
            'weekDatesFull' => $curWeekDates,
            'weekBounds' => $semanaAtual,
            'total_mes' => $totalMes,
            'total_mes_com_ocultas' => $totalMesComOcultas,
            'total_semana' => $totalSemana,
            'meta_semana_total' => $metaSemanaTotal,
            'total_hoje' => $totalHoje,
            'meta_dia_total' => $metaHojeTotal,
            'cac' => $cac,
            'investimento_mes' => $investimento,
            'investimento_fonte' => $inv['fonte'],
            'mes_label' => $agora->format('m/Y'),
            'curSemNum' => $curSemNumGlobal,
            'curSemAno' => $semMetaAno,
            'curSemMes' => $semMetaMes,
            'rotation' => $this->rotacao->itens(),
            'propagandas' => $this->propagandas->paraPainel(),
            'bg_next_track' => $this->rotacao->proximaMusica(),
            'timestamp' => $agora->getTimestamp(),
        ];
    }

    /**
     * Ordem do ranking: maior pontuação na frente; empate resolvido pelos critérios do admin, na ordem; por fim o nome.
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param array<int, mixed> $ordem
     */
    private function comparar(array $a, array $b, array $ordem, string $chavePontos, string $chaveTipos, string $chaveConversao, string $chaveNome): int
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
        return strcmp((string) $a[$chaveNome], (string) $b[$chaveNome]);   // (string): equipe sem nome (id solto) vale '' como no PHP sem modo estrito
    }
}
