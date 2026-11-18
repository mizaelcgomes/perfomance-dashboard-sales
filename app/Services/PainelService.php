<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\AtendimentoRepository;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ContratoRepository;
use App\Repositories\EquipeRepository;
use App\Repositories\MetaRepository;
use App\Repositories\SemanaRepository;
use App\Repositories\VendedorRepository;
use DateTime;

/**
 * Tudo que o Painel do admin precisa para UMA semana e o mês selecionado, numa chamada só (get_painel). Sem parâmetros: a semana
 * atual e o mês corrente. Com ano + mês + número da semana: aquela semana, e o mês de ano/mês nos totais mensais. As metas e a
 * ordenação seguem a regra do ranking ao vivo (meta por período → meta cadastrada; mensal → projeção semanal × nº de semanas;
 * desempate pela ordem configurada). Closers desligados com contratos no período entram marcados e no fim; férias ficam de fora.
 * Reproduz o get_painel do api.php.
 */
final class PainelService
{
    private const DIAS_CURTOS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    public function __construct(
        private Calendario $calendario,
        private DesempateService $desempate,
        private SemanaRepository $semanas,
        private VendedorRepository $vendedores,
        private EquipeRepository $equipes,
        private ContratoRepository $contratos,
        private AtendimentoRepository $atendimentos,
        private MetaRepository $metas,
        private ConfiguracaoRepository $config,
        private InvestimentoService $investimento,
        private ImagemService $imagens
    ) {
    }

    /** @return array<string, mixed> o corpo do get_painel, com "success" */
    public function painel(int $reqAno, int $reqMes, int $reqSem): array
    {
        $tz = Relogio::fuso();
        $agora = Relogio::agora();
        $hoje = $agora->format('Y-m-d');
        $mesOk = $reqMes >= 1 && $reqMes <= 12;

        // 1) Semana e mês selecionados
        $cur = $this->calendario->semanaAtual();
        $wSem = null;
        if ($reqAno && $mesOk && $reqSem >= 1) {
            $achada = null;
            foreach ($this->calendario->semanasDoMes($reqAno, $reqMes) as $w) {
                if ($w['semana_num'] === $reqSem) {
                    $achada = $w;
                }
            }
            if (!$achada) {
                return ['success' => false, 'message' => 'Semana não encontrada'];
            }
            $wStart = $achada['start'];
            $wEnd = $achada['end'];
            $wAno = $reqAno;
            $wMes = $reqMes;
            $wSem = $reqSem;
            $mAno = $reqAno;
            $mMes = $reqMes;
        } else {
            $wStart = $cur['start'];
            $wEnd = $cur['end'];
            $linha = $this->semanas->porPeriodo($wStart, $wEnd);
            if ($linha) {
                $wSem = (int) $linha['semana_num'];
                $wAno = (int) $linha['ano'];
                $wMes = (int) $linha['mes'];
            } else {
                $wAno = (int) $agora->format('Y');
                $wMes = (int) $agora->format('n');
                foreach ($this->calendario->semanasDoMes($wAno, $wMes) as $w) {
                    if ($w['start'] === $wStart && $w['end'] === $wEnd) {
                        $wSem = $w['semana_num'];
                    }
                }
            }
            $mAno = $reqAno ?: (int) $agora->format('Y');
            $mMes = $mesOk ? $reqMes : (int) $agora->format('n');
        }
        $isCurrent = ($wStart === $cur['start'] && $wEnd === $cur['end']);

        $dates = [];
        $labels = [];
        $nums = [];
        for ($d = new DateTime($wStart, $tz), $dEnd = new DateTime($wEnd, $tz); $d <= $dEnd; $d->modify('+1 day')) {
            $dates[] = $d->format('Y-m-d');
            $labels[] = self::DIAS_CURTOS[(int) $d->format('w')];
            $nums[] = $d->format('d');
        }
        $mIni = sprintf('%04d-%02d-01', $mAno, $mMes);
        $mFim = (new DateTime($mIni, $tz))->format('Y-m-t');
        $nWeeksM = max(1, count($this->calendario->semanasDoMes($mAno, $mMes)));

        // 2) Dados-base (contratos de TODOS os vendedores — ex-closers entram nas equipes)
        $dailyMap = $this->contratos->porVendedorEDia($wStart, $wEnd);

        // Mês: contratos por closer E por dia (alimenta a Visão do Time em modo Mês)
        $mDates = [];
        $mNums = [];
        $mLabels = [];
        for ($d = new DateTime($mIni, $tz), $dEnd = new DateTime($mFim, $tz); $d <= $dEnd; $d->modify('+1 day')) {
            $mDates[] = $d->format('Y-m-d');
            $mNums[] = $d->format('d');
            $mLabels[] = self::DIAS_CURTOS[(int) $d->format('w')];
        }
        $mDailyMap = $this->contratos->porVendedorEDia($mIni, $mFim);
        $mesMap = [];
        foreach ($mDailyMap as $vv => $porDia) {
            $mesMap[$vv] = array_sum($porDia);
        }
        $totalMes = array_sum($mesMap);
        // Contratos por dia do mês de TODOS os vendedores (inclui ex-closers e em férias): a soma é o "Contratos Mês"
        $mIdx = array_flip($mDates);
        $mDayTotals = array_fill(0, count($mDates), 0);
        foreach ($mDailyMap as $byDate) {
            foreach ($byDate as $dt => $q) {
                if (isset($mIdx[$dt])) {
                    $mDayTotals[$mIdx[$dt]] += $q;
                }
            }
        }

        // Último dia com fechamento de cada closer (até hoje) — alimenta "sem fechamentos há N dias"
        $lastMap = $this->contratos->ultimoDiaComVenda($hoje);
        // Meta de cada DIA da semana, por closer (null = nenhuma definida naquele dia)
        $dailyMetasMap = $this->metas->diariasPorCloser(array_unique(array_map(fn($dt) => substr($dt, 0, 7), $dates)));
        $atendMap = $this->atendimentos->totaisPorVendedor($wStart, $wEnd);
        $tipoMap = $this->contratos->tiposPorVendedor($wStart, $wEnd);

        $pmW = $this->metas->doTipoNoMes('closer', $wAno, $wMes);
        $pmM = $this->metas->doTipoNoMes('closer', $mAno, $mMes);
        $peW = $this->metas->doTipoNoMes('equipe', $wAno, $wMes);
        $peM = $this->metas->doTipoNoMes('equipe', $mAno, $mMes);

        // 3) Closers ativos + ex-closers com contrato no período (marcados, no fim da lista). Férias continuam fora.
        $closers = [];
        foreach ($this->vendedores->naoSuspensosParaPainel() as $v) {
            $vid = (int) $v['id'];
            $daily = array_map(fn($dt) => $dailyMap[$vid][$dt] ?? 0, $dates);
            $vendas = array_sum($daily);
            $metasS = max(1, (int) $v['metas']);
            $metaS = ($wSem !== null && ($pmW[$vid][$wSem] ?? 0) > 0) ? $pmW[$vid][$wSem] : $metasS;
            $metaMEff = (($pmM[$vid][0] ?? 0) > 0) ? $pmM[$vid][0] : ((int) $v['meta_mensal'] > 0 ? (int) $v['meta_mensal'] : null);
            $metaM = $metaMEff !== null ? $metaMEff : $metasS * $nWeeksM;
            $vMes = $mesMap[$vid] ?? 0;
            $atend = $atendMap[$vid] ?? 0;
            $exCloser = $v['deleted_at'] !== null;
            if ($exCloser && $vendas === 0 && $vMes === 0) {
                continue;       // ex-closer sem contrato no período não aparece
            }
            $closers[] = [
                'id' => $vid,
                'name' => $v['nome'],
                'equipe_id' => $v['equipe_id'] ? (int) $v['equipe_id'] : null,
                'color' => $v['cor'],
                'imageUrl' => $this->imagens->url($v['img_url']),
                'daily' => $daily,
                'vendas' => $vendas,
                'meta' => $metaS,
                'score' => (int) round($vendas / max(1, $metaS) * 100),
                'vendas_mes' => $vMes,
                'meta_mes' => $metaM,
                'score_mes' => $metaM > 0 ? (int) round($vMes / $metaM * 100) : 0,
                'tipo_totals' => $tipoMap[$vid] ?? [],
                'conv_rate' => $atend > 0 ? round($vendas / $atend * 100, 1) : 0.0,
                'daily_metas' => array_map(fn($dt) => $dailyMetasMap[$vid][$dt] ?? null, $dates),   // meta de cada dia da semana (ou null)
                'daily_mes' => array_map(fn($dt) => $mDailyMap[$vid][$dt] ?? 0, $mDates),          // contratos por dia do mês
                'ex_closer' => $exCloser,
                'last_sale' => $lastMap[$vid] ?? null,
                'dias_sem_venda' => isset($lastMap[$vid]) ? (int) (new DateTime($lastMap[$vid], $tz))->diff(new DateTime($hoje, $tz))->days : null,
            ];
        }
        $ordem = $this->desempate->ordem();
        usort($closers, function ($a, $b) use ($ordem) {
            if ($a['ex_closer'] !== $b['ex_closer']) {
                return $a['ex_closer'] ? 1 : -1;      // ex-closers sempre no fim
            }
            if ($b['score'] !== $a['score']) {
                return $b['score'] - $a['score'];
            }
            foreach ($ordem as $criterio) {
                $cmp = $this->desempate->comparar($a, $b, $criterio, 'tipo_totals', 'conv_rate');
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        // 4) Equipes (todas), com os contratos dos ex-membros somados, como na TV
        $exPorEquipe = [];
        foreach ($this->vendedores->inativosComEquipe() as $ev) {
            $exPorEquipe[(int) $ev['equipe_id']][] = (int) $ev['id'];
        }
        $teams = [];
        foreach ($this->equipes->todasPorNome() as $e) {
            $eid = (int) $e['id'];
            $n = 0;
            $vS = 0;
            $vM = 0;
            $somaMeta = 0;
            foreach ($closers as $c) {
                if ($c['equipe_id'] === $eid && !$c['ex_closer']) {
                    $n++;
                    $vS += $c['vendas'];
                    $vM += $c['vendas_mes'];
                    $somaMeta += $c['meta'];
                }
            }
            foreach ($exPorEquipe[$eid] ?? [] as $xid) {
                foreach ($dates as $dt) {
                    $vS += $dailyMap[$xid][$dt] ?? 0;
                }
                $vM += $mesMap[$xid] ?? 0;
            }
            // série diária do mês da equipe (membros ativos + ex-membros, como o total da equipe)
            $serie = array_fill(0, count($mDates), 0);
            foreach ($closers as $c) {
                if ($c['equipe_id'] === $eid && !$c['ex_closer']) {
                    foreach ($c['daily_mes'] as $i => $q) {
                        $serie[$i] += $q;
                    }
                }
            }
            foreach ($exPorEquipe[$eid] ?? [] as $xid) {
                foreach ($mDailyMap[$xid] ?? [] as $dt => $q) {
                    if (isset($mIdx[$dt])) {
                        $serie[$mIdx[$dt]] += $q;
                    }
                }
            }
            $ms = ($wSem !== null && ($peW[$eid][$wSem] ?? 0) > 0) ? $peW[$eid][$wSem] : ((int) $e['meta_semanal'] > 0 ? (int) $e['meta_semanal'] : max(1, $somaMeta));
            $mm = (($peM[$eid][0] ?? 0) > 0) ? $peM[$eid][0] : ((int) $e['meta_mensal'] > 0 ? (int) $e['meta_mensal'] : max(1, $somaMeta * $nWeeksM));
            $teams[] = [
                'id' => $eid, 'nome' => $e['nome'], 'cor' => $e['cor'], 'imageUrl' => $this->imagens->url($e['img_url']),
                'closers' => $n, 'vendas' => $vS, 'meta' => $ms, 'score' => (int) round($vS / max(1, $ms) * 100),
                'vendas_mes' => $vM, 'meta_mes' => $mm, 'score_mes' => (int) round($vM / max(1, $mm) * 100),
                'daily_mes' => $serie,
            ];
        }

        // 5) Mix de benefícios da semana, por equipe (0 = sem equipe) — inclui ex-closers
        $tiposEquipe = [];
        foreach ($this->contratos->tiposPorEquipe($wStart, $wEnd) as $eq => $linhas) {
            $tiposEquipe[$eq] = MixDeBeneficios::expandir($linhas);
        }

        // 6) Totais e metas coletivas (a meta "geral" da aba Metas sobrepõe a soma)
        $ativos = array_values(array_filter($closers, fn($c) => !$c['ex_closer']));
        $totalSemana = array_sum(array_map('array_sum', $dailyMap));          // todos os vendedores (ativos, férias e ex): mesma regra do total do mês
        $metaSemanaTotal = array_sum(array_column($ativos, 'meta'));
        $metaMesTotal = array_sum(array_column($ativos, 'meta_mes'));
        if ($wSem !== null && ($g = $this->metas->geral($wAno, $wMes, $wSem)) !== null) {
            $metaSemanaTotal = $g;
        }
        if (($g = $this->metas->geral($mAno, $mMes, 0)) !== null) {
            $metaMesTotal = $g;
        }
        $suspensos = $this->vendedores->contarSuspensos();

        // 7) CAC do mês selecionado: mês corrente = Meta Ads (se configurado) ou valor manual; meses passados = histórico mensal
        $inv = $this->investimento->paraPainel($mAno, $mMes, $mAno === (int) $agora->format('Y') && $mMes === (int) $agora->format('n'));
        $investimento = $inv['investimento'];

        return [
            'success' => true,
            'week' => ['ano' => $wAno, 'mes' => $wMes, 'semana_num' => $wSem, 'start' => $wStart, 'end' => $wEnd, 'dates' => $dates, 'labels' => $labels, 'nums' => $nums, 'is_current' => $isCurrent, 'today' => $hoje],
            'month' => ['ano' => $mAno, 'mes' => $mMes, 'dates' => $mDates, 'nums' => $mNums, 'labels' => $mLabels, 'daily_total' => $mDayTotals],
            'closers' => $closers,
            'teams' => $teams,
            'tipos_equipe' => (object) $tiposEquipe,
            'totals' => ['closers' => count($ativos), 'suspensos' => $suspensos, 'semana' => $totalSemana, 'meta_semana' => $metaSemanaTotal, 'mes' => $totalMes, 'meta_mes' => $metaMesTotal],
            'cac' => ['valor' => ($investimento > 0 && $totalMes > 0) ? round($investimento / $totalMes, 2) : null, 'investimento' => $investimento, 'meta' => (float) $this->config->valor('meta_cac', '85'), 'fonte' => $inv['fonte']],
        ];
    }
}
