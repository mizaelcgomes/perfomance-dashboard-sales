<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sessão PHP com os MESMOS parâmetros do config.php atual (cookie PHPSESSID, caminho "/", 8 h, HttpOnly, SameSite=Lax),
 * de modo que quem entrou pelo login.php antigo já está logado nas rotas novas e vice-versa.
 * Só inicia quando alguém pede (Auth, Csrf), para não mandar cookie em rota pública.
 */
final class Session
{
    private static bool $simulada = false;

    public static function iniciar(): void
    {
        if (self::$simulada || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.gc_maxlifetime', '28800');
        session_set_cookie_params([
            'lifetime' => 28800,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** @param mixed $padrao @return mixed */
    public static function obter(string $chave, $padrao = null)
    {
        self::iniciar();
        return $_SESSION[$chave] ?? $padrao;
    }

    /** @param mixed $valor */
    public static function definir(string $chave, $valor): void
    {
        self::iniciar();
        $_SESSION[$chave] = $valor;
    }

    public static function tem(string $chave): bool
    {
        self::iniciar();
        return isset($_SESSION[$chave]);
    }

    public static function esquecer(string $chave): void
    {
        self::iniciar();
        unset($_SESSION[$chave]);
    }

    /** Troca o identificador da sessão (chamar logo depois de um login). */
    public static function regenerar(): void
    {
        self::iniciar();
        if (!self::$simulada) {
            session_regenerate_id(true);
        }
    }

    /** Só para testes de linha de comando: usa um array em memória no lugar da sessão real. @param array<string, mixed> $dados */
    public static function simular(array $dados): void
    {
        self::$simulada = true;
        $_SESSION = $dados;
    }
}
