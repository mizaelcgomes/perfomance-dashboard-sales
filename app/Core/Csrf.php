<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Token CSRF do admin. Usa a MESMA chave de sessão (csrf_admin) e as mesmas regras do config.php atual
 * (csrfToken / csrfValido), então o token que o admin.php já injeta nas páginas vale também para as rotas novas.
 * O portal do Closer tem token próprio (cr_csrf) e entra na fase 6.
 */
final class Csrf
{
    private const CHAVE = 'csrf_admin';

    public static function token(): string
    {
        $atual = Session::obter(self::CHAVE);
        if (!is_string($atual) || $atual === '') {
            $atual = bin2hex(random_bytes(32));
            Session::definir(self::CHAVE, $atual);
        }
        return $atual;
    }

    /** O token do portal do closer (chave de sessão cr_csrf, a mesma de sempre): criado na primeira vez e repetido depois. */
    public static function tokenCloser(): string
    {
        $atual = Session::obter('cr_csrf');
        if (!is_string($atual) || $atual === '') {
            $atual = bin2hex(random_bytes(32));
            Session::definir('cr_csrf', $atual);
        }
        return $atual;
    }

    /** Token do portal do closer: a mesma chave de sessão (cr_csrf) e a mesma regra do closer-lib.php (cr_csrf_ok), só pelo cabeçalho. */
    public static function validoCloser(Request $requisicao): bool
    {
        $esperado = Session::obter('cr_csrf');
        $enviado = $requisicao->cabecalho('X-CSRF-Token') ?? '';
        return is_string($esperado) && $esperado !== '' && $enviado !== '' && hash_equals($esperado, $enviado);
    }

    /** O token vem no cabeçalho X-CSRF-Token ou no campo _csrf do corpo. */
    public static function valido(Request $requisicao): bool
    {
        $esperado = Session::obter(self::CHAVE);
        if (!is_string($esperado) || $esperado === '') {
            return false;
        }
        $enviado = $requisicao->cabecalho('X-CSRF-Token') ?? $requisicao->corpo('_csrf', '');
        return is_string($enviado) && $enviado !== '' && hash_equals($esperado, $enviado);
    }
}
