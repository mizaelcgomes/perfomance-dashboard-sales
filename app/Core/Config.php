<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Configuração em arquivos: config/app.php e config/database.php, mais um config/local.php opcional
 * (específico de cada ambiente, não vai junto com o pacote) que sobrepõe os valores.
 * Leitura por caminho com ponto: Config::obter('app.chave_teste').
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $dados = [];
    private static string $raiz = '';
    private static string $publico = '';

    public static function carregar(string $raiz, string $publico): void
    {
        self::$raiz = rtrim(str_replace('\\', '/', $raiz), '/');
        self::$publico = rtrim(str_replace('\\', '/', $publico), '/');

        $dados = [];
        foreach (['app', 'database'] as $nome) {
            $arquivo = self::$raiz . '/config/' . $nome . '.php';
            if (is_file($arquivo)) {
                $dados[$nome] = self::incluir($arquivo);
            }
        }
        $local = self::$raiz . '/config/local.php';
        if (is_file($local)) {
            $dados = array_replace_recursive($dados, self::incluir($local));
        }
        self::$dados = $dados;
    }

    /** Pasta raiz do código (a que contém app/, config/ e storage/). */
    public static function raiz(): string
    {
        return self::$raiz;
    }

    /** Pasta pública do núcleo (a que contém index.php e assets/). */
    public static function publico(): string
    {
        return self::$publico;
    }

    /** Pasta de gravação (logs, limites de tentativas, cache). Fora da pasta pública. */
    public static function storage(): string
    {
        $definida = self::obter('app.storage');
        return is_string($definida) && $definida !== '' ? rtrim($definida, '/\\') : self::$raiz . '/storage';
    }

    /** @param mixed $padrao */
    public static function obter(string $caminho, $padrao = null)
    {
        $atual = self::$dados;
        foreach (explode('.', $caminho) as $parte) {
            if (!is_array($atual) || !array_key_exists($parte, $atual)) {
                return $padrao;
            }
            $atual = $atual[$parte];
        }
        return $atual;
    }

    /** Só para testes: troca um valor em memória. @param mixed $valor */
    public static function definir(string $caminho, $valor): void
    {
        $partes = explode('.', $caminho);
        $alvo = &self::$dados;
        foreach ($partes as $parte) {
            if (!isset($alvo[$parte]) || !is_array($alvo[$parte])) {
                $alvo[$parte] = [];
            }
            $alvo = &$alvo[$parte];
        }
        $alvo = $valor;
    }

    /** @return array<string, mixed> */
    private static function incluir(string $arquivo): array
    {
        $raiz = self::$raiz;
        $publico = self::$publico;
        $resultado = (static function () use ($arquivo, $raiz, $publico) {
            return require $arquivo;   // os arquivos de config enxergam $raiz e $publico
        })();
        return is_array($resultado) ? $resultado : [];
    }
}
