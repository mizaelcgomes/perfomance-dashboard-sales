<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Contratos fechados (contratos_historico: quantidade por closer e dia) e o detalhe por benefício (contratos_tipos).
 * Os contratos de closers de equipe oculta da TV (equipes.oculta_tv = 1) ficam de FORA de todas as leituras: só o total da equipe no
 * ranking da TV os soma, e isso é pedido com $incluirOcultos = true. Todos os intervalos de data são fechados ($ini e $fim inclusos)
 * e chegam como "Y-m-d".
 */
final class ContratoRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** Condição extra das consultas: sem os closers de equipe oculta da TV. */
    private function semOcultos(string $coluna = 'vendedor_id'): string
    {
        return ' AND ' . VendedorRepository::sqlSemOcultos($coluna);
    }

    /** @return array<int, array<string, int>> [vendedor_id][data] => contratos do dia */
    public function porVendedorEDia(string $ini, string $fim, bool $incluirOcultos = false): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            'SELECT vendedor_id, data_registro, SUM(quantidade) AS qty FROM contratos_historico WHERE data_registro BETWEEN ? AND ?'
                . ($incluirOcultos ? '' : $this->semOcultos()) . ' GROUP BY vendedor_id, data_registro',
            [$ini, $fim]
        ) as $r) {
            $saida[(int) $r['vendedor_id']][$r['data_registro']] = (int) $r['qty'];
        }
        return $saida;
    }

    /** @return array<int, int> [vendedor_id] => contratos no intervalo (datas inclusive); $vendedorId filtra um closer só */
    public function totaisPorVendedor(string $ini, string $fim, ?int $vendedorId = null): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            'SELECT vendedor_id, SUM(quantidade) AS total FROM contratos_historico WHERE data_registro BETWEEN ? AND ?' . ($vendedorId ? ' AND vendedor_id=?' : '') . $this->semOcultos() . ' GROUP BY vendedor_id',
            $vendedorId ? [$ini, $fim, $vendedorId] : [$ini, $fim]
        ) as $r) {
            $saida[(int) $r['vendedor_id']] = (int) $r['total'];
        }
        return $saida;
    }

    /** @return array<int, array<string, array<string, int>>> [vendedor_id][data][tipo] => contratos */
    public function tiposPorVendedorEDia(string $ini, string $fim): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            'SELECT vendedor_id, data_registro, tipo, SUM(quantidade) AS qty FROM contratos_tipos WHERE data_registro BETWEEN ? AND ?' . $this->semOcultos() . ' GROUP BY vendedor_id, data_registro, tipo',
            [$ini, $fim]
        ) as $r) {
            $saida[(int) $r['vendedor_id']][$r['data_registro']][$r['tipo']] = (int) $r['qty'];
        }
        return $saida;
    }

    /** @return array<string, int> [data] => contratos de todos os closers no dia (ou só de um, com $vendedorId) */
    public function totaisPorDia(string $ini, string $fim, ?int $vendedorId = null): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            'SELECT data_registro, SUM(quantidade) AS q FROM contratos_historico WHERE data_registro BETWEEN ? AND ?' . ($vendedorId ? ' AND vendedor_id=?' : '') . $this->semOcultos() . ' GROUP BY data_registro',
            $vendedorId ? [$ini, $fim, $vendedorId] : [$ini, $fim]
        ) as $r) {
            $saida[$r['data_registro']] = (int) $r['q'];
        }
        return $saida;
    }

    /**
     * Linha por linha, sem somar (como a "Visão do Time — Mês" lê): se houver duas linhas para o mesmo closer e dia, vale a última.
     * @return array<int, array<int, int>> [vendedor_id][dia do mês] => contratos
     */
    public function quantidadePorVendedorEDiaDoMes(string $ini, string $fim): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            'SELECT vendedor_id, data_registro, quantidade FROM contratos_historico WHERE data_registro BETWEEN ? AND ?' . $this->semOcultos() . ' ORDER BY vendedor_id, data_registro',
            [$ini, $fim]
        ) as $r) {
            $saida[(int) $r['vendedor_id']][(int) substr((string) $r['data_registro'], 8, 2)] = (int) $r['quantidade'];
        }
        return $saida;
    }

    /**
     * @return array<int, array<string, int>> [vendedor_id][tipo] => contratos no período
     */
    public function tiposPorVendedor(string $ini, string $fim, bool $incluirOcultos = false): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            'SELECT vendedor_id, tipo, SUM(quantidade) AS total FROM contratos_tipos WHERE data_registro BETWEEN ? AND ?'
                . ($incluirOcultos ? '' : $this->semOcultos()) . ' GROUP BY vendedor_id, tipo',
            [$ini, $fim]
        ) as $r) {
            $saida[(int) $r['vendedor_id']][$r['tipo']] = (int) $r['total'];
        }
        return $saida;
    }

    /** @return array<int, int> [vendedor_id] => contratos no mês "AAAA-MM" */
    public function totaisPorVendedorNoMes(string $anoMes, bool $incluirOcultos = false): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT vendedor_id, SUM(quantidade) AS total_mes FROM contratos_historico WHERE DATE_FORMAT(data_registro,'%Y-%m')=?"
                . ($incluirOcultos ? '' : $this->semOcultos()) . ' GROUP BY vendedor_id',
            [$anoMes]
        ) as $r) {
            $saida[(int) $r['vendedor_id']] = (int) $r['total_mes'];
        }
        return $saida;
    }

    /** @return array<int, array<string, int>> [vendedor_id][tipo] => contratos no mês "AAAA-MM" */
    public function tiposPorVendedorNoMes(string $anoMes, bool $incluirOcultos = false): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT vendedor_id, tipo, SUM(quantidade) AS total FROM contratos_tipos WHERE DATE_FORMAT(data_registro,'%Y-%m')=?"
                . ($incluirOcultos ? '' : $this->semOcultos()) . ' GROUP BY vendedor_id, tipo',
            [$anoMes]
        ) as $r) {
            $saida[(int) $r['vendedor_id']][$r['tipo']] = (int) $r['total'];
        }
        return $saida;
    }

    /** Total de contratos do mês "AAAA-MM": de quem disputa (inclusive ex-closers), ou de todas as equipes com $incluirOcultos. */
    public function totalDoMes(string $anoMes, bool $incluirOcultos = false): int
    {
        return (int) $this->banco->valor("SELECT COALESCE(SUM(quantidade),0) FROM contratos_historico WHERE DATE_FORMAT(data_registro,'%Y-%m')=?" . ($incluirOcultos ? '' : $this->semOcultos()), [$anoMes]);
    }

    /** O primeiro dia com contrato lançado de um closer ("Y-m-d"), ou null: limite da navegação por mês do portal. */
    public function primeiroDia(int $vendedorId): ?string
    {
        $dia = $this->banco->valor('SELECT MIN(data_registro) FROM contratos_historico WHERE vendedor_id=?' . $this->semOcultos(), [$vendedorId]);
        return $dia === null || $dia === false ? null : (string) $dia;
    }

    /** @return array<int, string> [vendedor_id] => último dia (até $ate) com contrato fechado */
    public function ultimoDiaComVenda(string $ate): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT vendedor_id, MAX(data_registro) AS d FROM contratos_historico WHERE quantidade > 0 AND data_registro <= ?' . $this->semOcultos() . ' GROUP BY vendedor_id', [$ate]) as $r) {
            $saida[(int) $r['vendedor_id']] = $r['d'];
        }
        return $saida;
    }

    /**
     * Linhas (vendedor_id, data_registro, total) por closer e dia, em ordem de data: a base do gráfico da semana.
     * @return array<int, array<string, mixed>>
     */
    public function linhasPorVendedorEDia(string $ini, string $fim, ?int $vendedorId = null): array
    {
        return $this->banco->consultar(
            'SELECT vendedor_id, data_registro, SUM(quantidade) AS total FROM contratos_historico WHERE data_registro BETWEEN ? AND ?' . ($vendedorId ? ' AND vendedor_id=?' : '') . $this->semOcultos() . ' GROUP BY vendedor_id, data_registro ORDER BY data_registro',
            $vendedorId ? [$ini, $fim, $vendedorId] : [$ini, $fim]
        );
    }

    /**
     * Benefícios do período com a descrição do "outros" (tipo, tipo_descricao, total): os gráficos expandem o "outros" pela descrição.
     * @return array<int, array<string, mixed>>
     */
    public function tiposComDescricao(string $ini, string $fim, ?int $vendedorId = null): array
    {
        return $this->banco->consultar(
            'SELECT tipo, tipo_descricao, SUM(quantidade) AS total FROM contratos_tipos WHERE data_registro BETWEEN ? AND ?' . ($vendedorId ? ' AND vendedor_id=?' : '') . $this->semOcultos() . ' GROUP BY tipo, tipo_descricao',
            $vendedorId ? [$ini, $fim, $vendedorId] : [$ini, $fim]
        );
    }

    /** @return array<int, int> [mês 1..12] => contratos no ano (de todos os closers ou só de um) */
    public function totaisPorMesDoAno(int $ano, ?int $vendedorId = null): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            'SELECT MONTH(data_registro) AS m, SUM(quantidade) AS total FROM contratos_historico WHERE YEAR(data_registro)=?' . ($vendedorId ? ' AND vendedor_id=?' : '') . $this->semOcultos() . ' GROUP BY m ORDER BY m',
            $vendedorId ? [$ano, $vendedorId] : [$ano]
        ) as $r) {
            $saida[(int) $r['m']] = (int) $r['total'];
        }
        return $saida;
    }

    /** Benefícios do ano com a descrição do "outros". @return array<int, array<string, mixed>> */
    public function tiposComDescricaoDoAno(int $ano, ?int $vendedorId = null): array
    {
        return $this->banco->consultar(
            'SELECT tipo, tipo_descricao, SUM(quantidade) AS total FROM contratos_tipos WHERE YEAR(data_registro)=?' . ($vendedorId ? ' AND vendedor_id=?' : '') . $this->semOcultos() . ' GROUP BY tipo, tipo_descricao',
            $vendedorId ? [$ano, $vendedorId] : [$ano]
        );
    }

    /**
     * Benefícios do período por equipe do closer (0 = sem equipe), com a descrição do "outros": o mix de benefícios do Painel.
     * @return array<int, array<int, array<string, mixed>>> [equipe_id][] => linhas (eq, tipo, tipo_descricao, total)
     */
    public function tiposPorEquipe(string $ini, string $fim): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            'SELECT COALESCE(v.equipe_id,0) AS eq, ct.tipo, ct.tipo_descricao, SUM(ct.quantidade) AS total
             FROM contratos_tipos ct LEFT JOIN vendedores v ON v.id = ct.vendedor_id
             WHERE ct.data_registro BETWEEN ? AND ? AND ' . VendedorRepository::sqlSemOcultos('ct.vendedor_id') . ' GROUP BY eq, ct.tipo, ct.tipo_descricao',
            [$ini, $fim]
        ) as $r) {
            $saida[(int) $r['eq']][] = $r;
        }
        return $saida;
    }

    /** @return array<string, int> "AAAA-MM" => contratos do mês (todos os meses que têm contrato lançado) */
    public function totaisPorMes(): array
    {
        $saida = [];
        foreach ($this->banco->consultar("SELECT DATE_FORMAT(data_registro,'%Y-%m') AS ym, SUM(quantidade) AS total FROM contratos_historico WHERE 1" . $this->semOcultos() . ' GROUP BY ym') as $r) {
            $saida[(string) $r['ym']] = (int) $r['total'];
        }
        return $saida;
    }
}
