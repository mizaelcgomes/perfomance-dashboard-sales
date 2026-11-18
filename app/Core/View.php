<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Renderizador de templates PHP: app/Views/<nome>.php dentro de um layout (por padrão layouts/main).
 * Dentro de um template, $v é este objeto: $v->e() escapa texto, $v->parcial() inclui um pedaço reutilizável
 * (cabeçalho, rodapé, card) e $v->asset() monta o endereço de CSS/JS com a data do arquivo, para o navegador
 * nunca usar uma cópia antiga depois de você subir um arquivo novo.
 */
final class View
{
    public function __construct(
        private string $diretorioViews,
        private string $diretorioPublico,
        private string $base = ''
    ) {
    }

    /** @param array<string, mixed> $dados */
    public function renderizar(string $nome, array $dados = [], ?string $layout = 'layouts/main'): string
    {
        $conteudo = $this->incluir($nome, $dados);
        if ($layout === null) {
            return $conteudo;
        }
        return $this->incluir($layout, $dados + ['conteudo' => $conteudo]);
    }

    /** @param array<string, mixed> $dados */
    public function parcial(string $nome, array $dados = []): string
    {
        return $this->incluir($nome, $dados);
    }

    /** Escapa texto para HTML (texto e atributos). @param mixed $valor */
    public function e($valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** /assets/css/base.css?v=1730000000 (data de modificação do arquivo). */
    public function asset(string $caminho): string
    {
        $caminho = ltrim(str_replace('\\', '/', $caminho), '/');
        if (strpos($caminho, '..') !== false) {
            throw new InvalidArgumentException('Caminho de asset inválido.');
        }
        $arquivo = rtrim($this->diretorioPublico, '/\\') . '/assets/' . $caminho;
        $versao = is_file($arquivo) ? (int) filemtime($arquivo) : 0;
        return $this->base . '/assets/' . $caminho . '?v=' . $versao;
    }

    /** Endereço de uma rota do site, a partir de onde o núcleo está montado ('' na raiz): url('/api') => /api. */
    public function url(string $caminho): string
    {
        return $this->base . '/' . ltrim($caminho, '/');
    }

    /** @param array<string, mixed> $dados */
    private function incluir(string $nome, array $dados): string
    {
        if (!preg_match('#^[a-z0-9_\-/]+$#i', $nome) || strpos($nome, '..') !== false) {
            throw new InvalidArgumentException('Nome de view inválido: ' . $nome);
        }
        $arquivo = rtrim($this->diretorioViews, '/\\') . '/' . $nome . '.php';
        if (!is_file($arquivo)) {
            throw new InvalidArgumentException('View não encontrada: ' . $nome);
        }
        $v = $this;
        ob_start();
        try {
            (static function () use ($arquivo, $dados, $v): void {
                extract($dados, EXTR_SKIP);
                include $arquivo;
            })();
        } catch (\Throwable $erro) {
            ob_end_clean();
            throw $erro;
        }
        return (string) ob_get_clean();
    }
}
