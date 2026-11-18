<?php
declare(strict_types=1);

namespace App\Actions\Investimento;

use App\Core\Relogio;
use App\Core\Resultado;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\InvestimentoRepository;

/** Caso de uso: apagar o registro de investimento de um mês. No mês corrente o valor avulso "investimento_mes" volta a zero. */
final class ExcluirInvestimentoMensal
{
    public function __construct(private InvestimentoRepository $investimentos, private ConfiguracaoRepository $config)
    {
    }

    /** @param mixed $ano @param mixed $mes */
    public function executar($ano, $mes): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        if (!$ano || $mes < 1 || $mes > 12) {
            return Resultado::falha('Mês inválido');
        }
        $this->investimentos->excluirMes($ano, $mes);
        $agora = Relogio::agora();
        if ((int) $agora->format('Y') === $ano && (int) $agora->format('n') === $mes) {
            $this->config->definir('investimento_mes', '0');
        }
        return Resultado::ok([], 'Registro removido');
    }
}
