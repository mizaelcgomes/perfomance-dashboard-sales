<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Contrato dos middlewares: recebe a requisição, o "próximo" da fila e os argumentos da rota
 * ("auth:admin" => ['admin']). Pode barrar (devolvendo uma Response sem chamar $proximo) ou deixar passar.
 */
interface Middleware
{
    /**
     * @param callable(Request): Response $proximo
     * @param array<int, string> $argumentos
     */
    public function handle(Request $requisicao, callable $proximo, array $argumentos): Response;
}
