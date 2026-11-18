<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Metas por período (tabela metas_periodo): semana_num 0 = meta do mês, 1 em diante = meta daquela semana.
 * tipo 'closer' (ref_id = closer) ou 'geral' (meta coletiva da empresa, ref_id 0). Só as leituras dos relatórios (fase 2).
 */
final class MetaRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, array<int, int>> [closer_id][semana_num] => meta (semana_num 0 = mês) */
    public function doCloserNoMes(int $ano, int $mes): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT ref_id, semana_num, meta FROM metas_periodo WHERE tipo='closer' AND ano=? AND mes=?",
            [$ano, $mes]
        ) as $r) {
            $saida[(int) $r['ref_id']][(int) $r['semana_num']] = (int) $r['meta'];
        }
        return $saida;
    }

    /** @return array<int, int> [closer_id] => meta do mês (só a linha semana_num 0) */
    public function mensalDosClosers(int $ano, int $mes): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT ref_id, meta FROM metas_periodo WHERE tipo='closer' AND ano=? AND mes=? AND semana_num=0",
            [$ano, $mes]
        ) as $r) {
            $saida[(int) $r['ref_id']] = (int) $r['meta'];
        }
        return $saida;
    }

    /** @return array<int, array<int, int>> [equipe_id][semana_num] => meta (semana_num 0 = mês) */
    public function daEquipeNoMes(int $ano, int $mes): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT ref_id, semana_num, meta FROM metas_periodo WHERE tipo='equipe' AND ano=? AND mes=?",
            [$ano, $mes]
        ) as $r) {
            $saida[(int) $r['ref_id']][(int) $r['semana_num']] = (int) $r['meta'];
        }
        return $saida;
    }

    /** @return array<int, int> [closer_id] => meta da semana $semana do mês (só as linhas dessa semana) */
    public function doCloserNaSemana(int $ano, int $mes, int $semana): array
    {
        return $this->mapaPorRef('closer', $ano, $mes, $semana);
    }

    /** @return array<int, int> [equipe_id] => meta da semana $semana do mês */
    public function daEquipeNaSemana(int $ano, int $mes, int $semana): array
    {
        return $this->mapaPorRef('equipe', $ano, $mes, $semana);
    }

    /** @return array<int, int> [equipe_id] => meta do mês (só a linha semana_num 0) */
    public function mensalDasEquipes(int $ano, int $mes): array
    {
        return $this->mapaPorRef('equipe', $ano, $mes, 0);
    }

    /** @return array<int, array<int, int>> [ref_id][semana_num] => meta, do tipo 'closer' ou 'equipe' no mês (semana_num 0 = mês) */
    public function doTipoNoMes(string $tipo, int $ano, int $mes): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT ref_id, semana_num, meta FROM metas_periodo WHERE tipo=? AND ano=? AND mes=?', [$tipo, $ano, $mes]) as $r) {
            $saida[(int) $r['ref_id']][(int) $r['semana_num']] = (int) $r['meta'];
        }
        return $saida;
    }

    /** Meta coletiva (tipo 'geral') de uma semana (ou do mês, com semana 0) quando maior que zero; senão null. */
    public function geral(int $ano, int $mes, int $semana): ?int
    {
        $meta = $this->banco->valor(
            "SELECT meta FROM metas_periodo WHERE tipo='geral' AND ref_id=0 AND ano=? AND mes=? AND semana_num=?",
            [$ano, $mes, $semana]
        );
        return ($meta !== null && (int) $meta > 0) ? (int) $meta : null;
    }

    /**
     * Meta de cada DIA, por closer, para os meses dados (uma semana pode passar de um mês para o outro).
     * @param array<int, string> $anosMeses "AAAA-MM"
     * @return array<int, array<string, int>> [closer_id]["AAAA-MM-DD"] => meta do dia
     */
    public function diariasPorCloser(array $anosMeses): array
    {
        $ou = [];
        $parametros = [];
        foreach ($anosMeses as $ym) {
            [$ano, $mes] = explode('-', $ym);
            $ou[] = '(ano=? AND mes=?)';
            $parametros[] = (int) $ano;
            $parametros[] = (int) $mes;
        }
        $saida = [];
        if ($ou) {
            foreach ($this->banco->consultar('SELECT closer_id, ano, mes, dia, meta FROM metas_diarias_periodo WHERE ' . implode(' OR ', $ou), $parametros) as $r) {
                $saida[(int) $r['closer_id']][sprintf('%04d-%02d-%02d', $r['ano'], $r['mes'], $r['dia'])] = (int) $r['meta'];
            }
        }
        return $saida;
    }

    /** @return array<int, int> [ref_id] => meta, para um tipo, mês e semana (0 = meta do mês) */
    private function mapaPorRef(string $tipo, int $ano, int $mes, int $semana): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT ref_id, meta FROM metas_periodo WHERE tipo=? AND ano=? AND mes=? AND semana_num=?', [$tipo, $ano, $mes, $semana]) as $r) {
            $saida[(int) $r['ref_id']] = (int) $r['meta'];
        }
        return $saida;
    }

    /** Meta coletiva do mês cadastrada na aba Metas, ou null se não houver. */
    public function geralDoMes(int $ano, int $mes): ?int
    {
        $meta = $this->banco->valor(
            "SELECT meta FROM metas_periodo WHERE tipo='geral' AND ref_id=0 AND ano=? AND mes=? AND semana_num=0",
            [$ano, $mes]
        );
        return $meta === null ? null : (int) $meta;
    }

    // ── metas por período e metas diárias: leituras e escritas da aba "Metas & Regras" ──────────────────────────────

    /** Todas as metas por período do mês (tipo, ref_id, semana_num, meta). @return array<int, array<string, mixed>> */
    public function periodoDoMes(int $ano, int $mes): array
    {
        return $this->banco->consultar('SELECT tipo, ref_id, semana_num, meta FROM metas_periodo WHERE ano=? AND mes=?', [$ano, $mes]);
    }

    /** As metas diárias do mês (closer_id, dia, meta). @return array<int, array<string, mixed>> */
    public function diariasDoMes(int $ano, int $mes): array
    {
        return $this->banco->consultar('SELECT closer_id, dia, meta FROM metas_diarias_periodo WHERE ano=? AND mes=?', [$ano, $mes]);
    }

    /** Metas fixas (maiores que zero) de um tipo ('equipe' ou 'geral') no mês. @return array<int, array<int, int>> [ref_id][semana_num] => meta */
    public function fixasDoTipoNoMes(string $tipo, int $ano, int $mes): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT ref_id, semana_num, meta FROM metas_periodo WHERE tipo=? AND ano=? AND mes=? AND meta>0', [$tipo, $ano, $mes]) as $r) {
            $saida[(int) $r['ref_id']][(int) $r['semana_num']] = (int) $r['meta'];
        }
        return $saida;
    }

    /** As células (semana_num) com meta fixa maior que zero para um tipo e referência no mês. @return array<int, int> */
    public function semanasComMetaFixa(string $tipo, int $ref, int $ano, int $mes): array
    {
        return array_map('intval', array_column($this->banco->consultar('SELECT semana_num FROM metas_periodo WHERE tipo=? AND ref_id=? AND ano=? AND mes=? AND meta>0', [$tipo, $ref, $ano, $mes]), 'semana_num'));
    }

    public function salvarPeriodo(string $tipo, int $ref, int $ano, int $mes, int $semana, int $meta): void
    {
        $this->banco->executar(
            'INSERT INTO metas_periodo (tipo,ref_id,ano,mes,semana_num,meta) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE meta=VALUES(meta)',
            [$tipo, $ref, $ano, $mes, $semana, $meta]
        );
    }

    public function apagarPeriodo(string $tipo, int $ref, int $ano, int $mes, int $semana): void
    {
        $this->banco->executar('DELETE FROM metas_periodo WHERE tipo=? AND ref_id=? AND ano=? AND mes=? AND semana_num=?', [$tipo, $ref, $ano, $mes, $semana]);
    }

    /** Apaga todas as metas por período de uma referência (equipe ou geral) no mês. */
    public function apagarPeriodoDoRef(string $tipo, int $ref, int $ano, int $mes): void
    {
        $this->banco->executar('DELETE FROM metas_periodo WHERE tipo=? AND ref_id=? AND ano=? AND mes=?', [$tipo, $ref, $ano, $mes]);
    }

    public function salvarDiaria(int $closer, int $ano, int $mes, int $dia, int $meta): void
    {
        $this->banco->executar(
            'INSERT INTO metas_diarias_periodo (closer_id,ano,mes,dia,meta) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE meta=VALUES(meta)',
            [$closer, $ano, $mes, $dia, $meta]
        );
    }

    /** @return array<int, array<int, int>> [closer_id][dia] => meta do dia */
    public function diariasPorCloserNoMes(int $ano, int $mes): array
    {
        $saida = [];
        foreach ($this->diariasDoMes($ano, $mes) as $r) {
            $saida[(int) $r['closer_id']][(int) $r['dia']] = (int) $r['meta'];
        }
        return $saida;
    }

    /** Troca a série de metas diárias de um closer no mês (apaga e grava; série vazia só apaga). @param array<int, int> $serie [dia] => meta */
    public function substituirDiarias(int $closer, int $ano, int $mes, array $serie): void
    {
        $this->banco->executar('DELETE FROM metas_diarias_periodo WHERE closer_id=? AND ano=? AND mes=?', [$closer, $ano, $mes]);
        foreach ($serie as $dia => $meta) {
            $this->banco->executar('INSERT INTO metas_diarias_periodo (closer_id,ano,mes,dia,meta) VALUES (?,?,?,?,?)', [$closer, $ano, $mes, (int) $dia, (int) $meta]);
        }
    }

    /** Os closers não desligados, com o que a aba de metas precisa (meta base, equipe e férias). @return array<int, array<string, mixed>> */
    public function closersParaMetas(): array
    {
        return $this->banco->consultar(
            'SELECT v.id, v.nome, v.metas, v.meta_mensal, v.equipe_id, v.suspended_at, e.nome AS equipe_nome
               FROM vendedores v LEFT JOIN equipes e ON e.id = v.equipe_id WHERE v.deleted_at IS NULL ORDER BY v.nome'
        );
    }

    /** As equipes por nome, com a meta fixa antiga do cadastro. @return array<int, array<string, mixed>> */
    public function equipesParaMetas(): array
    {
        return $this->banco->consultar('SELECT id, nome, cor, meta_semanal, meta_mensal FROM equipes ORDER BY nome');
    }

    /** A meta fixa antiga do cadastro da equipe (equipes.meta_semanal e meta_mensal), ou null se a equipe não existe. @return array<string, mixed>|null */
    public function metaAntigaDaEquipe(int $equipe): ?array
    {
        return $this->banco->linha('SELECT meta_semanal, meta_mensal FROM equipes WHERE id=?', [$equipe]);
    }

    public function zerarMetaAntigaDaEquipe(int $equipe): void
    {
        $this->banco->executar('UPDATE equipes SET meta_semanal=0, meta_mensal=0 WHERE id=?', [$equipe]);
    }

    public function equipeExiste(int $equipe): bool
    {
        return $this->banco->linha('SELECT 1 FROM equipes WHERE id=?', [$equipe]) !== null;
    }

    public function closerExiste(int $closer): bool
    {
        return $this->banco->linha('SELECT 1 FROM vendedores WHERE id=? AND deleted_at IS NULL', [$closer]) !== null;
    }

    /** Roda $acao numa transação (desfaz tudo se lançar). @return mixed */
    public function emTransacao(callable $acao)
    {
        return $this->banco->transacao($acao);
    }

    // ── Fase 5: o closer novo nasce no padrão ───────────────────────────────────────────────────────

    /** Metas base (semanal e mensal) dos closers ativos, para achar o padrão de um closer novo. @return array<int, array<string, mixed>> */
    public function metasBaseDosAtivos(): array
    {
        return $this->banco->consultar('SELECT metas, meta_mensal FROM vendedores WHERE deleted_at IS NULL');
    }

    /** Meses DEPOIS de (ano, mes) que já têm metas por período de closer, em ordem. @return array<int, array{0: int, 1: int}> */
    public function mesesComMetaDeCloserDepoisDe(int $ano, int $mes): array
    {
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT DISTINCT ano, mes FROM metas_periodo WHERE tipo='closer' AND (ano > ? OR (ano = ? AND mes > ?)) ORDER BY ano, mes",
            [$ano, $ano, $mes]
        ) as $r) {
            $saida[] = [(int) $r['ano'], (int) $r['mes']];
        }
        return $saida;
    }
}
