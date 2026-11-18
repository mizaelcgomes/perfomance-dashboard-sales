<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * TEMPORÁRIO. Protege as rotas de teste do núcleo (/health e /_teste/*): só responde com ?k=<chave> igual a
 * app.chave_teste (config/app.php). Sem a chave (ou com chave errada) a resposta é idêntica à de uma rota que
 * não existe, para não revelar que ela está lá. Sai junto com as rotas de teste (app.rotas_teste = false).
 */
final class ChaveTeste implements Middleware
{
    public function handle(Request $requisicao, callable $proximo, array $argumentos): Response
    {
        $esperada = (string) Config::obter('app.chave_teste', '');
        $enviada = $requisicao->query('k', '');
        if ($esperada === '' || !is_string($enviada) || !hash_equals($esperada, $enviada)) {
            return Router::naoEncontrada();
        }
        return $proximo($requisicao);
    }
}
