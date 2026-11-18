<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\ContratoRepository;
use App\Repositories\LiderPortalRepository;
use DateTimeImmutable;

/**
 * A aba Equipe do portal do líder: um cartão por closer (performance da semana, métricas dos últimos 30 dias, alertas) e o detalhe de um
 * closer (visão geral, produtividade e contratos da semana ou do mês, linha do tempo das interações e evolução diária dos últimos 30 dias). Só closers da equipe do líder: um id de outra equipe é "não
 * encontrado". O líder em si não aparece como closer da própria lista (ele gerencia, não é gerenciado).
 */
final class LiderEquipeService
{
    public const DIAS_METRICAS = 30;
    public const DIAS_LINHA_DO_TEMPO = 90;

    public function __construct(
        private LiderDashboardService $dashboard,
        private LiderPortalRepository $portal,
        private ProdutividadeService $produtividade,
        private ContratoRepository $contratos,
        private TiposBeneficioService $tipos
    ) {
    }

    /**
     * @return array{closers: array<int, array<string, mixed>>, dias: int}
     */
    public function equipe(int $equipeId, int $liderVendedorId): array
    {
        $base = $this->dashboard->base($equipeId, $liderVendedorId);
        $cartoes = [];
        $metricas = $this->metricas($equipeId);
        foreach ($base['closers'] as $c) {
            if ($c['lider']) {
                continue;
            }
            $cartoes[] = $this->cartao($c, $metricas[$c['id']] ?? $this->zeros(), $base);
        }
        return ['closers' => $cartoes, 'dias' => self::DIAS_METRICAS];
    }

    /**
     * O detalhe de um closer da equipe, ou null se ele não é da equipe (ou é o próprio líder). $periodo (semana ou mês, o corrente) vale para
     * a produtividade e os contratos; qualquer outro valor vira semana.
     * @return array<string, mixed>|null
     */
    public function closer(int $equipeId, int $liderVendedorId, int $closerId, string $periodo = 'semana'): ?array
    {
        $base = $this->dashboard->base($equipeId, $liderVendedorId);
        $alvo = null;
        foreach ($base['closers'] as $c) {
            if ($c['id'] === $closerId && !$c['lider']) {
                $alvo = $c;
            }
        }
        if ($alvo === null) {
            return null;
        }
        $agora = Relogio::agora();
        $metricas = $this->metricas($equipeId);
        return [
            'closer' => $this->cartao($alvo, $metricas[$closerId] ?? $this->zeros(), $base),
            'linha_do_tempo' => $this->linhaDoTempo($equipeId, $closerId, $agora),
            'evolucao' => $this->evolucao($closerId, $agora),
            'periodo' => $this->doPeriodo($equipeId, $closerId, $periodo === 'mes' ? 'mes' : 'semana'),
        ];
    }

    /**
     * Produtividade (ligações, follow-ups, leads quentes, conversão) e contratos do closer na semana ou no mês corrente, com o período anterior
     * do mesmo tamanho para comparar. Os números são os da aba Produtividade do admin filtrada nesse closer.
     * @return array<string, mixed>
     */
    private function doPeriodo(int $equipeId, int $closerId, string $periodo): array
    {
        $dados = $this->produtividade->dados($periodo, '', '', $equipeId, $closerId);
        $rg = $dados['range'];
        $linha = null;
        foreach ($dados['ranking'] as $r) {
            if ($r['id'] === $closerId) {
                $linha = $r;
            }
        }
        $total = $linha['contratos'] ?? 0;
        $anterior = $this->contratos->totaisPorVendedor($rg['prev_start'], $rg['prev_end'], $closerId)[$closerId] ?? 0;
        $porDia = $this->contratos->totaisPorDia($rg['start'], $rg['eff_end'], $closerId);
        $melhor = null;
        foreach ($porDia as $data => $qtd) {
            if ($qtd > 0 && ($melhor === null || $qtd > $melhor['qtd'])) {
                $melhor = ['data' => (string) $data, 'qtd' => $qtd];
            }
        }
        [$beneficios, $comTipo] = $this->beneficios($closerId, $rg['start'], $rg['eff_end']);
        return [
            'periodo' => $periodo,
            'inicio' => $rg['start'], 'fim' => $rg['end'], 'dias' => $rg['dias'],
            'produtividade' => $linha === null ? null : [
                'ligacoes' => $linha['ligacoes'], 'followups' => $linha['followups'], 'leads_quentes' => $linha['leads_quentes'], 'total' => $linha['total'],
                'leads' => $linha['leads'], 'conversao' => $linha['conv'], 'anterior' => $linha['prev_total'], 'variacao' => $linha['variacao'],
                'ultima_acao' => $linha['ultima_acao'], 'tem_conta' => $linha['tem_conta'],
            ],
            'contratos' => [
                'total' => $total, 'anterior' => $anterior, 'media_dia' => $rg['dias'] > 0 ? round($total / $rg['dias'], 1) : 0, 'melhor_dia' => $melhor,
                'beneficios' => $beneficios, 'sem_tipo' => max(0, $total - $comTipo),
            ],
        ];
    }

    /**
     * Os benefícios fechados no período, do maior para o menor ("Outros" com descrição vira um item por descrição, como nos gráficos do admin).
     * @return array{0: array<int, array<string, mixed>>, 1: int} a lista e quantos contratos têm tipo
     */
    private function beneficios(int $closerId, string $de, string $ate): array
    {
        $estilo = $this->tipos->estilo();
        $lista = [];
        $comTipo = 0;
        foreach (MixDeBeneficios::expandir($this->contratos->tiposComDescricao($de, $ate, $closerId)) as $chave => $qtd) {
            $chave = (string) $chave;
            $outros = strpos($chave, 'outros:') === 0;
            $e = $estilo[$outros ? 'outros' : $chave] ?? null;
            $lista[] = [
                'chave' => $chave, 'label' => $outros ? substr($chave, 7) : ($e['label'] ?? $chave), 'qtd' => $qtd,
                'cor' => $e['cor'] ?? '#d4af37', 'icone' => $e['icone'] ?? 'file',
            ];
            $comTipo += $qtd;
        }
        usort($lista, static fn(array $a, array $b): int => ($b['qtd'] <=> $a['qtd']) ?: strcmp($a['label'], $b['label']));
        return [$lista, $comTipo];
    }

    /**
     * @param array<string, mixed> $c
     * @param array<string, int> $m
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function cartao(array $c, array $m, array $base): array
    {
        $proprios = array_values(array_filter(
            array_merge($base['alertas'], $base['pendencias']),
            static fn(array $a): bool => ($a['closer_id'] ?? null) === $c['id']
        ));
        return $c + [
            'metricas' => $m,
            'alertas' => array_map(static fn(array $a): array => ['id' => $a['id'], 'severidade' => $a['severidade'] ?? 'atencao', 'titulo' => $a['titulo'], 'acao' => $a['acao']], $proprios),
        ];
    }

    /** @return array<int, array<string, int>> */
    private function metricas(int $equipeId): array
    {
        $agora = Relogio::agora();
        return $this->portal->contagens($equipeId, $agora->modify('-' . (self::DIAS_METRICAS - 1) . ' days')->format('Y-m-d'), $agora->format('Y-m-d H:i:s'));
    }

    /** @return array<string, int> */
    private function zeros(): array
    {
        return ['feedbacks' => 0, 'cobrancas' => 0, 'revisoes' => 0, 'reunioes' => 0, 'treinamentos' => 0];
    }

    /** @return array<int, array<string, mixed>> */
    private function linhaDoTempo(int $equipeId, int $closerId, DateTimeImmutable $agora): array
    {
        $linhas = $this->portal->linhaDoTempo($equipeId, $closerId, $agora->modify('-' . self::DIAS_LINHA_DO_TEMPO . ' days')->format('Y-m-d'), $agora->format('Y-m-d H:i:s'), 80);
        return array_map(static fn(array $r): array => [
            'tipo' => (string) $r['tipo'], 'id' => (int) $r['id'], 'quando' => (string) $r['quando'], 'titulo' => (string) $r['titulo'],
            'texto' => $r['texto'] === null ? null : (string) $r['texto'],
            'extra' => $r['extra'] === null ? null : (string) $r['extra'],
        ], $linhas);
    }

    /**
     * Contratos e ações por dia nos últimos 30 dias (todos os dias aparecem, com zero onde não houve nada).
     * @return array{datas: array<int, string>, contratos: array<int, int>, acoes: array<int, int>, total_contratos: int, total_acoes: int}
     */
    private function evolucao(int $closerId, DateTimeImmutable $agora): array
    {
        $ate = $agora->format('Y-m-d');
        $de = $agora->modify('-' . (self::DIAS_METRICAS - 1) . ' days')->format('Y-m-d');
        $dados = $this->portal->evolucaoDiaria($closerId, $de, $ate);
        $datas = [];
        $contratos = [];
        $acoes = [];
        for ($i = self::DIAS_METRICAS - 1; $i >= 0; $i--) {
            $d = $agora->modify("-$i days")->format('Y-m-d');
            $datas[] = $d;
            $contratos[] = $dados['contratos'][$d] ?? 0;
            $acoes[] = $dados['acoes'][$d] ?? 0;
        }
        return ['datas' => $datas, 'contratos' => $contratos, 'acoes' => $acoes, 'total_contratos' => array_sum($contratos), 'total_acoes' => array_sum($acoes)];
    }
}
