<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Autoloader PSR-4 próprio (sem Composer): a classe App\Foo\Bar vem de app/Foo/Bar.php.
 * É o único arquivo que o front controller carrega "na mão"; todo o resto é carregado sob demanda.
 */
final class Autoloader
{
    public static function registrar(string $diretorioBase, string $prefixo = 'App\\'): void
    {
        $diretorioBase = rtrim($diretorioBase, '/\\') . DIRECTORY_SEPARATOR;
        $tamanho = strlen($prefixo);

        spl_autoload_register(static function (string $classe) use ($diretorioBase, $prefixo, $tamanho): void {
            if (strncmp($classe, $prefixo, $tamanho) !== 0) {
                return;
            }
            $arquivo = $diretorioBase . str_replace('\\', DIRECTORY_SEPARATOR, substr($classe, $tamanho)) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        });
    }
}
