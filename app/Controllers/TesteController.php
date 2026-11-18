<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * TEMPORÁRIO (fase 1). Rotas que só existem para provar o núcleo: middlewares (auth, csrf, ratelimit), leitura
 * de entrada (query, formulário, JSON), parâmetros de rota e views. Todas ficam atrás da chave de teste
 * (?k=) e somem quando app.rotas_teste for false. Nenhuma lê ou grava dados do sistema.
 */
final class TesteController
{
    public function __construct(private View $view)
    {
    }

    /** @param array<string, string> $p */
    public function csrf(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'token_valido' => true]);
    }

    /** @param array<string, string> $p */
    public function admin(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'usuario' => (string) Session::obter('admin_user', '')]);
    }

    /** @param array<string, string> $p */
    public function limite(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true]);
    }

    /** @param array<string, string> $p */
    public function parametro(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'id' => $p['id'] ?? null, 'tipo' => gettype($p['id'] ?? null)]);
    }

    /** Devolve o que o núcleo entendeu da requisição (sem cookies nem cabeçalhos sensíveis). @param array<string, string> $p */
    public function eco(Request $r, array $p = []): Response
    {
        return Response::json([
            'success' => true,
            'metodo' => $r->metodo(),
            'caminho' => $r->caminho(),
            'base' => $r->base(),
            'query' => $r->query(),
            'corpo' => $r->corpo(),
            'entrada_x' => $r->entrada('x'),
            'json' => strpos((string) $r->cabecalho('content-type', ''), 'application/json') !== false,
        ]);
    }

    /** Renderiza layout + view + parcial, com um texto perigoso na entrada para provar o escape. @param array<string, string> $p */
    public function visao(Request $r, array $p = []): Response
    {
        $html = $this->view->renderizar('teste/visao', [
            'titulo' => 'Teste de view',
            'nome' => (string) $r->query('nome', 'mundo'),
        ]);
        return Response::html($html);
    }
}
