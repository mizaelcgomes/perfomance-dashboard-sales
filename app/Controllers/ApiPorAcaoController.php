<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Http\Acoes;
use App\Http\Middleware\AuthLider;
use App\Services\AutenticacaoAdminService;
use App\Services\AutenticacaoCloserService;
use App\Services\FeriasService;
use PDOException;
use Throwable;

/**
 * Porta única da API por "action", que o JavaScript do admin, da TV e dos portais chama (era o api.php e o closer-api.php, fase 9a).
 *   POST|GET /api?action=...         admin e TV (mapa Acoes::ADMIN)    — respostas e códigos HTTP do api.php de antes
 *   POST     /api/closer {action...} portal do closer e do líder        — respostas e códigos HTTP do closer-api.php de antes
 * A ação só escolhe o Controller; o trabalho continua nos Controllers e Services de sempre.
 */
final class ApiPorAcaoController
{
    public function __construct(
        private Container $container,
        private AutenticacaoAdminService $admin,
        private AutenticacaoCloserService $closer,
        private FeriasService $ferias
    ) {
    }

    /** @param array<string, string> $p */
    public function admin(Request $r, array $p = []): Response
    {
        $acao = (string) $r->entrada('action', '');
        if ($r->metodo() === 'POST' && $acao !== 'login' && $this->admin->logado() && !Csrf::valido($r)) {
            return Response::json(['success' => false, 'code' => 'csrf', 'message' => 'A sessão do painel foi renovada. Recarregue a página (F5) e tente de novo.'], 419);
        }
        try {
            $this->ferias->sincronizar();
            if ($acao === 'login') {
                return Response::json($this->admin->entrar(trim((string) $r->corpo('user', '')), (string) $r->corpo('pass', ''), $r->ip()));
            }
            if ($acao === 'get_csrf') {
                return $this->admin->logado()
                    ? Response::json(['success' => true, 'token' => $this->admin->tokenCsrf()])
                    : $this->naoAutenticado();
            }
            if (!isset(Acoes::ADMIN[$acao])) {
                return Response::json(['success' => false, 'message' => 'Ação inválida: ' . $acao]);
            }
            [$classe, $metodo, $exigeLogin] = Acoes::ADMIN[$acao];
            if ($exigeLogin && !$this->admin->logado()) {
                return $this->naoAutenticado();
            }
            return Router::comoResponse($this->container->obter($classe)->{$metodo}($r, []));
        } catch (PDOException $e) {
            error_log('api ['.$acao.'] PDOException: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Ocorreu um erro ao processar a solicitação.']);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
        }
    }

    /** @param array<string, string> $p */
    public function closer(Request $r, array $p = []): Response
    {
        $falha = static fn(string $msg, int $codigo, array $extra = []): Response => Response::json(['success' => false, 'message' => $msg] + $extra, $codigo);
        if ($r->metodo() !== 'POST') {
            return $falha('Método não permitido', 405);
        }
        if (!Csrf::validoCloser($r)) {
            return $falha('Sua sessão expirou. Recarregue a página e tente de novo.', 403, ['code' => 'csrf']);
        }
        if ($r->corpo() === []) {
            return $falha('Requisição inválida', 400);
        }
        $acao = (string) $r->corpo('action', '');
        try {
            if (isset(Acoes::CLOSER_PUBLICAS[$acao])) {
                return Router::comoResponse($this->container->obter(AutenticacaoCloserController::class)->{Acoes::CLOSER_PUBLICAS[$acao]}($r, []));
            }
            if (!isset(Acoes::CLOSER[$acao]) && !isset(Acoes::LIDER[$acao])) {
                return $falha('Ação desconhecida', 400);
            }
            if ($this->closer->contaLogada() === null) {
                return $falha('Sua sessão expirou. Entre novamente.', 401, ['code' => 'auth']);
            }
            if (isset(Acoes::CLOSER[$acao])) {
                [$classe, $metodo] = Acoes::CLOSER[$acao];
                return Router::comoResponse($this->container->obter($classe)->{$metodo}($r, []));
            }
            [$classe, $metodo] = Acoes::LIDER[$acao];
            $instancia = $this->container->obter($classe);
            return $this->container->obter(AuthLider::class)->handle(
                $r,
                static fn(Request $q): Response => Router::comoResponse($instancia->{$metodo}($q, $q->parametros())),
                []
            );
        } catch (Throwable $e) {
            error_log('api closer: ' . $e->getMessage());
            return $falha('Erro interno. Tente novamente em instantes.', 500);
        }
    }

    private function naoAutenticado(): Response
    {
        return Response::json(['success' => false, 'message' => 'Não autenticado']);
    }
}
