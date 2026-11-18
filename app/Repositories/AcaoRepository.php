<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Ações que os closers registram no portal (tabela acoes_closer): ligação, follow-up e lead quente. As leituras da produtividade e do histórico de um closer (admin). */
final class AcaoRepository
{
    public function __construct(private Database $banco)
    {
    }

    /**
     * Ações por closer e tipo no intervalo de datas (inclusive). Todo closer pedido aparece, com zero se não tiver ação.
     * @param array<int, int> $ids
     * @return array<int, array{ligacao: int, followup: int, lead_quente: int}>
     */
    public function contagens(array $ids, string $ini, string $fim): array
    {
        $saida = [];
        foreach ($ids as $id) {
            $saida[(int) $id] = ['ligacao' => 0, 'followup' => 0, 'lead_quente' => 0];
        }
        if (!$ids) {
            return $saida;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $linhas = $this->banco->consultar(
            "SELECT vendedor_id, tipo, COUNT(*) n FROM acoes_closer WHERE vendedor_id IN ($in) AND registrado_em >= ? AND registrado_em <= ? GROUP BY vendedor_id, tipo",
            array_merge(array_map('intval', $ids), ["$ini 00:00:00", "$fim 23:59:59"])
        );
        foreach ($linhas as $r) {
            $saida[(int) $r['vendedor_id']][$r['tipo']] = (int) $r['n'];
        }
        return $saida;
    }

    /**
     * Leads únicos = números de cliente distintos com pelo menos 1 ação no intervalo: total do grupo e por closer. Um número conta uma vez,
     * mesmo com várias ações. É o denominador da taxa de conversão.
     * @param array<int, int> $ids
     * @return array{total: int, por_closer: array<int, int>}
     */
    public function leadsUnicos(array $ids, string $ini, string $fim): array
    {
        $saida = ['total' => 0, 'por_closer' => []];
        if (!$ids) {
            return $saida;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $parametros = array_merge(array_map('intval', $ids), ["$ini 00:00:00", "$fim 23:59:59"]);
        foreach ($this->banco->consultar(
            "SELECT vendedor_id, COUNT(DISTINCT telefone) n FROM acoes_closer WHERE vendedor_id IN ($in) AND registrado_em >= ? AND registrado_em <= ? GROUP BY vendedor_id",
            $parametros
        ) as $r) {
            $saida['por_closer'][(int) $r['vendedor_id']] = (int) $r['n'];
        }
        $saida['total'] = (int) $this->banco->valor(
            "SELECT COUNT(DISTINCT telefone) FROM acoes_closer WHERE vendedor_id IN ($in) AND registrado_em >= ? AND registrado_em <= ?",
            $parametros
        );
        return $saida;
    }

    /** @return array<int, string> [vendedor_id] => data e hora da última ação registrada */
    public function ultimaAcaoPorVendedor(): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT vendedor_id, MAX(registrado_em) m FROM acoes_closer GROUP BY vendedor_id') as $r) {
            $saida[(int) $r['vendedor_id']] = (string) $r['m'];
        }
        return $saida;
    }

    // ── Fase 5: detalhe de um closer na Produtividade do admin ──────────────────────────────────────

    /** Ações de um closer por dia e tipo entre duas datas: [data][tipo] => quantidade. @return array<string, array<string, int>> */
    public function porDiaETipo(int $vendedorId, string $de, string $ate): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            'SELECT DATE(registrado_em) d, tipo, COUNT(*) n FROM acoes_closer WHERE vendedor_id=? AND registrado_em >= ? AND registrado_em <= ? GROUP BY DATE(registrado_em), tipo',
            [$vendedorId, "$de 00:00:00", "$ate 23:59:59"]
        ) as $r) {
            $saida[$r['d']][$r['tipo']] = (int) $r['n'];
        }
        return $saida;
    }

    /** As últimas ações de um closer (a mais nova primeiro). @return array<int, array<string, mixed>> */
    public function ultimas(int $vendedorId, int $limite): array
    {
        return $this->banco->consultar(
            'SELECT id, tipo, telefone, observacao, registrado_em FROM acoes_closer WHERE vendedor_id=? ORDER BY registrado_em DESC, id DESC LIMIT ' . $limite,
            [$vendedorId]
        );
    }

    /**
     * O histórico de um closer, paginado, com filtros opcionais. $filtros é montado só com colunas do código; os valores vão como parâmetros.
     * Devolve o total (sem a página) e as linhas da página (uma a mais que $limite, para o "tem mais").
     * @param array<int, string> $condicoes
     * @param array<int, mixed> $parametros
     * @return array{total: int, linhas: array<int, array<string, mixed>>}
     */
    public function historico(array $condicoes, array $parametros, int $limite, int $deslocamento): array
    {
        $onde = implode(' AND ', $condicoes);
        $total = (int) $this->banco->valor("SELECT COUNT(*) FROM acoes_closer WHERE $onde", $parametros);
        $linhas = $this->banco->consultar(
            "SELECT id, tipo, telefone, observacao, registrado_em FROM acoes_closer WHERE $onde ORDER BY registrado_em DESC, id DESC LIMIT " . ($limite + 1) . " OFFSET $deslocamento",
            $parametros
        );
        return ['total' => $total, 'linhas' => $linhas];
    }

    // ── portal do closer: a evolução com a média da equipe ──────────────────────────────────────────

    /** Total de ações (todos os tipos) por dia dos closers dados, entre duas datas. @param array<int, int> $ids @return array<string, int> [data => total] */
    public function totaisPorDia(array $ids, string $ini, string $fim): array
    {
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT DATE(registrado_em) d, COUNT(*) n FROM acoes_closer WHERE vendedor_id IN ($marcas) AND registrado_em >= ? AND registrado_em <= ? GROUP BY DATE(registrado_em)",
            array_merge(array_map('intval', $ids), ["$ini 00:00:00", "$fim 23:59:59"])
        ) as $r) {
            $saida[$r['d']] = (int) $r['n'];
        }
        return $saida;
    }

    // ── Fase 6: as ações do PRÓPRIO closer no portal (ele só lê e altera as dele) ─────────────────────

    /** Registra uma ação e devolve o id. */
    public function criar(int $vendedorId, string $tipo, string $telefone, ?string $observacao, string $registradoEm): int
    {
        $this->banco->executar(
            'INSERT INTO acoes_closer (vendedor_id, tipo, telefone, observacao, registrado_em) VALUES (?,?,?,?,?)',
            [$vendedorId, $tipo, $telefone, $observacao, $registradoEm]
        );
        return (int) $this->banco->idInserido();
    }

    /** Uma ação, só se for do closer. @return array<string, mixed>|null */
    public function doCloser(int $id, int $vendedorId): ?array
    {
        return $this->banco->linha('SELECT * FROM acoes_closer WHERE id=? AND vendedor_id=?', [$id, $vendedorId]);
    }

    public function atualizar(int $id, int $vendedorId, string $tipo, string $telefone, ?string $observacao, string $registradoEm): void
    {
        $this->banco->executar(
            'UPDATE acoes_closer SET tipo=?, telefone=?, observacao=?, registrado_em=? WHERE id=? AND vendedor_id=?',
            [$tipo, $telefone, $observacao, $registradoEm, $id, $vendedorId]
        );
    }

    /** Apaga a ação do closer e devolve quantas linhas apagou (0 = não era dele ou não existe). */
    public function excluir(int $id, int $vendedorId): int
    {
        return $this->banco->executar('DELETE FROM acoes_closer WHERE id=? AND vendedor_id=?', [$id, $vendedorId]);
    }
}
