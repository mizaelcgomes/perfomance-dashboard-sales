<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Middleware\Auth;
use App\Http\Middleware\AuthLider;
use App\Http\Middleware\ChaveTeste;
use App\Http\Middleware\Csrf as CsrfMiddleware;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\SincronizarFerias;
use App\Services\LiderEscopoService;
use PDO;
use PDOException;
use Throwable;

/**
 * Liga as peças: carrega a configuração, monta o contêiner e o Router, lê app/routes.php e responde à requisição.
 * O public/index.php só cria esta classe e chama executar(). A "ponte" do sistema atual (nucleo-ponte.php) usa
 * responderControlador() e renderizarView() para o api.php e o admin.php chamarem o código novo sem passar pelo Router.
 */
final class Aplicacao
{
    /** nome usado nas rotas => classe do middleware */
    private const MIDDLEWARES = [
        'auth' => Auth::class,
        'csrf' => CsrfMiddleware::class,
        'ratelimit' => RateLimit::class,
        'chave' => ChaveTeste::class,
        'ferias' => SincronizarFerias::class,
        'lider' => AuthLider::class,
    ];

    /**
     * @param string $urlPublica endereço (relativo à página) da pasta pública, para os CSS/JS das views servidas pela ponte: 'nova' no
     *                           servidor (a porta do núcleo), 'public' no ambiente local. Vazio quando o núcleo responde direto na raiz.
     */
    public function __construct(private string $raiz, private string $publico, private string $urlPublica = '')
    {
    }

    public function executar(): void
    {
        Config::carregar($this->raiz, $this->publico);
        error_reporting(E_ALL);
        ini_set('display_errors', Config::obter('app.debug', false) === true ? '1' : '0');

        $requisicao = Request::capturar();
        $this->responder($requisicao)->enviar($requisicao->metodo() === 'HEAD');
    }

    /** Separado de executar() para os testes poderem despachar uma Request montada à mão. */
    public function responder(Request $requisicao, ?PDO $pdo = null): Response
    {
        $container = $this->montarContainer($requisicao, $pdo);

        $router = new Router($container, self::MIDDLEWARES);
        $rotas = require $this->raiz . '/app/routes.php';
        $rotas($router);

        return $router->despachar($requisicao);
    }

    /**
     * Chama direto o método de um Controller, sem Router nem middleware (quem chama já fez a autenticação). Usado pela ponte do
     * api.php: as URLs antigas (api.php?action=...) passam a ser só uma porta para o mesmo código das rotas novas.
     * @param class-string $controller
     */
    public function responderControlador(string $controller, string $metodo, ?PDO $pdo = null): Response
    {
        Config::carregar($this->raiz, $this->publico);
        try {
            $requisicao = Request::capturar();
            $instancia = $this->montarContainer($requisicao, $pdo)->obter($controller);
            return Router::comoResponse($instancia->{$metodo}($requisicao, []));
        } catch (PDOException $erro) {
            // o api.php sempre respondeu assim a um erro de banco (HTTP 200, sem expor tabela nem coluna): as portas finas mantêm o contrato
            error_log(sprintf('[nucleo] PDOException: %s em %s:%d', $erro->getMessage(), basename($erro->getFile()), $erro->getLine()));
            return Response::json(['success' => false, 'message' => 'Ocorreu um erro ao processar a solicitação.']);
        } catch (Throwable $erro) {
            return Router::erroInterno($erro);
        }
    }

    /**
     * Como responderControlador, mas só para líderes: passa pelo middleware AuthLider (conta de closer ativa E liderança da equipe, conferidas
     * agora) e entrega ao método o equipe_id da liderança. Usado pelo closer-api.php (portal) pela ponte.
     * @param class-string $controller
     */
    public function responderLider(string $controller, string $metodo, ?PDO $pdo = null): Response
    {
        Config::carregar($this->raiz, $this->publico);
        try {
            $requisicao = Request::capturar();
            $container = $this->montarContainer($requisicao, $pdo);
            $instancia = $container->obter($controller);
            return $container->obter(AuthLider::class)->handle(
                $requisicao,
                static fn(Request $r): Response => Router::comoResponse($instancia->{$metodo}($r, $r->parametros())),
                []
            );
        } catch (Throwable $erro) {
            return Router::erroInterno($erro);
        }
    }

    /**
     * A equipe que a conta de closer lidera agora (ou null se não lidera). Usado pelo lider.php e pelo closer.php para decidir a página do
     * usuário; a regra é a mesma do middleware auth:lider (conta ativa, closer da equipe e líder cadastrado dela), conferida a cada chamada.
     */
    public function equipeDoLider(int $contaId, ?PDO $pdo = null): ?int
    {
        Config::carregar($this->raiz, $this->publico);
        $container = $this->montarContainer(Request::capturar(), $pdo);
        return $container->obter(LiderEscopoService::class)->equipeDoLider($contaId);
    }

    /**
     * O padrão de metas de um closer novo (a meta que a maioria já tem e, nos meses com metas por período, o padrão de cada mês). Usado pelo
     * aceite de acesso do api.php, que cria o closer na hora. Chamar ANTES de criar o closer.
     * @return array<string, mixed>
     */
    public function herancaDeMetas(?PDO $pdo = null): array
    {
        Config::carregar($this->raiz, $this->publico);
        return $this->montarContainer(Request::capturar(), $pdo)->obter(\App\Services\MetasService::class)->heranca();
    }

    /** Grava, para o closer recém-criado, as metas que o igualam ao padrão calculado em herancaDeMetas(). @param array<string, mixed> $heranca */
    public function aplicarHerancaDeMetas(int $closerId, array $heranca, ?PDO $pdo = null): void
    {
        Config::carregar($this->raiz, $this->publico);
        $this->montarContainer(Request::capturar(), $pdo)->obter(\App\Services\MetasService::class)->aplicarHeranca($closerId, $heranca);
    }

    /**
     * Um Service do núcleo, montado com a conexão $pdo do sistema atual. Usado pelas páginas do portal do closer (closer.php, lider.php, login,
     * cadastro e recuperação) para saber quem está logado e montar a tela, sem passar pelo Router.
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    public function servico(string $classe, ?PDO $pdo = null): object
    {
        Config::carregar($this->raiz, $this->publico);
        return $this->montarContainer(Request::capturar(), $pdo)->obter($classe);
    }

    /** Renderiza uma view do núcleo (app/Views/<nome>.php) e devolve o HTML. Usado pelo admin.php para incluir pedaços de tela. @param array<string, mixed> $dados */
    public function renderizarView(string $nome, array $dados = []): string
    {
        Config::carregar($this->raiz, $this->publico);
        return (new View($this->raiz . '/app/Views', $this->publico, $this->urlPublica))->parcial($nome, $dados);
    }

    private function montarContainer(Request $requisicao, ?PDO $pdo): Container
    {
        $container = new Container();
        $container->instancia(Request::class, $requisicao);
        $container->instancia(Container::class, $container);
        $container->instancia(Database::class, $pdo !== null ? Database::daPdo($pdo) : new Database());
        // pelo Router a base é a da requisição (ex.: /nova); pela ponte (api.php e index.php do site) é o endereço relativo da pasta pública
        $container->instancia(View::class, new View($this->raiz . '/app/Views', $this->publico, $pdo !== null ? $this->urlPublica : $requisicao->base()));
        return $container;
    }
}
