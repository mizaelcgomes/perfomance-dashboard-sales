<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ContaCloserRepository;
use App\Repositories\EquipeRepository;

/**
 * A visão do time do líder: produtividade, contratos, ranking e painel (semanal e mensal) de UMA equipe, e o resumo por equipe que o
 * admin vê na seção Líderes da Produtividade. Não calcula nada novo: usa os mesmos serviços do admin (ProdutividadeService,
 * RankingFiltradoService e PainelService) e só filtra pela equipe, então os números são os mesmos que o admin vê para essa equipe.
 */
final class LiderVisaoService
{
    public function __construct(
        private ProdutividadeService $produtividade,
        private RankingFiltradoService $rankings,
        private PainelService $painel,
        private EquipeRepository $equipes,
        private ContaCloserRepository $contas
    ) {
    }

    /**
     * Visão completa de uma equipe. O período vale para a produtividade e os contratos; o ranking e o painel são sempre da semana
     * e do mês correntes (como no admin).
     * @return array<string, mixed>
     */
    public function visao(int $equipeId, string $periodo, string $from = '', string $to = ''): array
    {
        $equipe = $this->equipes->comLider($equipeId);
        $prod = $this->produtividade->dados($periodo, $from, $to, $equipeId, 0);
        $painel = $this->painel->painel(0, 0, 0);

        $contratos = array_map(
            static fn(array $r): array => ['id' => $r['id'], 'nome' => $r['nome'], 'contratos' => $r['contratos']],
            $prod['ranking']
        );
        usort($contratos, static fn(array $a, array $b): int => ($b['contratos'] <=> $a['contratos']) ?: strcmp((string) $a['nome'], (string) $b['nome']));

        $semana = $painel['week'] ?? [];
        $mes = $painel['month'] ?? [];
        $rankingSemana = null;
        if (isset($semana['semana_num']) && $semana['semana_num'] !== null) {
            $rankingSemana = $this->doTime($this->rankings->consultar('semana', (string) $semana['semana_num'], 'closer', (int) $semana['ano'], (int) $semana['mes']), $equipeId);
        }
        $rankingMes = isset($mes['ano']) ? $this->doTime($this->rankings->consultar('mes', sprintf('%04d-%02d', $mes['ano'], $mes['mes']), 'closer'), $equipeId) : null;

        $time = null;
        foreach ($painel['teams'] ?? [] as $t) {
            if ((int) $t['id'] === $equipeId) {
                $time = $t;
            }
        }
        $closersPainel = array_values(array_filter($painel['closers'] ?? [], static fn(array $c): bool => $c['equipe_id'] === $equipeId));

        return [
            'equipe' => $equipe === null ? null : [
                'id' => (int) $equipe['id'], 'nome' => $equipe['nome'], 'cor' => $equipe['cor'],
                'lider_vendedor_id' => $equipe['lider_id'] === null ? null : (int) $equipe['lider_id'], 'lider_nome' => $equipe['lider_nome'],
            ],
            'produtividade' => $prod,
            'contratos' => ['total' => array_sum(array_column($contratos, 'contratos')), 'por_closer' => $contratos],
            'ranking' => ['semana' => $rankingSemana, 'mes' => $rankingMes],
            'painel' => ['week' => $semana, 'month' => $mes, 'equipe' => $time, 'closers' => $closersPainel],
        ];
    }

    /**
     * Uma linha por equipe para o admin: líder, funil do período (mesmos números da aba Produtividade com o filtro de equipe), contratos
     * e a pontuação da equipe na semana e no mês.
     * @return array<int, array<string, mixed>>
     */
    public function resumoPorEquipe(string $periodo, string $from = '', string $to = ''): array
    {
        $painel = $this->painel->painel(0, 0, 0);
        $porEquipe = [];
        foreach ($painel['teams'] ?? [] as $t) {
            $porEquipe[(int) $t['id']] = $t;
        }
        $comConta = $this->contas->vendedoresComContaAtiva();
        $saida = [];
        foreach ($this->equipes->comLideres() as $e) {
            $id = (int) $e['id'];
            $prod = $this->produtividade->dados($periodo, $from, $to, $id, 0);
            $ranking = $prod['ranking'];
            usort($ranking, static fn(array $a, array $b): int => ($b['contratos'] <=> $a['contratos']) ?: strcmp((string) $a['nome'], (string) $b['nome']));
            $saida[] = [
                'equipe_id' => $id, 'equipe_nome' => $e['nome'], 'equipe_cor' => $e['cor'],
                'lider_vendedor_id' => $e['lider_id'] === null ? null : (int) $e['lider_id'], 'lider_nome' => $e['lider_nome'],
                // o líder só enxerga a área do time se tiver conta ativa no portal: o admin vê aqui quando falta isso
                'lider_acesso' => $e['lider_nome'] === null ? null : isset($comConta[(int) $e['lider_id']]),
                'closers' => count($prod['ranking']),
                'kpis' => $prod['kpis'], 'prev' => $prod['prev'], 'funil' => $prod['funil'], 'participantes' => $prod['participantes'],
                'contratos_total' => array_sum(array_column($prod['ranking'], 'contratos')),
                'melhores' => array_map(static fn(array $r): array => ['id' => $r['id'], 'nome' => $r['nome'], 'contratos' => $r['contratos']], array_slice($ranking, 0, 3)),
                'painel' => isset($porEquipe[$id]) ? [
                    'vendas' => $porEquipe[$id]['vendas'], 'meta' => $porEquipe[$id]['meta'], 'score' => $porEquipe[$id]['score'],
                    'vendas_mes' => $porEquipe[$id]['vendas_mes'], 'meta_mes' => $porEquipe[$id]['meta_mes'], 'score_mes' => $porEquipe[$id]['score_mes'],
                ] : null,
            ];
        }
        return $saida;
    }

    /**
     * Fica só com os closers da equipe, na ordem do ranking, e numera de 1 em diante (a posição dentro do time).
     * @param array<string, mixed> $resposta a resposta do RankingFiltradoService
     * @return array<int, array<string, mixed>>
     */
    private function doTime(array $resposta, int $equipeId): array
    {
        $linhas = array_values(array_filter($resposta['data'] ?? [], static fn(array $r): bool => $r['equipe_id'] === $equipeId));
        foreach ($linhas as $i => &$r) {
            $r['pos'] = $i + 1;
        }
        unset($r);
        return $linhas;
    }
}
