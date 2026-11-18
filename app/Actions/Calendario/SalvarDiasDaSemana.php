<?php
declare(strict_types=1);

namespace App\Actions\Calendario;

use App\Core\Resultado;
use App\Repositories\ConfiguracaoRepository;
use App\Services\CalendarioService;

/** Caso de uso: definir quantos dias tem o ciclo da semana (1 a 14, padrão 7) e devolver os rótulos e os dias do mês de cada um. */
final class SalvarDiasDaSemana
{
    public function __construct(private ConfiguracaoRepository $config, private CalendarioService $calendario)
    {
    }

    /** @param mixed $dias */
    public function executar($dias): Resultado
    {
        $wd = max(1, min(14, (int) (is_scalar($dias) ? $dias : 7)));
        $this->config->definir('week_days', (string) $wd);
        $ciclo = $this->calendario->diasDoCiclo($wd);
        return Resultado::ok(['weekDays' => $wd, 'weekLabels' => $ciclo['labels'], 'weekDates' => $ciclo['datas']], 'Dias atualizados');
    }
}
