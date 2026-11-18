<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Resultado;
use App\Repositories\ConfiguracaoRepository;

/** Caso de uso: gravar a meta de CAC (o custo por contrato que a empresa quer alcançar), usada nos relatórios e no Painel. */
final class SalvarMetaCac
{
    public function __construct(private ConfiguracaoRepository $config)
    {
    }

    /** @param mixed $valor */
    public function executar($valor): Resultado
    {
        $v = max(0, (float) str_replace(',', '.', is_scalar($valor) ? (string) $valor : '0'));
        $this->config->definir('meta_cac', (string) $v);
        return Resultado::ok([], 'Meta de CAC salva');
    }
}
