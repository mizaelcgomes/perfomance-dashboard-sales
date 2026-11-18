<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Corte e limpeza de texto UTF-8 sem depender do mbstring (a hospedagem pode não ter): as mesmas regras de cr_substr e cr_clean_text do
 * closer-lib.php, que o api.php usava nas descrições do feed de vendas.
 */
final class Texto
{
    /** Pedaço de $len letras a partir da posição $ini (UTF-8). */
    public static function cortar(string $texto, int $ini, int $len): string
    {
        return preg_match_all('/./su', $texto, $m) ? implode('', array_slice($m[0], $ini, $len)) : '';
    }

    /** Quantidade de letras (UTF-8). */
    public static function tamanho(string $texto): int
    {
        return (int) preg_match_all('/./su', $texto);
    }

    /** Sem caracteres de controle, sem espaços nas pontas e limitado a $max letras; texto inválido em UTF-8 vira ''. @param mixed $texto */
    public static function limpar($texto, int $max): string
    {
        $texto = is_scalar($texto) ? (string) $texto : '';
        $texto = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto));
        return self::cortar($texto, 0, $max);
    }
}
