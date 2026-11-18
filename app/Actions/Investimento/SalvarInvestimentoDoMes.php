<?php
declare(strict_types=1);

namespace App\Actions\Investimento;

use App\Core\Resultado;
use App\Repositories\ConfiguracaoRepository;

/** Caso de uso: gravar só o valor avulso do investimento do mês corrente ("investimento_mes"), que a TV e o Painel leem para o CAC. */
final class SalvarInvestimentoDoMes
{
    public function __construct(private ConfiguracaoRepository $config)
    {
    }

    /** @param mixed $valor */
    public function executar($valor): Resultado
    {
        $val = max(0, (float) (is_scalar($valor) ? $valor : 0));
        $this->config->definir('investimento_mes', (string) $val);
        return Resultado::ok([], 'Investimento salvo');
    }
}
