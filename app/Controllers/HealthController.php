<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * TEMPORÁRIO (fase 1). /health?k=<chave> mostra se o núcleo está de pé no servidor: versão do PHP, extensões,
 * banco, pasta de gravação, e onde ele está montado (/nova). Não devolve caminhos de disco, usuário, senha nem host.
 */
final class HealthController
{
    public function __construct(private Database $banco)
    {
    }

    /** @param array<string, string> $parametros */
    public function status(Request $requisicao, array $parametros = []): Response
    {
        $banco = $this->banco->testar();
        $storage = Config::storage();

        return Response::json([
            'success' => true,
            'status' => $banco['ok'] ? 'ok' : 'degradado',
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'montado_em' => $requisicao->base() === '' ? '/' : $requisicao->base(),
            'caminho' => $requisicao->caminho(),
            'https' => $requisicao->https(),
            'banco' => $banco,
            'storage_gravavel' => is_dir($storage) && is_writable($storage),
            'extensoes' => [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'json' => extension_loaded('json'),
                'gd' => extension_loaded('gd'),
                'mbstring' => extension_loaded('mbstring'),
            ],
            'fuso_padrao' => date_default_timezone_get(),
            'hora' => date('c'),
        ]);
    }
}
