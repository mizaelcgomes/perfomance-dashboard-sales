<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ContaCloserRepository;

/**
 * Exige login: "auth:admin" (sessão do painel) ou "auth:closer" (sessão do portal).
 * Usa as MESMAS chaves de sessão do sistema atual (admin_logged, closer_id), então o login antigo vale aqui.
 * Sem login: navegador pedindo página => redireciona para a tela de login; chamada de API => 401 JSON.
 *
 * O "closer" só confere a sessão (e a equipe oculta da TV); quem decide se a CONTA serve é o Controller (conta pendente = 403). O "lembrar de mim" e o
 * login em si são do AutenticacaoCloserService: o closer-api.php resolve o cookie ANTES de chamar o núcleo e deixa a sessão aberta.
 */
final class Auth implements Middleware
{
    public function __construct(private ContaCloserRepository $contas)
    {
    }

    public function handle(Request $requisicao, callable $proximo, array $argumentos): Response
    {
        $papel = $argumentos[0] ?? 'admin';

        $contaId = (int) Session::obter('closer_id', 0);
        $logado = $papel === 'closer'
            ? $contaId > 0 && !$this->contas->pertenceAEquipeOculta($contaId)   // equipe oculta da TV: sem portal
            : Session::obter('admin_logged') === true;

        if ($logado) {
            return $proximo($requisicao);
        }

        if ($requisicao->metodo() === 'GET' && $requisicao->aceitaHtml()) {
            $destino = $papel === 'closer'
                ? (string) Config::obter('app.login_closer', '/closer/login')
                : (string) Config::obter('app.login_admin', '/login');
            return Response::redirecionar($destino);
        }
        return Response::json(['success' => false, 'message' => 'Não autenticado'], 401);
    }
}
