<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Csrf as TokenCsrf;
use App\Core\Request;
use App\Core\Response;

/**
 * Exige o token CSRF em toda escrita (POST, PUT, PATCH, DELETE). Leituras passam direto.
 * Sem token válido: 419, e o Controller nem chega a rodar.
 */
final class Csrf implements Middleware
{
    private const SEGURAS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $requisicao, callable $proximo, array $argumentos): Response
    {
        $closer = ($argumentos[0] ?? 'admin') === 'closer';     // csrf:closer = o token do portal do closer (cr_csrf)
        $valido = $closer ? TokenCsrf::validoCloser($requisicao) : TokenCsrf::valido($requisicao);
        if (in_array($requisicao->metodo(), self::SEGURAS, true) || $valido) {
            return $proximo($requisicao);
        }
        if ($closer) {
            return Response::json(['success' => false, 'code' => 'csrf', 'message' => 'Sua sessão expirou. Recarregue a página e tente de novo.'], 419);
        }
        return Response::json([
            'success' => false,
            'code' => 'csrf',
            'message' => 'A sessão do painel foi renovada. Recarregue a página (F5) e tente de novo.',
        ], 419);
    }
}
