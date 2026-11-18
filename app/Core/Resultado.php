<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Retorno das Actions (um caso de uso por classe, estilo Interactor): ok ou falha, com mensagem e dados.
 * paraArray() devolve o mesmo formato que o api.php usa hoje ({success, message, ...}), para o front-end não notar a troca.
 */
final class Resultado
{
    /**
     * @param array<string, mixed> $dados
     * @param array<string, string> $erros erros por campo, ex.: ['telefone' => 'Telefone inválido']
     */
    private function __construct(
        private bool $ok,
        private string $mensagem,
        private array $dados,
        private array $erros
    ) {
    }

    /** @param array<string, mixed> $dados */
    public static function ok(array $dados = [], string $mensagem = ''): self
    {
        return new self(true, $mensagem, $dados, []);
    }

    /**
     * @param array<string, string> $erros
     * @param array<string, mixed> $dados
     */
    public static function falha(string $mensagem, array $erros = [], array $dados = []): self
    {
        return new self(false, $mensagem, $dados, $erros);
    }

    public function sucesso(): bool
    {
        return $this->ok;
    }

    public function mensagem(): string
    {
        return $this->mensagem;
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        return $this->dados;
    }

    /** @return array<string, string> */
    public function erros(): array
    {
        return $this->erros;
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        $saida = ['success' => $this->ok];
        if ($this->mensagem !== '') {
            $saida['message'] = $this->mensagem;
        }
        if ($this->erros !== []) {
            $saida['errors'] = $this->erros;
        }
        return $saida + $this->dados;
    }

    /** Resposta HTTP: 200 quando ok, $statusFalha (422 por padrão) quando falha. */
    public function paraResponse(int $statusFalha = 422): Response
    {
        return Response::json($this->paraArray(), $this->ok ? 200 : $statusFalha);
    }
}
