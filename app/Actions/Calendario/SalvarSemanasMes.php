<?php
declare(strict_types=1);

namespace App\Actions\Calendario;

use App\Core\Resultado;
use App\Repositories\SemanaRepository;

/**
 * Caso de uso: configurar as semanas de um mês (aba Calendário). Troca TODAS as semanas do mês pelas enviadas; semana sem datas, com data fora
 * do formato AAAA-MM-DD ou com número menor que 1 é ignorada. Sem semanas configuradas, vale o cálculo automático (quarta a terça).
 */
final class SalvarSemanasMes
{
    public function __construct(private SemanaRepository $semanas)
    {
    }

    /** @param mixed $ano @param mixed $mes @param mixed $semanasJson a lista em JSON, como o admin envia no campo "semanas" */
    public function executar($ano, $mes, $semanasJson): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        $lista = json_decode(is_string($semanasJson) ? $semanasJson : '[]', true);
        if (!$ano || $mes < 1 || $mes > 12 || !is_array($lista)) {
            return Resultado::falha('Dados inválidos');
        }
        $validas = [];
        foreach ($lista as $idx => $s) {
            $num = (int) ($s['semana_num'] ?? ($idx + 1));
            $ini = trim((string) ($s['data_inicio'] ?? ''));
            $fim = trim((string) ($s['data_fim'] ?? ''));
            if (!$ini || !$fim || $num < 1) {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) {
                continue;
            }
            $validas[] = ['semana_num' => $num, 'data_inicio' => $ini, 'data_fim' => $fim];
        }
        $this->semanas->substituirDoMes($ano, $mes, $validas);
        return Resultado::ok([], 'Semanas salvas para ' . sprintf('%02d/%d', $mes, $ano));
    }
}
