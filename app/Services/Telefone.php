<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Telefone do cliente, com as MESMAS regras do portal do closer (cr_phone_digits e cr_phone_valid, em closer-lib.php): só dígitos,
 * sem o +55, 10 ou 11 dígitos, DDD de 11 a 99, celular começa com 9, fixo começa com 2 a 9, e número com todos os dígitos iguais é recusado.
 */
final class Telefone
{
    public static function digitos($valor): string
    {
        $d = preg_replace('/\D+/', '', (string) $valor) ?? '';
        if (strlen($d) > 11 && strpos($d, '55') === 0) {
            $d = substr($d, 2);
        }
        return $d;
    }

    public static function valido(string $d): bool
    {
        $l = strlen($d);
        if ($l !== 10 && $l !== 11) {
            return false;
        }
        $ddd = (int) substr($d, 0, 2);
        if ($ddd < 11 || $ddd > 99) {
            return false;
        }
        if ($l === 11 && $d[2] !== '9') {
            return false;
        }
        if ($l === 10 && ($d[2] === '0' || $d[2] === '1')) {
            return false;
        }
        return preg_match('/^(\d)\1+$/', substr($d, 2)) !== 1;
    }

    /** (11) 98765-4321 */
    public static function formatar(string $d): string
    {
        return strlen($d) === 11
            ? '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7)
            : (strlen($d) === 10 ? '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6) : $d);
    }

    /** (11) 9****-1234: o histórico nunca mostra o número inteiro. Mesma regra do cr_phone_mask do closer-lib.php. */
    public static function mascarar(string $d): string
    {
        $ddd = substr($d, 0, 2);
        $fim = substr($d, -4);
        return strlen($d) === 11 ? "($ddd) 9****-$fim" : "($ddd) ****-$fim";
    }
}
