<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Semanas configuradas de cada mês (tabela semanas_mes, aba Calendário do admin). */
final class SemanaRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, array<string, mixed>> semana_num, data_inicio, data_fim (vazio = o mês não tem semanas configuradas) */
    public function doMes(int $ano, int $mes): array
    {
        return $this->banco->consultar(
            'SELECT semana_num, data_inicio, data_fim FROM semanas_mes WHERE ano=? AND mes=? ORDER BY semana_num',
            [$ano, $mes]
        );
    }

    /** As datas da semana configurada número $semana do mês, ou null. @return array<string, mixed>|null */
    public function daSemana(int $ano, int $mes, int $semana): ?array
    {
        return $this->banco->linha('SELECT data_inicio, data_fim FROM semanas_mes WHERE ano=? AND mes=? AND semana_num=? LIMIT 1', [$ano, $mes, $semana]);
    }

    /** Quantas semanas o admin configurou para o mês (0 = nenhuma). */
    public function contarDoMes(int $ano, int $mes): int
    {
        return (int) $this->banco->valor('SELECT COUNT(*) FROM semanas_mes WHERE ano=? AND mes=?', [$ano, $mes]);
    }

    /** A semana configurada de (ano, mes) que contém $data, ou null. @return array<string, mixed>|null */
    public function queContem(int $ano, int $mes, string $data): ?array
    {
        return $this->banco->linha(
            'SELECT data_inicio, data_fim FROM semanas_mes WHERE ano=? AND mes=? AND data_inicio<=? AND data_fim>=? ORDER BY semana_num LIMIT 1',
            [$ano, $mes, $data, $data]
        );
    }

    /** A semana configurada que termina em $data (a anterior à semana atual), ou null. @return array<string, mixed>|null */
    public function queTerminaEm(string $data): ?array
    {
        return $this->banco->linha('SELECT ano, mes, semana_num, data_inicio, data_fim FROM semanas_mes WHERE data_fim=? LIMIT 1', [$data]);
    }

    /** O número/ano/mês da semana configurada com exatamente estas datas, ou null. @return array<string, mixed>|null */
    public function porPeriodo(string $ini, string $fim): ?array
    {
        return $this->banco->linha('SELECT semana_num, ano, mes FROM semanas_mes WHERE data_inicio=? AND data_fim=? LIMIT 1', [$ini, $fim]);
    }

    /** Os meses que têm semanas configuradas (os 36 mais recentes), com o total de semanas e o primeiro e o último dia. @return array<int, array<string, mixed>> */
    public function historico(): array
    {
        return $this->banco->consultar(
            'SELECT ano, mes, COUNT(*) as total_semanas, MIN(data_inicio) as primeiro_dia, MAX(data_fim) as ultimo_dia FROM semanas_mes GROUP BY ano, mes ORDER BY ano DESC, mes DESC LIMIT 36'
        );
    }

    /**
     * Troca TODAS as semanas do mês pelas dadas (apaga e grava, numa transação só).
     * @param array<int, array{semana_num: int, data_inicio: string, data_fim: string}> $semanas
     */
    public function substituirDoMes(int $ano, int $mes, array $semanas): void
    {
        $this->banco->transacao(function () use ($ano, $mes, $semanas): void {
            $this->banco->executar('DELETE FROM semanas_mes WHERE ano=? AND mes=?', [$ano, $mes]);
            foreach ($semanas as $s) {
                $this->banco->executar(
                    'INSERT INTO semanas_mes (ano,mes,semana_num,data_inicio,data_fim) VALUES (?,?,?,?,?)',
                    [$ano, $mes, $s['semana_num'], $s['data_inicio'], $s['data_fim']]
                );
            }
        });
    }

    /** Apaga as semanas configuradas do mês (volta a valer o cálculo automático). */
    public function excluirDoMes(int $ano, int $mes): void
    {
        $this->banco->executar('DELETE FROM semanas_mes WHERE ano=? AND mes=?', [$ano, $mes]);
    }
}
