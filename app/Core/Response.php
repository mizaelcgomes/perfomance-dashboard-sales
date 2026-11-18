<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A resposta HTTP: status, cabeçalhos e corpo. Controllers e middlewares devolvem uma Response;
 * só o front controller a envia ao navegador (enviar()).
 */
final class Response
{
    /** @var array<string, string> */
    private array $cabecalhos;

    /** @param array<string, string> $cabecalhos */
    public function __construct(private string $corpo = '', private int $status = 200, array $cabecalhos = [])
    {
        // cabeçalhos de segurança que valem para toda resposta; quem criou a resposta pode sobrescrever
        $this->cabecalhos = $cabecalhos + [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
        ];
    }

    /** @param mixed $dados @param array<string, string> $cabecalhos */
    public static function json($dados, int $status = 200, array $cabecalhos = []): self
    {
        $corpo = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($corpo === false) {
            $corpo = '{"success":false,"message":"Falha ao montar a resposta."}';
            $status = 500;
        }
        return new self($corpo, $status, $cabecalhos + [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param array<string, string> $cabecalhos */
    public static function html(string $html, int $status = 200, array $cabecalhos = []): self
    {
        return new self($html, $status, $cabecalhos + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirecionar(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url, 'Cache-Control' => 'no-store']);
    }

    public function comCabecalho(string $nome, string $valor): self
    {
        $copia = clone $this;
        $copia->cabecalhos[$nome] = $valor;
        return $copia;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function corpo(): string
    {
        return $this->corpo;
    }

    /** @return array<string, string> */
    public function cabecalhos(): array
    {
        return $this->cabecalhos;
    }

    public function cabecalho(string $nome): ?string
    {
        foreach ($this->cabecalhos as $k => $v) {
            if (strcasecmp($k, $nome) === 0) {
                return $v;
            }
        }
        return null;
    }

    public function enviar(bool $semCorpo = false): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->cabecalhos as $nome => $valor) {
                header($nome . ': ' . $valor);
            }
        }
        if (!$semCorpo) {
            echo $this->corpo;
        }
    }
}
