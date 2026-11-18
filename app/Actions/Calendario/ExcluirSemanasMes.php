<?php
declare(strict_types=1);

namespace App\Actions\Calendario;

use App\Core\Resultado;
use App\Repositories\SemanaRepository;

/** Caso de uso: apagar as semanas configuradas de um mês (volta a valer o cálculo automático). */
final class ExcluirSemanasMes
{
    public function __construct(private SemanaRepository $semanas)
    {
    }

    /** @param mixed $ano @param mixed $mes */
    public function executar($ano, $mes): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        if (!$ano || $mes < 1 || $mes > 12) {
            return Resultado::falha('Dados inválidos');
        }
        $this->semanas->excluirDoMes($ano, $mes);
        return Resultado::ok([], 'Configuração removida (será usado cálculo automático)');
    }
}
