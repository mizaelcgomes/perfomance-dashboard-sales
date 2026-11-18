<?php
declare(strict_types=1);

namespace App\Actions\Contrato;

use App\Core\Resultado;
use App\Repositories\ConfiguracaoRepository;

/** Caso de uso: salvar a ordem dos critérios de desempate do ranking (lista de benefícios, de grupos e/ou "conv_rate"), em JSON. */
final class SalvarDesempate
{
    public function __construct(private ConfiguracaoRepository $config)
    {
    }

    /** @param mixed $ordem JSON com a lista de critérios, como vem do formulário */
    public function executar($ordem): Resultado
    {
        $lista = json_decode(is_string($ordem) ? $ordem : '[]', true);
        if (!is_array($lista)) {
            return Resultado::falha('Dados inválidos');
        }
        $this->config->definir('tiebreaker_order', (string) json_encode($lista, JSON_UNESCAPED_UNICODE));
        return Resultado::ok([], 'Critérios salvos');
    }
}
