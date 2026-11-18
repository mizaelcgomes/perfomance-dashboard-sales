<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\LiderEscopoService;

/**
 * "lider": exige login de closer (sessão do portal) E liderança de uma equipe, conferidas a cada requisição.
 *  - sem login: página => redireciona para o login do closer; API => 401 JSON "Não autenticado" (como o auth:closer);
 *  - logado mas não é líder de equipe ativa: 403 JSON, sem dizer de qual equipe;
 *  - líder: segue, com o id da equipe em $parametros['equipe_id'] (o controller nunca recebe a equipe da requisição).
 */
final class AuthLider implements Middleware
{
    public function __construct(private LiderEscopoService $lideres)
    {
    }

    public function handle(Request $requisicao, callable $proximo, array $argumentos): Response
    {
        $contaId = (int) Session::obter('closer_id', 0);
        if ($contaId <= 0) {
            if ($requisicao->metodo() === 'GET' && $requisicao->aceitaHtml()) {
                return Response::redirecionar((string) Config::obter('app.login_closer', '/closer/login'));
            }
            return Response::json(['success' => false, 'message' => 'Não autenticado'], 401);
        }

        $equipe = $this->lideres->equipeDoLider($contaId);
        if ($equipe === null) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito a líderes de equipe.'], 403);
        }

        return $proximo($requisicao->comParametros(array_merge($requisicao->parametros(), ['equipe_id' => (string) $equipe])));
    }
}
