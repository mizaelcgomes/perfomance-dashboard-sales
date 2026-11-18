<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A requisição HTTP já "lida": método, caminho (sem a pasta onde o núcleo está montado, como /nova), query, corpo
 * (formulário ou JSON) e cabeçalhos. Nada daqui escreve na sessão nem no banco.
 */
final class Request
{
    /** @var array<string, string> */
    private array $parametros = [];
    /** @var array<string, string> */
    private array $cabecalhos = [];
    /** @var array<string, mixed> */
    private array $arquivos = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $corpo
     * @param array<string, mixed> $servidor
     */
    public function __construct(
        private string $metodo,
        private string $caminho,
        private string $base,
        private array $query = [],
        private array $corpo = [],
        private array $servidor = [],
        private string $bruto = ''
    ) {
        foreach ($servidor as $chave => $valor) {
            if (!is_string($chave) || !is_scalar($valor)) {
                continue;
            }
            if (strncmp($chave, 'HTTP_', 5) === 0) {
                $this->cabecalhos[strtolower(str_replace('_', '-', substr($chave, 5)))] = (string) $valor;
            } elseif ($chave === 'CONTENT_TYPE' || $chave === 'CONTENT_LENGTH') {
                $this->cabecalhos[strtolower(str_replace('_', '-', $chave))] = (string) $valor;
            }
        }
    }

    public static function capturar(): self
    {
        return self::deServidor($_SERVER, $_GET, $_POST, (string) file_get_contents('php://input'))->comArquivos($_FILES);
    }

    /** Arquivos enviados pelo formulário (o $_FILES do PHP). @param array<string, mixed> $arquivos */
    public function comArquivos(array $arquivos): self
    {
        $copia = clone $this;
        $copia->arquivos = $arquivos;
        return $copia;
    }

    /** Um arquivo enviado (name, tmp_name, size, error...) ou null se o campo não veio. @return array<string, mixed>|null */
    public function arquivo(string $nome): ?array
    {
        $a = $this->arquivos[$nome] ?? null;
        return is_array($a) ? $a : null;
    }

    /**
     * Monta a requisição a partir das variáveis do servidor (separado de capturar() para poder testar sem HTTP).
     * @param array<string, mixed> $s
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    public static function deServidor(array $s, array $get = [], array $post = [], string $bruto = ''): self
    {
        $metodo = strtoupper((string) ($s['REQUEST_METHOD'] ?? 'GET'));
        $caminhoBruto = (string) (parse_url((string) ($s['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $base = self::baseDe($s);

        $corpo = $post;
        $tipo = strtolower((string) ($s['CONTENT_TYPE'] ?? ''));
        if ($corpo === [] && $bruto !== '' && strpos($tipo, 'application/json') !== false) {
            $json = json_decode($bruto, true);
            $corpo = is_array($json) ? $json : [];
        }
        return new self($metodo, self::normalizar($caminhoBruto, $base), $base, $get, $corpo, $s, $bruto);
    }

    /** Pasta onde o index.php está montado: '' na raiz do site, '/nova' quando fica em public_html/nova. */
    private static function baseDe(array $s): string
    {
        $script = str_replace('\\', '/', (string) ($s['SCRIPT_NAME'] ?? ''));
        if ($script === '' || basename($script) !== 'index.php') {
            return '';
        }
        $pasta = rtrim(dirname($script), '/');
        return ($pasta === '.' || $pasta === '\\') ? '' : $pasta;
    }

    private static function normalizar(string $caminho, string $base): string
    {
        $caminho = rawurldecode($caminho);
        if ($base !== '' && ($caminho === $base || strncmp($caminho, $base . '/', strlen($base) + 1) === 0)) {
            $caminho = (string) substr($caminho, strlen($base));
        }
        if ($caminho === '/index.php' || strncmp($caminho, '/index.php/', 11) === 0) {
            $caminho = (string) substr($caminho, 10);
        }
        $caminho = '/' . trim((string) preg_replace('#/+#', '/', $caminho), '/');
        return $caminho === '' ? '/' : $caminho;
    }

    public function metodo(): string
    {
        return $this->metodo;
    }

    public function caminho(): string
    {
        return $this->caminho;
    }

    public function base(): string
    {
        return $this->base;
    }

    /** @param mixed $padrao @return mixed */
    public function query(?string $chave = null, $padrao = null)
    {
        return $chave === null ? $this->query : ($this->query[$chave] ?? $padrao);
    }

    /** @param mixed $padrao @return mixed */
    public function corpo(?string $chave = null, $padrao = null)
    {
        return $chave === null ? $this->corpo : ($this->corpo[$chave] ?? $padrao);
    }

    /** Corpo primeiro, depois a query (como o api.php faz com $_POST e $_GET). @param mixed $padrao @return mixed */
    public function entrada(string $chave, $padrao = null)
    {
        return $this->corpo[$chave] ?? $this->query[$chave] ?? $padrao;
    }

    public function bruto(): string
    {
        return $this->bruto;
    }

    public function cabecalho(string $nome, ?string $padrao = null): ?string
    {
        return $this->cabecalhos[strtolower($nome)] ?? $padrao;
    }

    /** Endereço de quem chamou. Só REMOTE_ADDR: cabeçalhos como X-Forwarded-For podem ser forjados. */
    public function ip(): string
    {
        return (string) ($this->servidor['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function https(): bool
    {
        $h = $this->servidor['HTTPS'] ?? '';
        return !empty($h) && $h !== 'off';
    }

    /** O navegador espera uma página (e não JSON)? Usado para decidir entre redirecionar e responder 401. */
    public function aceitaHtml(): bool
    {
        $aceita = strtolower((string) $this->cabecalho('accept', ''));
        if ($aceita === '' || strpos($aceita, 'text/html') === false) {
            return false;
        }
        $json = strpos($aceita, 'application/json');
        return $json === false || strpos($aceita, 'text/html') < $json;
    }

    /** @param array<string, string> $parametros */
    public function comParametros(array $parametros): self
    {
        $copia = clone $this;
        $copia->parametros = $parametros;
        return $copia;
    }

    /** @param mixed $padrao @return mixed */
    public function parametro(string $nome, $padrao = null)
    {
        return $this->parametros[$nome] ?? $padrao;
    }

    /** @return array<string, string> */
    public function parametros(): array
    {
        return $this->parametros;
    }
}
