<?php
declare(strict_types=1);

/**
 * Testes de unidade do núcleo MVC (fase 1), em PHP puro, sem servidor HTTP.
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\nucleo\unit.php
 * Precisa do MySQL de teste no ar (os testes do Database fazem consultas reais; usam só tabela TEMPORÁRIA).
 * Código de saída: 0 tudo certo, 1 alguma falha.
 */

use App\Controllers\HealthController;
use App\Core\Aplicacao;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Resultado;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Http\Middleware\Auth;
use App\Http\Middleware\ChaveTeste;
use App\Http\Middleware\Csrf as CsrfMiddleware;
use App\Http\Middleware\Middleware;
use App\Http\Middleware\RateLimit;
use App\Repositories\ContaCloserRepository;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
$PUBLICO = $RAIZ . '/public';
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $PUBLICO);

require __DIR__ . '/harness.php';

// ── classes de apoio dos testes ─────────────────────────────────────────────────────
final class Marca implements Middleware
{
    public static array $log = [];
    public function handle(Request $r, callable $proximo, array $a): Response
    {
        self::$log[] = 'antes:' . ($a[0] ?? '');
        $resp = $proximo($r);
        self::$log[] = 'depois:' . ($a[0] ?? '');
        return $resp;
    }
}
final class Barra implements Middleware
{
    public function handle(Request $r, callable $proximo, array $a): Response
    {
        return Response::json(['success' => false, 'barrado' => true], 401);
    }
}
final class CtrlTeste
{
    public static array $log = [];
    public function listar(Request $r, array $p = []): Response
    {
        self::$log[] = 'listar';
        return Response::json(['ok' => true, 'id' => $p['id'] ?? null]);
    }
    public function quebra(Request $r, array $p = []): Response
    {
        throw new RuntimeException('segredo interno ' . '/var/www/privado');
    }
    public function array(Request $r, array $p = []): array
    {
        return ['a' => 1];
    }
    public function texto(Request $r, array $p = []): string
    {
        return '<p>oi</p>';
    }
    public function nada(Request $r, array $p = []): void
    {
    }
}
final class DepA
{
    public function __construct(public DepB $b)
    {
    }
}
final class DepB
{
    public function __construct(public DepA $a)
    {
    }
}
final class Folha
{
    public function __construct(public string $nome = 'padrao', public ?int $n = null)
    {
    }
}
final class UsaFolha
{
    public function __construct(public Folha $f)
    {
    }
}

function roteador(array $mw = []): Router
{
    $c = new Container();
    return new Router($c, $mw + ['marca' => Marca::class, 'barra' => Barra::class]);
}

// ═══ Autoloader e Config ═══════════════════════════════════════════════════════════════
t('Autoloader: carrega classe do namespace App e ignora as de fora', function () {
    verdade(class_exists('App\Core\Resultado'));
    verdade(!class_exists('App\Nada\NaoExiste'));
    verdade(!class_exists('Outro\Namespace\Qualquer'));
});
t('Config: lê config/app.php e config/database.php, com caminho por ponto e padrão', function () use ($RAIZ) {
    igual('TOP CLOSERS', Config::obter('app.nome'));
    igual('padrao', Config::obter('app.nao.existe', 'padrao'));
    igual('utf8mb4', Config::obter('database.charset'));
    igual($RAIZ, Config::raiz());
    igual($RAIZ . '/storage', Config::storage());
});
t('Config: config/local.php sobrepõe só o que muda e não precisa repetir o resto', function () {
    $t = pastaTemp('cfg_');
    mkdir("$t/config", 0777, true);
    file_put_contents("$t/config/app.php", "<?php return ['nome'=>'A','debug'=>false,'x'=>['y'=>1,'z'=>2]];");
    file_put_contents("$t/config/local.php", "<?php return ['app'=>['debug'=>true,'x'=>['y'=>9]]];");
    Config::carregar($t, "$t/public");
    igual(true, Config::obter('app.debug'));
    igual('A', Config::obter('app.nome'));
    igual(9, Config::obter('app.x.y'));
    igual(2, Config::obter('app.x.z'));
    igual("$t/public", Config::publico());
    apagar($t);
    Config::carregar(dirname(__DIR__, 2), dirname(__DIR__, 2) . '/public');
});
t('Config: os arquivos de config enxergam $raiz e $publico', function () {
    $t = pastaTemp('cfg2_');
    mkdir("$t/config", 0777, true);
    file_put_contents("$t/config/app.php", "<?php return ['r'=>\$raiz,'p'=>\$publico];");
    Config::carregar($t, "$t/pub");
    igual($t, Config::obter('app.r'));
    igual("$t/pub", Config::obter('app.p'));
    apagar($t);
    Config::carregar(dirname(__DIR__, 2), dirname(__DIR__, 2) . '/public');
});

// ═══ Request ═════════════════════════════════════════════════════════════════════════════
t('Request: na raiz do site (/index.php) o caminho sai sem alteração e a query é lida', function () {
    $r = req('GET', '/health?k=1&x=2', [], ['k' => '1', 'x' => '2']);
    igual('/health', $r->caminho());
    igual('', $r->base());
    igual('GET', $r->metodo());
    igual('2', $r->query('x'));
    igual('padrao', $r->query('nada', 'padrao'));
});
t('Request: montado em /nova (HostGator) o prefixo é retirado do caminho', function () {
    $r = req('GET', '/nova/_teste/eco?k=1', ['SCRIPT_NAME' => '/nova/index.php']);
    igual('/_teste/eco', $r->caminho());
    igual('/nova', $r->base());
});
t('Request: /nova, /nova/ e /nova/index.php viram "/"; barras repetidas e finais são normalizadas', function () {
    foreach (['/nova', '/nova/', '/nova/index.php', '/nova/index.php/'] as $u) {
        igual('/', req('GET', $u, ['SCRIPT_NAME' => '/nova/index.php'])->caminho(), $u);
    }
    igual('/a/b', req('GET', '/nova//a//b/', ['SCRIPT_NAME' => '/nova/index.php'])->caminho());
    igual('/index.phpx', req('GET', '/index.phpx')->caminho(), 'não pode confundir /index.phpx com o front controller');
});
t('Request: caminho com acento codificado é decodificado; SCRIPT_NAME que não é index.php não cria base', function () {
    igual('/relatório', req('GET', '/relat%C3%B3rio')->caminho());
    igual('', req('GET', '/a/b', ['SCRIPT_NAME' => '/a/b'])->base());
});
t('Request: corpo de formulário, corpo JSON e JSON inválido', function () {
    igual('1', req('POST', '/x', [], [], ['a' => '1'])->corpo('a'));
    $j = req('POST', '/x', ['CONTENT_TYPE' => 'application/json; charset=utf-8'], [], [], '{"a":1,"b":{"c":2}}');
    igual(1, $j->corpo('a'));
    igual(['c' => 2], $j->corpo('b'));
    igual([], req('POST', '/x', ['CONTENT_TYPE' => 'application/json'], [], [], '{quebrado')->corpo());
    igual('corpo', req('POST', '/x', [], ['v' => 'query'], ['v' => 'corpo'])->entrada('v'), 'corpo vence a query');
});
t('Request: cabeçalhos (case-insensitive), IP, https e aceitaHtml', function () {
    $r = req('GET', '/x', ['HTTP_X_CSRF_TOKEN' => 'abc', 'CONTENT_TYPE' => 'text/plain', 'HTTPS' => 'on', 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,*/*;q=0.8']);
    igual('abc', $r->cabecalho('X-CSRF-Token'));
    igual('abc', $r->cabecalho('x-csrf-token'));
    igual('text/plain', $r->cabecalho('Content-Type'));
    igual('10.0.0.1', $r->ip());
    verdade($r->https());
    verdade($r->aceitaHtml());
    verdade(!req('GET', '/x', ['HTTPS' => 'off'])->https());
    verdade(!req('GET', '/x', ['HTTP_ACCEPT' => 'application/json'])->aceitaHtml());
    verdade(!req('GET', '/x', ['HTTP_ACCEPT' => '*/*'])->aceitaHtml());
    verdade(!req('GET', '/x', ['HTTP_ACCEPT' => 'application/json, text/html'])->aceitaHtml());
});
t('Request: não confia em X-Forwarded-For para o IP', function () {
    igual('10.0.0.1', req('GET', '/x', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4'])->ip());
});

// ═══ Response e Resultado ═════════════════════════════════════════════════════════════════
t('Response::json: status, tipo, acentos sem escape e cabeçalhos de segurança', function () {
    $r = Response::json(['m' => 'ação'], 201);
    igual(201, $r->status());
    igual('{"m":"ação"}', $r->corpo());
    igual('application/json; charset=utf-8', $r->cabecalho('content-type'));
    igual('nosniff', $r->cabecalho('X-Content-Type-Options'));
    igual('SAMEORIGIN', $r->cabecalho('X-Frame-Options'));
    igual('no-store', $r->cabecalho('Cache-Control'));
});
t('Response::json: dado que não vira JSON responde 500 em vez de corpo vazio', function () {
    $r = Response::json(['x' => NAN]);
    igual(500, $r->status());
    verdade(strpos($r->corpo(), '"success":false') !== false);
});
t('Response: redirecionar, html, comCabecalho não altera o original', function () {
    $r = Response::redirecionar('/login.php');
    igual(302, $r->status());
    igual('/login.php', $r->cabecalho('Location'));
    $h = Response::html('<b>x</b>');
    igual('text/html; charset=utf-8', $h->cabecalho('Content-Type'));
    $h2 = $h->comCabecalho('X-A', '1');
    igual(null, $h->cabecalho('X-A'));
    igual('1', $h2->cabecalho('X-A'));
});
t('Resultado: ok, falha, formato do api.php ({success, message}) e status HTTP', function () {
    $ok = Resultado::ok(['id' => 5], 'Salvo');
    verdade($ok->sucesso());
    igual(['success' => true, 'message' => 'Salvo', 'id' => 5], $ok->paraArray());
    igual(200, $ok->paraResponse()->status());
    $f = Resultado::falha('Inválido', ['telefone' => 'Telefone inválido']);
    verdade(!$f->sucesso());
    igual(['success' => false, 'message' => 'Inválido', 'errors' => ['telefone' => 'Telefone inválido']], $f->paraArray());
    igual(422, $f->paraResponse()->status());
    igual(409, $f->paraResponse(409)->status());
    igual(['success' => true], Resultado::ok()->paraArray());
});

// ═══ View ═════════════════════════════════════════════════════════════════════════════════
t('View: escapa HTML (XSS) no texto e em atributos', function () use ($RAIZ, $PUBLICO) {
    $v = new View($RAIZ . '/app/Views', $PUBLICO, '');
    igual('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $v->e('<script>alert("x")</script>'));
    igual('a&#039;b', $v->e("a'b"));
    $html = $v->renderizar('teste/visao', ['titulo' => 'T', 'nome' => '<img src=x onerror=alert(1)>']);
    verdade(strpos($html, '&lt;img src=x onerror=alert(1)&gt;') !== false, 'nome deve sair escapado');
    verdade(strpos($html, '<img src=x') === false, 'não pode sobrar tag crua');
});
t('View: layout + view + parcial; título do layout vem dos dados', function () use ($RAIZ, $PUBLICO) {
    $html = (new View($RAIZ . '/app/Views', $PUBLICO, ''))->renderizar('teste/visao', ['titulo' => 'Meu título', 'nome' => 'Ana']);
    verdade(strpos($html, '<title>Meu título</title>') !== false);
    verdade(strpos($html, '<h1>Olá, Ana</h1>') !== false);
    verdade(strpos($html, 'núcleo MVC') !== false, 'o parcial do rodapé deve estar incluído');
    verdade(strpos($html, '<!DOCTYPE html>') === 0);
    $solta = trim((new View($RAIZ . '/app/Views', $PUBLICO))->renderizar('teste/visao', ['titulo' => 'x', 'nome' => 'y'], null));
    verdade(strpos($solta, '<main>') === 0 && strpos($solta, '<!DOCTYPE') === false, 'sem layout devolve só a view');
});
t('View::asset: endereço com ?v=<data do arquivo> e prefixo da pasta onde o núcleo está montado', function () use ($RAIZ, $PUBLICO) {
    $v = new View($RAIZ . '/app/Views', $PUBLICO, '/nova');
    $url = $v->asset('css/base.css');
    $esperado = (int) filemtime($PUBLICO . '/assets/css/base.css');
    igual('/nova/assets/css/base.css?v=' . $esperado, $url);
    verdade($esperado > 0);
    igual('/nova/assets/nao-existe.js?v=0', $v->asset('nao-existe.js'));
    lanca(InvalidArgumentException::class, fn () => $v->asset('../config/app.php'));
});
t('View: nome inválido, tentativa de sair da pasta e view inexistente são recusados', function () use ($RAIZ, $PUBLICO) {
    $v = new View($RAIZ . '/app/Views', $PUBLICO);
    lanca(InvalidArgumentException::class, fn () => $v->parcial('../../config/app'));
    lanca(InvalidArgumentException::class, fn () => $v->parcial('teste/../visao'));
    lanca(InvalidArgumentException::class, fn () => $v->parcial('naoexiste/nada'));
    lanca(InvalidArgumentException::class, fn () => $v->parcial("teste/visao\0.php"));
});
t('View: erro dentro do template não deixa saída parcial vazar', function () {
    $t = pastaTemp('views_');
    file_put_contents("$t/quebra.php", '<?php echo "parcial"; throw new RuntimeException("falhou");');
    $nivel = ob_get_level();
    lanca(RuntimeException::class, fn () => (new View($t, $t))->parcial('quebra'));
    igual($nivel, ob_get_level(), 'o buffer de saída precisa ser fechado');
    apagar($t);
});

// ═══ Container ═════════════════════════════════════════════════════════════════════════════
t('Container: monta pelas dependências do construtor, reaproveita a instância e respeita valor padrão', function () {
    $c = new Container();
    $u = $c->obter(UsaFolha::class);
    igual('padrao', $u->f->nome);
    igual(null, $u->f->n);
    verdade($c->obter(Folha::class) === $u->f, 'mesma instância');
    $c->instancia(Folha::class, new Folha('fixa'));
    igual('fixa', $c->obter(Folha::class)->nome);
});
t('Container: dependência circular e classe inexistente dão erro claro', function () {
    lanca(RuntimeException::class, fn () => (new Container())->obter(DepA::class));
    lanca(RuntimeException::class, fn () => (new Container())->obter('App\Nao\Existe'));
});

// ═══ Router ════════════════════════════════════════════════════════════════════════════════
t('Router: rota simples, parâmetro com restrição, barra final e HEAD', function () {
    $r = roteador();
    $r->get('/p/{id:\d+}', [CtrlTeste::class, 'listar']);
    $r->get('/ola', [CtrlTeste::class, 'listar']);
    igual('12', json_decode($r->despachar(req('GET', '/p/12'))->corpo(), true)['id']);
    igual(404, $r->despachar(req('GET', '/p/abc'))->status());
    igual(200, $r->despachar(req('GET', '/ola/'))->status());
    igual(200, $r->despachar(req('HEAD', '/ola'))->status());
});
t('Router: parâmetro é decodificado, e "." na rota não vira curinga', function () {
    $r = roteador();
    $r->get('/arq/{id}', [CtrlTeste::class, 'listar']);
    $r->get('/dados.json', [CtrlTeste::class, 'listar']);
    igual('a b', json_decode($r->despachar(req('GET', '/arq/a%20b'))->corpo(), true)['id']);
    igual(404, $r->despachar(req('GET', '/dadosXjson'))->status());
    igual(200, $r->despachar(req('GET', '/dados.json'))->status());
});
t('Router: 404 JSON para rota inexistente e 405 com Allow para método errado', function () {
    $r = roteador();
    $r->post('/salvar', [CtrlTeste::class, 'listar']);
    $n = $r->despachar(req('GET', '/nada'));
    igual(404, $n->status());
    igual('Rota não encontrada.', json_decode($n->corpo(), true)['message']);
    igual('application/json; charset=utf-8', $n->cabecalho('Content-Type'));
    $m = $r->despachar(req('GET', '/salvar'));
    igual(405, $m->status());
    igual('POST', $m->cabecalho('Allow'));
});
t('Router: middlewares rodam na ordem (antes na ida, depois na volta) e recebem os argumentos', function () {
    Marca::$log = [];
    $r = roteador();
    $r->get('/m', [CtrlTeste::class, 'listar'], ['marca:A', 'marca:B']);
    igual(200, $r->despachar(req('GET', '/m'))->status());
    igual(['antes:A', 'antes:B', 'depois:B', 'depois:A'], Marca::$log);
});
t('Router: middleware que barra impede o Controller de rodar', function () {
    CtrlTeste::$log = [];
    $r = roteador();
    $r->get('/p', [CtrlTeste::class, 'listar'], ['barra']);
    $resp = $r->despachar(req('GET', '/p'));
    igual(401, $resp->status());
    igual([], CtrlTeste::$log, 'o Controller não pode ter rodado');
});
t('Router: exceção vira 500 genérico, sem vazar detalhe nem caminho (e com debug mostra)', function () {
    $r = roteador();
    $r->get('/q', [CtrlTeste::class, 'quebra']);
    $log = ini_set('error_log', sys_get_temp_dir() . '/nucleo-teste.log');
    Config::definir('app.debug', false);
    $resp = $r->despachar(req('GET', '/q'));
    igual(500, $resp->status());
    verdade(strpos($resp->corpo(), 'privado') === false && strpos($resp->corpo(), 'segredo') === false, 'não pode vazar a mensagem');
    verdade(strpos($resp->corpo(), '"debug"') === false);
    Config::definir('app.debug', true);
    verdade(strpos($r->despachar(req('GET', '/q'))->corpo(), 'segredo interno') !== false, 'com debug mostra');
    Config::definir('app.debug', false);
    ini_set('error_log', (string) $log);
});
t('Router: middleware desconhecido vira 500 (erro de configuração, não passa batido)', function () {
    $r = roteador();
    $r->get('/x', [CtrlTeste::class, 'listar'], ['inexistente']);
    $log = ini_set('error_log', sys_get_temp_dir() . '/nucleo-teste.log');
    igual(500, $r->despachar(req('GET', '/x'))->status());
    ini_set('error_log', (string) $log);
});
t('Router: o retorno do Controller vira Response (array=JSON, texto=HTML, vazio=204, Resultado, Closure)', function () {
    $r = roteador();
    $r->get('/a', [CtrlTeste::class, 'array']);
    $r->get('/t', [CtrlTeste::class, 'texto']);
    $r->get('/n', [CtrlTeste::class, 'nada']);
    $r->get('/c', fn (Request $req, array $p) => Resultado::falha('Não deu'));
    $r->get('/k', fn (Request $req, array $p) => Response::json(['k' => 1], 202));
    igual('{"a":1}', $r->despachar(req('GET', '/a'))->corpo());
    igual('text/html; charset=utf-8', $r->despachar(req('GET', '/t'))->cabecalho('Content-Type'));
    igual(204, $r->despachar(req('GET', '/n'))->status());
    igual(422, $r->despachar(req('GET', '/c'))->status());
    igual(202, $r->despachar(req('GET', '/k'))->status());
});

// ═══ Csrf (núcleo e middleware) ═══════════════════════════════════════════════════════════
t('Csrf: token de 64 caracteres, guardado na sessão (csrf_admin) e estável entre chamadas', function () {
    Session::simular([]);
    $a = Csrf::token();
    verdade((bool) preg_match('/^[0-9a-f]{64}$/', $a));
    igual($a, Csrf::token());
    igual($a, $_SESSION['csrf_admin'], 'mesma chave de sessão do config.php atual');
});
t('Csrf::valido: cabeçalho X-CSRF-Token, campo _csrf, token errado, vazio e sessão sem token', function () {
    Session::simular(['csrf_admin' => 'abc123']);
    verdade(Csrf::valido(req('POST', '/x', ['HTTP_X_CSRF_TOKEN' => 'abc123'])));
    verdade(Csrf::valido(req('POST', '/x', [], [], ['_csrf' => 'abc123'])));
    verdade(!Csrf::valido(req('POST', '/x', ['HTTP_X_CSRF_TOKEN' => 'errado'])));
    verdade(!Csrf::valido(req('POST', '/x')));
    verdade(!Csrf::valido(req('POST', '/x', ['HTTP_X_CSRF_TOKEN' => ''])));
    Session::simular([]);
    verdade(!Csrf::valido(req('POST', '/x', ['HTTP_X_CSRF_TOKEN' => ''])), 'sessão sem token nunca valida');
    verdade(!Csrf::valido(req('POST', '/x', ['HTTP_X_CSRF_TOKEN' => 'qualquer'])));
});
t('Middleware csrf: leitura passa; escrita sem token = 419; com token passa', function () {
    Session::simular(['csrf_admin' => 'tok']);
    $mw = new CsrfMiddleware();
    $passa = fn (Request $r) => Response::json(['passou' => true]);
    igual(200, $mw->handle(req('GET', '/x'), $passa, [])->status());
    $barrada = $mw->handle(req('POST', '/x'), $passa, []);
    igual(419, $barrada->status());
    igual('csrf', json_decode($barrada->corpo(), true)['code']);
    igual(200, $mw->handle(req('POST', '/x', ['HTTP_X_CSRF_TOKEN' => 'tok']), $passa, [])->status());
    igual(419, $mw->handle(req('DELETE', '/x'), $passa, [])->status());
    igual(419, $mw->handle(req('PUT', '/x'), $passa, [])->status());
});

// ═══ Auth e ChaveTeste ═════════════════════════════════════════════════════════════════════
$novoAuth = static fn (): Auth => new Auth(new ContaCloserRepository(new Database()));
t('Middleware auth:admin: logado passa; sem login redireciona (página) ou responde 401 (API)', function () use ($novoAuth) {
    $mw = $novoAuth();
    $passa = fn (Request $r) => Response::json(['passou' => true]);
    Session::simular(['admin_logged' => true]);
    igual(200, $mw->handle(req('GET', '/x'), $passa, ['admin'])->status());
    Session::simular([]);
    $pagina = $mw->handle(req('GET', '/x', ['HTTP_ACCEPT' => 'text/html']), $passa, ['admin']);
    igual(302, $pagina->status());
    igual('/login', $pagina->cabecalho('Location'));
    $api = $mw->handle(req('GET', '/x', ['HTTP_ACCEPT' => 'application/json']), $passa, ['admin']);
    igual(401, $api->status());
    igual(401, $mw->handle(req('POST', '/x', ['HTTP_ACCEPT' => 'text/html']), $passa, ['admin'])->status(), 'POST nunca redireciona');
    Session::simular(['admin_logged' => 'sim']);
    igual(401, $mw->handle(req('GET', '/x'), $passa, ['admin'])->status(), 'só true estrito conta como logado');
});
t('Middleware auth:closer: usa closer_id e manda para o login do portal', function () use ($novoAuth) {
    $mw = $novoAuth();
    $passa = fn (Request $r) => Response::json(['passou' => true]);
    Session::simular(['closer_id' => 7]);
    igual(200, $mw->handle(req('GET', '/x'), $passa, ['closer'])->status());
    Session::simular(['admin_logged' => true]);
    $r = $mw->handle(req('GET', '/x', ['HTTP_ACCEPT' => 'text/html']), $passa, ['closer']);
    igual('/closer/login', $r->cabecalho('Location'), 'admin logado não vale como closer');
});
t('Middleware chave: sem chave, chave errada ou chave vazia na config = 404 igual ao de rota inexistente', function () {
    $mw = new ChaveTeste();
    $passa = fn (Request $r) => Response::json(['passou' => true]);
    Config::definir('app.chave_teste', 'segredo');
    $semChave = $mw->handle(req('GET', '/x'), $passa, []);
    $errada = $mw->handle(req('GET', '/x', [], ['k' => 'outra']), $passa, []);
    $naoExiste = Router::naoEncontrada();
    igual($naoExiste->status(), $semChave->status());
    igual($naoExiste->corpo(), $semChave->corpo(), 'não pode revelar que a rota existe');
    igual($naoExiste->corpo(), $errada->corpo());
    igual(200, $mw->handle(req('GET', '/x', [], ['k' => 'segredo']), $passa, [])->status());
    Config::definir('app.chave_teste', '');
    igual(404, $mw->handle(req('GET', '/x', [], ['k' => '']), $passa, [])->status(), 'chave vazia na config nunca libera');
    Config::carregar(dirname(__DIR__, 2), dirname(__DIR__, 2) . '/public');
});

// ═══ RateLimit ═════════════════════════════════════════════════════════════════════════════
t('RateLimit: N chamadas passam, a seguinte leva 429 com Retry-After; outro IP é independente', function () {
    $pasta = pastaTemp('rl_');
    Config::definir('app.storage', $pasta);
    $mw = new RateLimit();
    $passa = fn (Request $r) => Response::json(['passou' => true]);
    for ($i = 1; $i <= 3; $i++) {
        igual(200, $mw->handle(req('GET', '/x'), $passa, ['login', '3', '60'])->status(), "chamada $i");
    }
    $bloqueada = $mw->handle(req('GET', '/x'), $passa, ['login', '3', '60']);
    igual(429, $bloqueada->status());
    verdade((int) $bloqueada->cabecalho('Retry-After') > 0 && (int) $bloqueada->cabecalho('Retry-After') <= 60);
    igual(200, $mw->handle(req('GET', '/x', ['REMOTE_ADDR' => '10.0.0.2']), $passa, ['login', '3', '60'])->status(), 'outro IP');
    igual(200, $mw->handle(req('GET', '/x'), $passa, ['outra-rota', '3', '60'])->status(), 'outra rota');
    verdade(!empty(glob($pasta . '/rate-limit/*.json')), 'o controle fica em storage/rate-limit');
    Config::carregar(dirname(__DIR__, 2), dirname(__DIR__, 2) . '/public');
    apagar($pasta);
});
t('RateLimit: a janela expira e a contagem recomeça', function () {
    $pasta = pastaTemp('rl2_');
    Config::definir('app.storage', $pasta);
    $mw = new RateLimit();
    $passa = fn (Request $r) => Response::json(['passou' => true]);
    igual(200, $mw->handle(req('GET', '/x'), $passa, ['j', '1', '1'])->status());
    igual(429, $mw->handle(req('GET', '/x'), $passa, ['j', '1', '1'])->status());
    sleep(2);
    igual(200, $mw->handle(req('GET', '/x'), $passa, ['j', '1', '1'])->status(), 'depois da janela volta a passar');
    Config::carregar(dirname(__DIR__, 2), dirname(__DIR__, 2) . '/public');
    apagar($pasta);
});
t('RateLimit: pasta não gravável não derruba a rota (deixa passar e registra no log)', function () {
    $arquivo = tempnam(sys_get_temp_dir(), 'rl3_');
    Config::definir('app.storage', $arquivo . '/dentro');   // "pasta" dentro de um arquivo: impossível criar
    $mw = new RateLimit();
    $log = ini_set('error_log', sys_get_temp_dir() . '/nucleo-teste.log');
    igual(200, $mw->handle(req('GET', '/x'), fn (Request $r) => Response::json(['passou' => true]), ['j', '1', '60'])->status());
    ini_set('error_log', (string) $log);
    @unlink($arquivo);
    Config::carregar(dirname(__DIR__, 2), dirname(__DIR__, 2) . '/public');
});

// ═══ Database (MySQL de teste, só tabela temporária) ══════════════════════════════════════════════
t('Database: acha o config.secrets.php, conecta e devolve a versão sem vazar credenciais', function () {
    $db = new Database();
    $r = $db->testar();
    verdade($r['ok'] === true, 'conexão: ' . json_encode($r));
    verdade(strpos($r['versao'], '8.') === 0 || (int) $r['versao'] > 0, 'versão: ' . $r['versao']);
    $texto = json_encode($r);
    foreach (['DB_PASS', 'DB_USER', 'DB_HOST', 'DB_NAME'] as $c) {
        verdade($texto === false || strpos($texto, (string) constant($c)) === false || (string) constant($c) === '', "o diagnóstico vazou $c");
    }
});
t('Database: senha errada vira "PDOException 1045" e nunca mostra host, usuário ou senha', function () {
    $db = new Database(['host' => DB_HOST, 'banco' => DB_NAME, 'usuario' => DB_USER, 'senha' => 'senha-errada-de-teste']);
    $r = $db->testar();
    igual(false, $r['ok']);
    igual('PDOException 1045', $r['erro']);
    verdade(strpos(json_encode($r), DB_USER) === false);
});
t('Database: prepared statements tratam entrada maliciosa como texto, não como SQL', function () {
    $db = new Database();
    $mal = "1' OR '1'='1'; DROP TABLE x; --";
    $linhas = $db->consultar('SELECT ? AS a, ? AS b', [$mal, 5]);
    igual($mal, $linhas[0]['a']);
    igual(5, (int) $linhas[0]['b']);
    igual($mal, $db->valor('SELECT ?', [$mal]));
    igual(null, $db->linha('SELECT 1 WHERE 1 = ?', [2]));
    igual(['x' => 1], array_map('intval', $db->linha('SELECT 1 AS x') ?? []));
});
t('Database: transação confirma ao terminar e desfaz quando lança exceção (tabela temporária)', function () {
    $db = new Database();
    $db->executar('CREATE TEMPORARY TABLE nucleo_t (id INT) ENGINE=InnoDB');
    $db->transacao(fn (Database $d) => $d->executar('INSERT INTO nucleo_t (id) VALUES (?)', [1]));
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM nucleo_t'));
    lanca(RuntimeException::class, fn () => $db->transacao(function (Database $d) {
        $d->executar('INSERT INTO nucleo_t (id) VALUES (?)', [2]);
        throw new RuntimeException('desfaz');
    }));
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM nucleo_t'), 'o insert da transação com erro precisa sumir');
    igual(1, $db->executar('UPDATE nucleo_t SET id = ? WHERE id = ?', [9, 1]));
});

// ═══ Aplicação completa (sem HTTP) ════════════════════════════════════════════════════════════════
t('Aplicação: /health com a chave responde ok, com banco, e sem vazar senha nem caminhos de disco', function () use ($RAIZ, $PUBLICO) {
    Config::carregar($RAIZ, $PUBLICO);
    $chave = (string) Config::obter('app.chave_teste');
    $app = new Aplicacao($RAIZ, $PUBLICO);
    $resp = $app->responder(req('GET', '/health?k=' . $chave, [], ['k' => $chave]));
    igual(200, $resp->status());
    $j = json_decode($resp->corpo(), true);
    igual('ok', $j['status']);
    igual(PHP_VERSION, $j['php']);
    igual(true, $j['banco']['ok']);
    igual(true, $j['storage_gravavel']);
    igual(true, $j['extensoes']['pdo_mysql']);
    foreach ([DB_PASS, DB_USER, str_replace('/', '\\', $RAIZ), $RAIZ] as $segredo) {
        verdade($segredo === '' || strpos($resp->corpo(), (string) $segredo) === false, 'o /health não pode mostrar: ' . substr((string) $segredo, 0, 3) . '…');
    }
});
t('Aplicação: /health sem chave, com chave errada ou com rotas_teste=false responde 404', function () use ($RAIZ, $PUBLICO) {
    Config::carregar($RAIZ, $PUBLICO);
    $app = new Aplicacao($RAIZ, $PUBLICO);
    igual(404, $app->responder(req('GET', '/health'))->status());
    igual(404, $app->responder(req('GET', '/health?k=errada', [], ['k' => 'errada']))->status());
    $chave = (string) Config::obter('app.chave_teste');
    Config::definir('app.rotas_teste', false);
    igual(404, $app->responder(req('GET', '/health?k=' . $chave, [], ['k' => $chave]))->status(), 'rotas_teste=false remove a rota');
    Config::carregar($RAIZ, $PUBLICO);
});
t('Aplicação: rotas de teste — csrf, auth, parâmetro, eco JSON e visão escapada', function () use ($RAIZ, $PUBLICO) {
    Config::carregar($RAIZ, $PUBLICO);
    $chave = (string) Config::obter('app.chave_teste');
    $app = new Aplicacao($RAIZ, $PUBLICO);
    $k = ['k' => $chave];
    Session::simular(['admin_logged' => true, 'admin_user' => 'teste', 'csrf_admin' => 'tk']);
    igual(419, $app->responder(req('POST', '/_teste/csrf?k=' . $chave, [], $k))->status(), 'POST sem token');
    igual(200, $app->responder(req('POST', '/_teste/csrf?k=' . $chave, ['HTTP_X_CSRF_TOKEN' => 'tk'], $k))->status(), 'POST com token');
    igual('teste', json_decode($app->responder(req('GET', '/_teste/admin?k=' . $chave, [], $k))->corpo(), true)['usuario']);
    Session::simular([]);
    igual(401, $app->responder(req('GET', '/_teste/admin?k=' . $chave, ['HTTP_ACCEPT' => 'application/json'], $k))->status());
    igual(302, $app->responder(req('GET', '/_teste/admin?k=' . $chave, ['HTTP_ACCEPT' => 'text/html'], $k))->status());
    igual('42', json_decode($app->responder(req('GET', '/_teste/parametro/42?k=' . $chave, [], $k))->corpo(), true)['id']);
    igual(404, $app->responder(req('GET', '/_teste/parametro/abc?k=' . $chave, [], $k))->status());
    $eco = json_decode($app->responder(req('POST', '/_teste/eco?k=' . $chave, ['CONTENT_TYPE' => 'application/json'], $k, [], '{"x":"do-json"}'))->corpo(), true);
    igual('do-json', $eco['entrada_x']);
    igual(true, $eco['json']);
    $html = $app->responder(req('GET', '/_teste/visao?k=' . $chave . '&nome=x', [], $k + ['nome' => '<script>alert(1)</script>']))->corpo();
    verdade(strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($html, '<script>alert(1)') === false, 'XSS escapado na rota');
});

// ═══ Layouts de pastas: ambiente local e HostGator (app/ fora do public_html) ═════════════════════════════
function rodarFrontController(string $index, string $scriptName, string $uri, array $get): array
{
    // o código vai para um arquivo (no Windows o escapeshellarg troca aspas duplas por espaço e quebraria um -r)
    $arquivo = tempnam(sys_get_temp_dir(), 'fc_') . '.php';
    $codigo = "<?php\n"
        . '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export('GET', true) . ";\n"
        . '$_SERVER[' . var_export('REQUEST_URI', true) . '] = ' . var_export($uri, true) . ";\n"
        . '$_SERVER[' . var_export('SCRIPT_NAME', true) . '] = ' . var_export($scriptName, true) . ";\n"
        . '$_SERVER[' . var_export('REMOTE_ADDR', true) . '] = ' . var_export('127.0.0.1', true) . ";\n"
        . '$_GET = ' . var_export($get, true) . ";\n"
        . 'require ' . var_export($index, true) . ";\n";
    file_put_contents($arquivo, $codigo);
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d extension_dir=' . escapeshellarg((string) ini_get('extension_dir')) . ' -d extension=pdo_mysql ' . escapeshellarg($arquivo) . ' 2>&1';
    $saida = (string) shell_exec($cmd);
    @unlink($arquivo);
    $j = json_decode($saida, true);
    return is_array($j) ? $j : ['_bruto' => $saida];
}
t('Layout HostGator: index em public_html/nova e app/ acima do public_html — acha tudo e responde com base /nova', function () use ($RAIZ) {
    $home = pastaTemp('home_');
    foreach (['app', 'config', 'storage'] as $p) {
        copiar("$RAIZ/$p", "$home/$p");
    }
    mkdir("$home/public_html/nova", 0777, true);
    copy("$RAIZ/public/index.php", "$home/public_html/nova/index.php");
    // segredos FALSOS na raiz do site (public_html), onde o login.php antigo ficaria: prova a busca "pasta acima da pública"
    file_put_contents("$home/public_html/config.secrets.php", "<?php define('DB_HOST','127.0.0.1');define('DB_NAME','nao_existe');define('DB_USER','usuario_falso');define('DB_PASS','senha_falsa');");
    $chave = (string) Config::obter('app.chave_teste');
    $j = rodarFrontController("$home/public_html/nova/index.php", '/nova/index.php', '/nova/health?k=' . $chave, ['k' => $chave]);
    verdade(isset($j['success']), 'resposta inesperada: ' . json_encode($j));
    igual('/nova', $j['montado_em']);
    igual('/health', $j['caminho']);
    igual('degradado', $j['status'], 'com segredos falsos o banco precisa falhar');
    igual('PDOException 1045', $j['banco']['erro'], 'achou o config.secrets.php em public_html/ (acima de nova/) e tentou conectar');
    igual(true, $j['storage_gravavel'], '~/storage gravável');
    verdade(strpos(json_encode($j), 'usuario_falso') === false && strpos(json_encode($j), 'senha_falsa') === false);
    apagar($home);
});
t('Layout HostGator recomendado: pasta única ~/nucleo (app, config, storage) acima do public_html, núcleo em public_html/nova', function () use ($RAIZ) {
    $home = pastaTemp('home4_');
    foreach (['app', 'config', 'storage'] as $p) {
        copiar("$RAIZ/$p", "$home/nucleo/$p");
    }
    mkdir("$home/public_html/nova", 0777, true);
    copy("$RAIZ/public/index.php", "$home/public_html/nova/index.php");
    file_put_contents("$home/public_html/config.secrets.php", "<?php define('DB_HOST','127.0.0.1');define('DB_NAME','nao_existe');define('DB_USER','usuario_falso');define('DB_PASS','senha_falsa');");
    $chave = (string) Config::obter('app.chave_teste');
    $j = rodarFrontController("$home/public_html/nova/index.php", '/nova/index.php', '/nova/health?k=' . $chave, ['k' => $chave]);
    igual('/nova', $j['montado_em'] ?? json_encode($j));
    igual('PDOException 1045', $j['banco']['erro'] ?? '', 'achou ~/nucleo e os segredos em public_html/');
    igual(true, $j['storage_gravavel'], '~/nucleo/storage gravável');
    apagar($home);
});
t('Layout HostGator: sem config.secrets.php o /health explica o problema de configuração (e sem caminho)', function () use ($RAIZ) {
    $home = pastaTemp('home2_');
    foreach (['app', 'config', 'storage'] as $p) {
        copiar("$RAIZ/$p", "$home/$p");
    }
    mkdir("$home/public_html/nova", 0777, true);
    copy("$RAIZ/public/index.php", "$home/public_html/nova/index.php");
    $chave = (string) Config::obter('app.chave_teste');
    $j = rodarFrontController("$home/public_html/nova/index.php", '/nova/index.php', '/nova/health?k=' . $chave, ['k' => $chave]);
    igual('degradado', $j['status']);
    verdade(strpos($j['banco']['erro'], 'config.secrets.php não encontrado') !== false, json_encode($j['banco']));
    verdade(strpos(json_encode($j), str_replace('/', '\\/', $home)) === false && strpos(json_encode($j), $home) === false, 'sem caminho de disco');
    apagar($home);
});
t('Layout HostGator: config/local.php com o caminho dos segredos resolve quando a busca automática não acha', function () use ($RAIZ) {
    $home = pastaTemp('home3_');
    foreach (['app', 'config', 'storage'] as $p) {
        copiar("$RAIZ/$p", "$home/$p");
    }
    mkdir("$home/public_html/nova", 0777, true);
    mkdir("$home/outro-lugar", 0777, true);
    copy("$RAIZ/public/index.php", "$home/public_html/nova/index.php");
    file_put_contents("$home/outro-lugar/segredos.php", "<?php define('DB_HOST','127.0.0.1');define('DB_NAME','x');define('DB_USER','u_falso');define('DB_PASS','p_falsa');");
    file_put_contents("$home/config/local.php", "<?php return ['database' => ['segredos' => '$home/outro-lugar/segredos.php']];");
    $chave = (string) Config::obter('app.chave_teste');
    $j = rodarFrontController("$home/public_html/nova/index.php", '/nova/index.php', '/nova/health?k=' . $chave, ['k' => $chave]);
    igual('PDOException 1045', $j['banco']['erro'] ?? json_encode($j));
    apagar($home);
});
t('Layout: sem a pasta app/ o front controller responde 500 claro, sem caminho de disco', function () use ($RAIZ) {
    $t = pastaTemp('semapp_');
    mkdir("$t/public_html/nova", 0777, true);
    copy("$RAIZ/public/index.php", "$t/public_html/nova/index.php");
    $j = rodarFrontController("$t/public_html/nova/index.php", '/nova/index.php', '/nova/health', []);
    igual(false, $j['success'] ?? null, json_encode($j));
    verdade(strpos($j['message'], 'Núcleo não encontrado') !== false);
    verdade(strpos($j['message'], $t) === false);
    apagar($t);
});
t('Layout local: public/ ao lado de app/ funciona (rota inexistente = 404 JSON)', function () use ($RAIZ) {
    $j = rodarFrontController("$RAIZ/public/index.php", '/index.php', '/nao-existe', []);
    igual(false, $j['success'] ?? null, json_encode($j));
    igual('Rota não encontrada.', $j['message']);
});

// ═══ Arquivos de proteção ═════════════════════════════════════════════════════════════════════════════
t('Proteções: .htaccess da pasta pública reescreve para o index.php e bloqueia PHP e arquivos ocultos', function () use ($RAIZ) {
    $h = (string) file_get_contents($RAIZ . '/public/.htaccess');
    verdade(strpos($h, 'RewriteRule ^ index.php [L]') !== false);
    verdade(strpos($h, '!-f') !== false && strpos($h, '!-d') !== false);
    verdade(strpos($h, 'Options -Indexes') !== false);
    verdade(strpos($h, 'index\.php$') !== false, 'só o index.php executa');
    verdade(strpos((string) file_get_contents($RAIZ . '/public/assets/.htaccess'), 'php') !== false);
});
t('Proteções: app/, config/ e storage/ têm .htaccess "negar tudo" e storage tem as 3 subpastas', function () use ($RAIZ) {
    foreach (['app', 'config', 'storage'] as $p) {
        $h = (string) file_get_contents("$RAIZ/$p/.htaccess");
        verdade(strpos($h, 'Require all denied') !== false && strpos($h, 'Deny from all') !== false, $p);
    }
    foreach (['logs', 'rate-limit', 'cache'] as $d) {
        verdade(is_dir("$RAIZ/storage/$d"), "storage/$d");
    }
});
t('Compatibilidade: nenhum arquivo do núcleo usa recurso do PHP 8.1 ou mais novo', function () use ($RAIZ) {
    $proibidos = ['/\benum\s+\w+/', '/\breadonly\b/', '/\bnever\b\s*\{?/', '/new\s+\w+\(\.\.\.\)/', '/\(\.\.\.\)/', '/array_is_list\(/', '/#\[ReturnTypeWillChange\]/'];
    $achados = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($RAIZ . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (substr((string) $f, -4) !== '.php') {
            continue;
        }
        $src = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents((string) $f));
        foreach ($proibidos as $re) {
            if (preg_match($re, (string) $src)) {
                $achados[] = basename((string) $f) . ' ~ ' . $re;
            }
        }
    }
    igual([], $achados, 'recursos de PHP 8.1+ encontrados');
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
if ($falharam) {
    echo 'Falhas: ' . implode(' | ', $falharam) . "\n";
}
exit($falharam ? 1 : 0);
