<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\AcaoRepository;
use App\Repositories\ContaCloserRepository;
use App\Repositories\ContratoRepository;
use App\Repositories\VendedorRepository;
use DateTime;

/**
 * Produtividade do time: funil Ligações → Follow-ups → Leads quentes → Contratos (contratos = contratos_historico, a mesma fonte do
 * ranking). Conversão = contratos ÷ leads únicos × 100 (leads únicos = números de cliente distintos com pelo menos 1 ação no período).
 * Média de ações por lead = ações ÷ leads únicos. Variação = ações do período contra o mesmo número de dias imediatamente antes.
 * Reproduz o get_produtividade do api.php e as funções cr_prod_* do closer-lib.php (o admin ainda usa as originais).
 */
final class ProdutividadeService
{
    /** Tipos de ação do portal do closer e o rótulo de cada um (CR_TIPOS do closer-lib.php). */
    public const TIPOS_DE_ACAO = ['ligacao' => 'Ligação', 'followup' => 'Follow-up', 'lead_quente' => 'Lead Quente'];

    public function __construct(
        private Calendario $calendario,
        private VendedorRepository $vendedores,
        private AcaoRepository $acoes,
        private ContratoRepository $contratos,
        private ContaCloserRepository $contas,
        private ImagemService $imagens
    ) {
    }

    /**
     * Período pedido (hoje, semana, mes ou custom com from/to) e o período imediatamente anterior, do mesmo tamanho.
     * Só conta até hoje: um período no futuro vem marcado como vazio.
     * @return array<string, mixed>
     */
    public function periodo(string $periodo, string $from = '', string $to = ''): array
    {
        $tz = Relogio::fuso();
        $hoje = Relogio::hoje();
        if ($periodo === 'semana') {
            $semana = $this->calendario->semanaAtual();
            $s = $semana['start'];
            $e = $semana['end'];
        } elseif ($periodo === 'mes') {
            $d = new DateTime($hoje, $tz);
            $s = $d->format('Y-m-01');
            $e = $d->format('Y-m-t');
        } elseif ($periodo === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $s = $from;
            $e = $to;
            if ($s > $e) {
                $t = $s;
                $s = $e;
                $e = $t;
            }
            if ((new DateTime($s, $tz))->diff(new DateTime($e, $tz))->days > 365) {
                $e = (new DateTime($s, $tz))->modify('+365 days')->format('Y-m-d');
            }
        } else {
            $periodo = 'hoje';
            $s = $e = $hoje;
        }
        $efetivo = min($e, $hoje);                              // só conta até hoje
        $vazio = $efetivo < $s;                                 // período inteiro no futuro
        if ($vazio) {
            $efetivo = $s;
        }
        $dias = (new DateTime($s, $tz))->diff(new DateTime($efetivo, $tz))->days + 1;
        $fimAnterior = (new DateTime($s, $tz))->modify('-1 day')->format('Y-m-d');
        $inicioAnterior = (new DateTime($fimAnterior, $tz))->modify('-' . ($dias - 1) . ' days')->format('Y-m-d');
        return [
            'periodo' => $periodo, 'start' => $s, 'end' => $e, 'eff_end' => $efetivo, 'dias' => $dias, 'vazio' => $vazio,
            'prev_start' => $inicioAnterior, 'prev_end' => $fimAnterior, 'hoje' => $hoje,
        ];
    }

    /**
     * O que a aba Produtividade mostra: KPIs, funil e o ranking de closers, do escopo pedido ($equipeId e $closerId 0 = todos).
     * @return array<string, mixed> o corpo do get_produtividade, sem o "success"
     */
    public function dados(string $periodo, string $from = '', string $to = '', int $equipeId = 0, int $closerId = 0): array
    {
        $rg = $this->periodo($periodo, $from, $to);
        $closers = $this->vendedores->paraProdutividade($equipeId, $closerId);
        $ids = array_map(static fn($c) => (int) $c['id'], $closers);
        $cur = $this->acoes->contagens($ids, $rg['start'], $rg['eff_end']);
        $prev = $this->acoes->contagens($ids, $rg['prev_start'], $rg['prev_end']);
        $ctr = $this->contratos->totaisPorVendedor($rg['start'], $rg['eff_end']);
        $ctrP = $this->contratos->totaisPorVendedor($rg['prev_start'], $rg['prev_end']);
        $lead = $this->acoes->leadsUnicos($ids, $rg['start'], $rg['eff_end']);           // leads únicos: total do escopo e por closer
        $leadP = $this->acoes->leadsUnicos($ids, $rg['prev_start'], $rg['prev_end']);
        $ativos = $this->contas->vendedoresComContaAtiva();                              // só quem tem conta ativa entra nos alertas
        $last = $this->acoes->ultimaAcaoPorVendedor();

        $tot = ['ligacoes' => 0, 'followups' => 0, 'leads_quentes' => 0, 'contratos' => 0];
        $totP = $tot;
        $rank = [];
        $participantes = 0;
        foreach ($closers as $c) {
            $id = (int) $c['id'];
            $a = $cur[$id];
            $p = $prev[$id];
            $lig = $a['ligacao'];
            $fu = $a['followup'];
            $lq = $a['lead_quente'];
            $ct = $ctr[$id] ?? 0;
            $leads = $lead['por_closer'][$id] ?? 0;
            $total = $lig + $fu + $lq;
            $totalP = $p['ligacao'] + $p['followup'] + $p['lead_quente'];
            $var = $totalP > 0 ? (int) round(($total - $totalP) / $totalP * 100) : null;
            $ferias = $c['suspended_at'] !== null;
            $temConta = isset($ativos[$id]);
            // KPIs/funil só somam quem PARTICIPA do portal (conta ativa ou ações no período/anterior): senão os contratos de quem nunca
            // registrou ação entrariam no numerador da conversão sem as ligações correspondentes no denominador.
            if ($temConta || $total > 0 || $totalP > 0) {
                $participantes++;
                foreach (['ligacoes' => $lig, 'followups' => $fu, 'leads_quentes' => $lq, 'contratos' => $ct] as $k => $v) {
                    $tot[$k] += $v;
                }
                $totP['ligacoes'] += $p['ligacao'];
                $totP['followups'] += $p['followup'];
                $totP['leads_quentes'] += $p['lead_quente'];
                $totP['contratos'] += $ctrP[$id] ?? 0;
            }
            $alertas = [];
            if ($temConta && !$ferias && !$rg['vazio']) {
                if ($total === 0) {
                    $alertas[] = ['k' => 'inativo', 't' => 'Sem atividade', 'd' => 'Nenhuma ação registrada no período'];
                } elseif ($totalP >= 10 && $var !== null && $var <= -30) {
                    $alertas[] = ['k' => 'queda', 't' => 'Queda de ' . abs($var) . '%', 'd' => 'Menos ações que no período anterior (' . $totalP . ' → ' . $total . ')'];
                }
                if ($lig >= 15 && $ct === 0) {
                    $alertas[] = ['k' => 'sem_contrato', 't' => 'Sem contratos', 'd' => $lig . ' ligações e nenhum contrato no período'];
                }
            }
            $rank[] = [
                'id' => $id, 'nome' => $c['nome'], 'equipe_id' => $c['equipe_id'] ? (int) $c['equipe_id'] : null, 'equipe_nome' => $c['equipe_nome'], 'equipe_cor' => $c['equipe_cor'],
                'cor' => $c['cor'], 'imageUrl' => $this->imagens->url($c['img_url']), 'ferias' => $ferias, 'tem_conta' => $temConta,
                'ligacoes' => $lig, 'followups' => $fu, 'leads_quentes' => $lq, 'contratos' => $ct, 'total' => $total,
                'leads' => $leads, 'conv' => $leads > 0 ? round($ct / $leads * 100, 1) : null, 'prev_total' => $totalP, 'variacao' => $var,
                'ultima_acao' => $last[$id] ?? null, 'alertas' => $alertas,
            ];
        }
        // ações = ligação + follow-up + lead quente. Conversão e média usam os LEADS ÚNICOS (números distintos), não as ações.
        $acoes = static fn(array $t): int => $t['ligacoes'] + $t['followups'] + $t['leads_quentes'];
        $conv = static fn(array $t, int $leads) => $leads > 0 ? round($t['contratos'] / $leads * 100, 1) : null;
        $media = static fn(array $t, int $leads) => $leads > 0 ? round($acoes($t) / $leads, 1) : null;
        $etapas = [['k' => 'ligacoes', 'l' => 'Ligações'], ['k' => 'followups', 'l' => 'Follow-ups'], ['k' => 'leads_quentes', 'l' => 'Leads quentes'], ['k' => 'contratos', 'l' => 'Contratos']];
        // só Contratos tem %: é a taxa de conversão (mesmo valor do cartão "Taxa de conversão")
        $funil = [];
        foreach ($etapas as $e) {
            $funil[] = ['k' => $e['k'], 'label' => $e['l'], 'qtd' => $tot[$e['k']], 'pct_leads' => $e['k'] === 'contratos' ? $conv($tot, $lead['total']) : null];
        }
        return [
            'range' => $rg,
            'kpis' => $tot + ['acoes' => $acoes($tot), 'leads_unicos' => $lead['total'], 'conversao' => $conv($tot, $lead['total']), 'media_acoes_lead' => $media($tot, $lead['total'])],
            'prev' => $totP + ['acoes' => $acoes($totP), 'leads_unicos' => $leadP['total'], 'conversao' => $conv($totP, $leadP['total']), 'media_acoes_lead' => $media($totP, $leadP['total'])],
            'funil' => $funil,
            'ranking' => $rank,
            'com_conta' => count(array_filter($rank, static fn($r) => $r['tem_conta'])),
            'participantes' => $participantes,
        ];
    }

    // ── Fase 5: detalhe de um closer e histórico de ações (admin) ───────────────────────────────────

    /**
     * Detalhe de um closer: evolução dia a dia e por semana configurada do mês (padrão = mês atual) e as últimas 12 ações (número mascarado).
     * Sem closer encontrado ou com mês inválido devolve ['success' => false, 'message' => ...]. Com $closerId 0 o filtro some e vale o primeiro
     * closer por nome (o api.php sempre se comportou assim). Porte fiel do get_produtividade_closer.
     * @param mixed $ano @param mixed $mes (null = o do dia de hoje)
     * @return array<string, mixed>
     */
    public function detalheDoCloser(int $closerId, $ano, $mes): array
    {
        $closers = $this->vendedores->paraProdutividade(0, $closerId);
        if (!$closers) {
            return ['success' => false, 'message' => 'Closer não encontrado'];
        }
        $hoje = Relogio::hoje();
        $ano = (int) ($ano ?? substr($hoje, 0, 4));
        $mes = (int) ($mes ?? substr($hoje, 5, 2));
        if ($ano < 2000 || $ano > 2100 || $mes < 1 || $mes > 12) {
            return ['success' => false, 'message' => 'Mês inválido'];
        }
        $evolucao = $this->evolucao($closerId, $ano, $mes);
        return [
            'success' => true, 'closer' => ['id' => $closerId, 'nome' => $closers[0]['nome']], 'ano' => $evolucao['ano'], 'mes' => $evolucao['mes'],
            'hoje' => $evolucao['hoje'], 'dias' => $evolucao['dias'], 'semanas' => $evolucao['semanas'],
            'ultimas' => array_map([$this, 'acaoParaTela'], $this->acoes->ultimas($closerId, 12)),
        ];
    }

    /**
     * O histórico completo de um closer (modal "Ver todas as ações"), paginado, com números mascarados. Filtros: tipo, from, to (AAAA-MM-DD).
     * @param array<string, mixed> $filtros tipo, from, to, limit e offset como vieram da requisição
     * @return array<string, mixed> o corpo da resposta, sem o "success"
     */
    public function historicoDeAcoes(int $closerId, array $filtros): array
    {
        $condicoes = ['vendedor_id = ?'];
        $parametros = [$closerId];
        $tipo = (string) ($filtros['tipo'] ?? '');
        if (isset(self::TIPOS_DE_ACAO[$tipo])) {
            $condicoes[] = 'tipo = ?';
            $parametros[] = $tipo;
        }
        $de = (string) ($filtros['from'] ?? '');
        $ate = (string) ($filtros['to'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
            $condicoes[] = 'registrado_em >= ?';
            $parametros[] = "$de 00:00:00";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
            $condicoes[] = 'registrado_em <= ?';
            $parametros[] = "$ate 23:59:59";
        }
        $limite = max(1, min(100, (int) ($filtros['limit'] ?? 40)));
        $deslocamento = max(0, (int) ($filtros['offset'] ?? 0));
        $r = $this->acoes->historico($condicoes, $parametros, $limite, $deslocamento);
        $linhas = $r['linhas'];
        $temMais = count($linhas) > $limite;
        if ($temMais) {
            array_pop($linhas);
        }
        return ['total' => $r['total'], 'has_more' => $temMais, 'acoes' => array_map([$this, 'acaoParaTela'], $linhas)];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function acaoParaTela(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'tipo' => $r['tipo'], 'tipo_label' => self::TIPOS_DE_ACAO[$r['tipo']] ?? $r['tipo'],
            'mascarado' => Telefone::mascarar((string) $r['telefone']), 'observacao' => $r['observacao'], 'registrado_em' => $r['registrado_em'],
        ];
    }

    /**
     * Evolução de UM closer num mês: dia a dia e por semana configurada (a mesma regra do Painel). Dias e semanas que ainda não chegaram vêm
     * com valores null; a semana em andamento conta só até hoje ('parcial'). Cada linha: ligacoes, followups, leads_quentes e contratos
     * (contratos = contratos_historico, a mesma fonte do ranking). Porte fiel de cr_prod_evolucao.
     * @return array<string, mixed>
     */
    private function evolucao(int $closerId, int $ano, int $mes): array
    {
        $tz = Relogio::fuso();
        $hoje = Relogio::hoje();
        $ini = sprintf('%04d-%02d-01', $ano, $mes);
        $fimMes = (new DateTime($ini, $tz))->format('Y-m-t');
        $semanas = $this->calendario->semanasDoMes($ano, $mes);
        // o que buscar: o mês inteiro + as semanas configuradas (uma semana pode passar do fim do mês), nunca depois de hoje
        $de = $ini;
        $ate = $fimMes;
        foreach ($semanas as $w) {
            if ($w['start'] < $de) {
                $de = $w['start'];
            }
            if ($w['end'] > $ate) {
                $ate = $w['end'];
            }
        }
        $ate = min($ate, $hoje);
        $mapa = [];
        if ($de <= $ate) {
            $mapa = $this->acoes->porDiaETipo($closerId, $de, $ate);
            if ($closerId > 0) {                                  // closer 0 (sem filtro) não tem contratos próprios: o api.php filtrava vendedor_id = 0
                foreach ($this->contratos->totaisPorDia($de, $ate, $closerId) as $data => $q) {
                    $mapa[$data]['contratos'] = $q;
                }
            }
        }
        $nulo = ['ligacoes' => null, 'followups' => null, 'leads_quentes' => null, 'contratos' => null];
        $doDia = static function (string $d) use ($mapa): array {
            $x = $mapa[$d] ?? [];
            return ['ligacoes' => $x['ligacao'] ?? 0, 'followups' => $x['followup'] ?? 0, 'leads_quentes' => $x['lead_quente'] ?? 0, 'contratos' => $x['contratos'] ?? 0];
        };
        $dias = [];
        for ($d = new DateTime($ini, $tz), $f = new DateTime($fimMes, $tz); $d <= $f; $d->modify('+1 day')) {
            $ds = $d->format('Y-m-d');
            $dias[] = ['data' => $ds] + ($ds <= $hoje ? $doDia($ds) : $nulo);
        }
        $saida = [];
        foreach ($semanas as $w) {
            $futura = $w['start'] > $hoje;
            $tot = $nulo;
            if (!$futura) {
                $tot = ['ligacoes' => 0, 'followups' => 0, 'leads_quentes' => 0, 'contratos' => 0];
                for ($d = new DateTime($w['start'], $tz), $f = new DateTime(min($w['end'], $hoje), $tz); $d <= $f; $d->modify('+1 day')) {
                    foreach ($doDia($d->format('Y-m-d')) as $k => $v) {
                        $tot[$k] += $v;
                    }
                }
            }
            $saida[] = ['num' => (int) $w['semana_num'], 'inicio' => $w['start'], 'fim' => $w['end'], 'futura' => $futura, 'parcial' => !$futura && $w['end'] > $hoje] + $tot;
        }
        return ['ano' => $ano, 'mes' => $mes, 'hoje' => $hoje, 'dias' => $dias, 'semanas' => $saida];
    }
}
