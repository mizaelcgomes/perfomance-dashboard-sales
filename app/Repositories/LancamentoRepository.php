<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Os lançamentos de contratos: o total do dia por closer (contratos_historico, a fonte única do ranking), o espelho legado por dia da semana
 * (contratos), o detalhe por benefício (contratos_tipos) e o diário de lançamentos (contratos_feed). As leituras de relatório ficam no
 * ContratoRepository; aqui estão as gravações da aba Contratos do admin e as leituras que ela usa (matriz e feed).
 * As consultas com FOR UPDATE só funcionam dentro de uma transação (quem chama abre).
 */
final class LancamentoRepository
{
    public function __construct(private Database $banco)
    {
    }

    // ── total do dia e espelho legado ───────────────────────────────────────────────────────────

    public function gravarTotalDoDia(int $vendedorId, string $data, int $quantidade): void
    {
        $this->banco->executar(
            'INSERT INTO contratos_historico (vendedor_id,data_registro,quantidade) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantidade=VALUES(quantidade)',
            [$vendedorId, $data, $quantidade]
        );
    }

    public function gravarDiaDaSemana(int $vendedorId, int $dia, int $quantidade): void
    {
        $this->banco->executar(
            'INSERT INTO contratos (vendedor_id,dia_semana,quantidade) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantidade=VALUES(quantidade)',
            [$vendedorId, $dia, $quantidade]
        );
    }

    /** Soma dos contratos do closer entre duas datas (inclusive). */
    public function somaDoPeriodo(int $vendedorId, string $ini, string $fim): int
    {
        return (int) $this->banco->valor('SELECT SUM(quantidade) FROM contratos_historico WHERE vendedor_id=? AND data_registro BETWEEN ? AND ? AND ' . VendedorRepository::sqlSemOcultos('vendedor_id'), [$vendedorId, $ini, $fim]);
    }

    /** Total do dia do closer (0 se não há linha); $travar = FOR UPDATE (dentro de transação). */
    public function totalDoDia(int $vendedorId, string $data, bool $travar = false): int
    {
        return (int) ($this->banco->valor('SELECT quantidade FROM contratos_historico WHERE vendedor_id=? AND data_registro=?' . ($travar ? ' FOR UPDATE' : ''), [$vendedorId, $data]) ?: 0);
    }

    // ── detalhe por benefício ───────────────────────────────────────────────────────────────────

    /** O primeiro lançamento (id, quantidade, tipo_descricao) de um benefício no dia, travado para alteração. @return array<string, mixed>|null */
    public function linhaDoTipoTravada(int $vendedorId, string $data, string $tipo): ?array
    {
        return $this->banco->linha(
            'SELECT id, quantidade, tipo_descricao FROM contratos_tipos WHERE vendedor_id=? AND data_registro=? AND tipo=? ORDER BY id LIMIT 1 FOR UPDATE',
            [$vendedorId, $data, $tipo]
        );
    }

    public function apagarLinhaDeTipo(int $id): void
    {
        $this->banco->executar('DELETE FROM contratos_tipos WHERE id=?', [$id]);
    }

    public function atualizarLinhaDeTipo(int $id, int $quantidade, string $descricao): void
    {
        $this->banco->executar('UPDATE contratos_tipos SET quantidade=?, tipo_descricao=? WHERE id=?', [$quantidade, $descricao, $id]);
    }

    public function inserirTipo(int $vendedorId, string $data, string $tipo, string $descricao, int $quantidade): void
    {
        $this->banco->executar(
            'INSERT INTO contratos_tipos (vendedor_id,data_registro,tipo,tipo_descricao,quantidade) VALUES (?,?,?,?,?)',
            [$vendedorId, $data, $tipo, $descricao, $quantidade]
        );
    }

    /** Apaga os benefícios ATIVOS do dia (os de tipo desativado ou removido ficam intactos). @param array<int, string> $ativos */
    public function apagarTiposAtivos(int $vendedorId, string $data, array $ativos): void
    {
        $marcas = implode(',', array_fill(0, count($ativos), '?'));
        $this->banco->executar("DELETE FROM contratos_tipos WHERE vendedor_id=? AND data_registro=? AND tipo IN ($marcas)", array_merge([$vendedorId, $data], $ativos));
    }

    public function somaDosTiposDoDia(int $vendedorId, string $data): int
    {
        return (int) $this->banco->valor('SELECT COALESCE(SUM(quantidade),0) FROM contratos_tipos WHERE vendedor_id=? AND data_registro=?', [$vendedorId, $data]);
    }

    /** @return array<int, array<string, mixed>> tipo, tipo_descricao e quantidade do dia */
    public function tiposDoDia(int $vendedorId, string $data): array
    {
        return $this->banco->consultar('SELECT tipo,tipo_descricao,quantidade FROM contratos_tipos WHERE vendedor_id=? AND data_registro=? AND ' . VendedorRepository::sqlSemOcultos('vendedor_id'), [$vendedorId, $data]);
    }

    // ── matriz de auditoria ─────────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> data (d), tipo e soma (q) por dia e benefício; $vendedorId 0 = todos */
    public function celulasDoPeriodo(string $ini, string $fim, int $vendedorId): array
    {
        $filtro = $vendedorId ? ' AND vendedor_id=?' : '';
        return $this->banco->consultar(
            "SELECT data_registro d, tipo, SUM(quantidade) q FROM contratos_tipos WHERE data_registro BETWEEN ? AND ?$filtro GROUP BY data_registro, tipo",
            $vendedorId ? [$ini, $fim, $vendedorId] : [$ini, $fim]
        );
    }

    /** @return array<int, array<string, mixed>> a descrição do "Outros" de cada dia (d, tipo_descricao) */
    public function descricoesDeOutros(int $vendedorId, string $ini, string $fim): array
    {
        return $this->banco->consultar(
            "SELECT data_registro d, tipo_descricao FROM contratos_tipos WHERE vendedor_id=? AND tipo='outros' AND data_registro BETWEEN ? AND ?",
            [$vendedorId, $ini, $fim]
        );
    }

    /** @return array<int, array<string, mixed>> total do histórico por dia (d, q); $vendedorId 0 = todos */
    public function totaisDoHistoricoPorDia(string $ini, string $fim, int $vendedorId): array
    {
        $filtro = $vendedorId ? ' AND vendedor_id=?' : '';
        return $this->banco->consultar(
            "SELECT data_registro d, SUM(quantidade) q FROM contratos_historico WHERE data_registro BETWEEN ? AND ?$filtro GROUP BY data_registro",
            $vendedorId ? [$ini, $fim, $vendedorId] : [$ini, $fim]
        );
    }

    public function totalDoHistoricoNaData(string $data): int
    {
        return (int) $this->banco->valor('SELECT COALESCE(SUM(quantidade),0) FROM contratos_historico WHERE data_registro=? AND ' . VendedorRepository::sqlSemOcultos('vendedor_id'), [$data]);
    }

    // ── feed (diário de lançamentos) ────────────────────────────────────────────────────────────

    /** Detalhe por benefício agregado: [vendedor|tipo => [d, q]] do dia (de um closer, ou de todos com $vendedorId null). @return array<string, array{d: string, q: int}> */
    public function agregadoDeTipos(string $data, ?int $vendedorId): array
    {
        $filtro = $vendedorId ? ' AND vendedor_id=?' : '';
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT vendedor_id, tipo, MAX(tipo_descricao) d, SUM(quantidade) q FROM contratos_tipos WHERE data_registro=?$filtro GROUP BY vendedor_id, tipo",
            $vendedorId ? [$data, $vendedorId] : [$data]
        ) as $r) {
            $saida[$r['vendedor_id'] . '|' . $r['tipo']] = ['d' => (string) $r['d'], 'q' => (int) $r['q']];
        }
        return $saida;
    }

    /** O mesmo agregado, mas do feed: [vendedor|tipo => quantidade]. @return array<string, int> */
    public function agregadoDoFeed(string $data, ?int $vendedorId): array
    {
        $filtro = $vendedorId ? ' AND vendedor_id=?' : '';
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT vendedor_id, tipo, SUM(quantidade) q FROM contratos_feed WHERE data_registro=?$filtro GROUP BY vendedor_id, tipo",
            $vendedorId ? [$data, $vendedorId] : [$data]
        ) as $r) {
            $saida[$r['vendedor_id'] . '|' . $r['tipo']] = (int) $r['q'];
        }
        return $saida;
    }

    public function somaDoFeed(int $vendedorId, string $data, string $tipo): int
    {
        return (int) $this->banco->valor('SELECT COALESCE(SUM(quantidade),0) FROM contratos_feed WHERE vendedor_id=? AND data_registro=? AND tipo=?', [$vendedorId, $data, $tipo]);
    }

    public function apagarFeedDoTipo(int $vendedorId, string $data, string $tipo): void
    {
        $this->banco->executar('DELETE FROM contratos_feed WHERE vendedor_id=? AND data_registro=? AND tipo=?', [$vendedorId, $data, $tipo]);
    }

    /** Grava um lançamento do feed e devolve o id. */
    public function inserirNoFeed(int $vendedorId, string $data, string $tipo, string $descricao, int $quantidade, string $origem, string $criadoEm): int
    {
        $this->banco->executar(
            'INSERT INTO contratos_feed (vendedor_id,data_registro,tipo,tipo_descricao,quantidade,origem,criado_em) VALUES (?,?,?,?,?,?,?)',
            [$vendedorId, $data, $tipo, $descricao, $quantidade, $origem, $criadoEm]
        );
        return (int) $this->banco->idInserido();
    }

    /** @return array<string, mixed>|null */
    public function lancamento(int $id): ?array
    {
        return $this->banco->linha('SELECT * FROM contratos_feed WHERE id=?', [$id]);
    }

    public function atualizarLancamento(int $id, int $vendedorId, string $data, string $tipo, string $descricao, int $quantidade, string $origem, string $criadoEm): void
    {
        $this->banco->executar(
            'UPDATE contratos_feed SET vendedor_id=?, data_registro=?, tipo=?, tipo_descricao=?, quantidade=?, origem=?, criado_em=? WHERE id=?',
            [$vendedorId, $data, $tipo, $descricao, $quantidade, $origem, $criadoEm, $id]
        );
    }

    public function excluirLancamento(int $id): void
    {
        $this->banco->executar('DELETE FROM contratos_feed WHERE id=?', [$id]);
    }

    /** Os lançamentos do dia com closer e equipe, do mais novo para o mais antigo. @return array<int, array<string, mixed>> */
    public function lancamentosDoDia(string $data): array
    {
        return $this->banco->consultar(
            'SELECT f.id, f.vendedor_id, v.nome AS closer, v.deleted_at, e.nome AS equipe, f.tipo, f.tipo_descricao, f.quantidade, f.origem, f.criado_em
               FROM contratos_feed f
               LEFT JOIN vendedores v ON v.id = f.vendedor_id
               LEFT JOIN equipes e ON e.id = v.equipe_id
              WHERE f.data_registro = ? AND ' . VendedorRepository::sqlSemOcultos('f.vendedor_id') . '
              ORDER BY f.criado_em DESC, f.id DESC',
            [$data]
        );
    }
}
