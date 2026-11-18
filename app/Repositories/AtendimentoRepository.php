<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Atendimentos diários dos closers (tabela atendimentos): a base da taxa de conversão. Só as leituras da TV e da Retrospectiva. */
final class AtendimentoRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, int> [vendedor_id] => atendimentos no intervalo (datas inclusive) */
    public function totaisPorVendedor(string $ini, string $fim): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT vendedor_id, SUM(quantidade) AS total FROM atendimentos WHERE data_registro BETWEEN ? AND ? GROUP BY vendedor_id', [$ini, $fim]) as $r) {
            $saida[(int) $r['vendedor_id']] = (int) $r['total'];
        }
        return $saida;
    }

    /** @return array<int, int> [vendedor_id] => atendimentos no mês "AAAA-MM" */
    public function totaisPorVendedorNoMes(string $anoMes): array
    {
        $saida = [];
        foreach ($this->banco->consultar("SELECT vendedor_id, SUM(quantidade) AS total FROM atendimentos WHERE DATE_FORMAT(data_registro,'%Y-%m')=? GROUP BY vendedor_id", [$anoMes]) as $r) {
            $saida[(int) $r['vendedor_id']] = (int) $r['total'];
        }
        return $saida;
    }

    // ── Fase 5: lançamento manual de atendimentos (base da taxa de conversão) ──────────────────────

    /** Atendimentos de um closer entre duas datas: [data => quantidade]. @return array<string, int> */
    public function doVendedorNoPeriodo(int $vendedorId, string $ini, string $fim): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT data_registro, quantidade FROM atendimentos WHERE vendedor_id=? AND data_registro BETWEEN ? AND ?', [$vendedorId, $ini, $fim]) as $r) {
            $saida[$r['data_registro']] = (int) $r['quantidade'];
        }
        return $saida;
    }

    /** Grava o dia; quantidade 0 apaga a linha. */
    public function salvarDia(int $vendedorId, string $data, int $quantidade): void
    {
        if ($quantidade === 0) {
            $this->banco->executar('DELETE FROM atendimentos WHERE vendedor_id=? AND data_registro=?', [$vendedorId, $data]);
            return;
        }
        $this->banco->executar(
            'INSERT INTO atendimentos (vendedor_id,data_registro,quantidade) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantidade=VALUES(quantidade)',
            [$vendedorId, $data, $quantidade]
        );
    }
}
