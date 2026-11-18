<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Roteador: liga método + caminho a um Controller, com middlewares por rota.
 *
 *   $r->get('/health', [HealthController::class, 'status'], ['chave']);
 *   $r->post('/admin/meta/salvar', [MetaController::class, 'salvar'], ['auth:admin', 'csrf']);
 *   $r->get('/pedido/{id:\d+}', [PedidoController::class, 'ver']);
 *
 * Middlewares são nomes ("auth:admin", "ratelimit:login,5,300"): o que vem depois dos dois-pontos são os argumentos.
 * Rota que não existe responde 404 JSON; caminho que existe com outro método, 405 com o cabeçalho Allow.
 */
final class Router
{
    /** @var array<string, array<int, array{0: string, 1: array<int, string>, 2: mixed, 3: array<int, string>, 4: string}>> */
    private array $rotas = [];

    /** @param array<string, string> $middlewares mapa nome => classe */
    public function __construct(private Container $container, private array $middlewares = [])
    {
    }

    /** @param mixed $handler @param array<int, string> $middlewares */
    public function get(string $caminho, $handler, array $middlewares = []): void
    {
        $this->adicionar(['GET', 'HEAD'], $caminho, $handler, $middlewares);
    }

    /** @param mixed $handler @param array<int, string> $middlewares */
    public function post(string $caminho, $handler, array $middlewares = []): void
    {
        $this->adicionar(['POST'], $caminho, $handler, $middlewares);
    }

    /**
     * @param array<int, string> $metodos
     * @param mixed $handler
     * @param array<int, string> $middlewares
     */
    public function adicionar(array $metodos, string $caminho, $handler, array $middlewares = []): void
    {
        [$regex, $nomes] = self::compilar($caminho);
        foreach ($metodos as $metodo) {
            $this->rotas[$metodo][] = [$regex, $nomes, $handler, $middlewares, $caminho];
        }
    }

    public function despachar(Request $requisicao): Response
    {
        try {
            $caminho = $requisicao->caminho();
            $metodo = $requisicao->metodo();

            foreach ($this->rotas[$metodo] ?? [] as [$regex, $nomes, $handler, $middlewares]) {
                if (preg_match($regex, $caminho, $achou) === 1) {
                    $parametros = [];
                    foreach ($nomes as $i => $nome) {
                        $parametros[$nome] = rawurldecode($achou[$i + 1]);
                    }
                    return $this->executar($requisicao->comParametros($parametros), $handler, $middlewares);
                }
            }

            $permitidos = [];
            foreach ($this->rotas as $outroMetodo => $lista) {
                foreach ($lista as $rota) {
                    if (preg_match($rota[0], $caminho) === 1) {
                        $permitidos[$outroMetodo] = true;
                        break;
                    }
                }
            }
            if ($permitidos !== []) {
                return Response::json(['success' => false, 'message' => 'Método não permitido.'], 405, ['Allow' => implode(', ', array_keys($permitidos))]);
            }
            return self::naoEncontrada();
        } catch (Throwable $erro) {
            return self::erroInterno($erro);
        }
    }

    public static function naoEncontrada(): Response
    {
        return Response::json(['success' => false, 'message' => 'Rota não encontrada.'], 404);
    }

    /** Erro inesperado: vai para o log do servidor; o navegador só recebe uma mensagem genérica (detalhes só com app.debug). */
    public static function erroInterno(Throwable $erro): Response
    {
        error_log(sprintf('[nucleo] %s: %s em %s:%d', get_class($erro), $erro->getMessage(), basename($erro->getFile()), $erro->getLine()));
        $corpo = ['success' => false, 'message' => 'Erro interno. Tente novamente em instantes.'];
        if (Config::obter('app.debug', false) === true) {
            $corpo['debug'] = get_class($erro) . ': ' . $erro->getMessage() . ' (' . basename($erro->getFile()) . ':' . $erro->getLine() . ')';
        }
        return Response::json($corpo, 500);
    }

    /**
     * @param mixed $handler
     * @param array<int, string> $middlewares
     */
    private function executar(Request $requisicao, $handler, array $middlewares): Response
    {
        $final = function (Request $r) use ($handler): Response {
            return $this->chamar($handler, $r);
        };

        $pilha = $final;
        foreach (array_reverse($middlewares) as $especificacao) {
            $proximo = $pilha;
            $pilha = function (Request $r) use ($especificacao, $proximo): Response {
                [$nome, $argumentos] = self::quebrar($especificacao);
                $classe = $this->middlewares[$nome] ?? throw new RuntimeException('Middleware desconhecido: ' . $nome);
                $instancia = $this->container->obter($classe);
                return $instancia->handle($r, $proximo, $argumentos);
            };
        }
        return $pilha($requisicao);
    }

    /** @param mixed $handler */
    private function chamar($handler, Request $requisicao): Response
    {
        if (is_array($handler) && count($handler) === 2) {
            $controller = $this->container->obter((string) $handler[0]);
            $resultado = $controller->{(string) $handler[1]}($requisicao, $requisicao->parametros());
        } elseif ($handler instanceof Closure) {
            $resultado = $handler($requisicao, $requisicao->parametros());
        } else {
            throw new RuntimeException('Handler de rota inválido.');
        }

        return self::comoResponse($resultado);
    }

    /** O que um Controller devolveu vira Response: array = JSON, texto = HTML, Resultado = JSON com status, vazio = 204. @param mixed $resultado */
    public static function comoResponse($resultado): Response
    {
        if ($resultado instanceof Response) {
            return $resultado;
        }
        if ($resultado instanceof Resultado) {
            return $resultado->paraResponse();
        }
        if (is_array($resultado)) {
            return Response::json($resultado);
        }
        if (is_string($resultado)) {
            return Response::html($resultado);
        }
        return new Response('', 204);
    }

    /** "ratelimit:login,5,300" => ['ratelimit', ['login', '5', '300']]. @return array{0: string, 1: array<int, string>} */
    private static function quebrar(string $especificacao): array
    {
        $partes = explode(':', $especificacao, 2);
        $argumentos = isset($partes[1]) && $partes[1] !== '' ? explode(',', $partes[1]) : [];
        return [$partes[0], $argumentos];
    }

    /** "/pedido/{id:\d+}" => regex com um grupo por parâmetro. @return array{0: string, 1: array<int, string>} */
    private static function compilar(string $caminho): array
    {
        $nomes = [];
        $regex = '';
        foreach (preg_split('#(\{[^}]+\})#', $caminho, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $parte) {
            if ($parte[0] === '{') {
                $definicao = explode(':', substr($parte, 1, -1), 2);
                $nomes[] = $definicao[0];
                $regex .= '(' . ($definicao[1] ?? '[^/]+') . ')';
            } else {
                $regex .= preg_quote($parte, '#');
            }
        }
        return ['#^' . rtrim($regex, '/') . '/?$#', $nomes];
    }
}
