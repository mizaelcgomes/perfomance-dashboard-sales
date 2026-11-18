<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;

/**
 * Regras de validação comuns aos registros da liderança (reuniões, feedbacks, cobranças, revisões e treinamentos).
 * Todo método devolve o valor limpo ou null; quem chama monta a mensagem de erro do campo. Nenhum texto é gravado com HTML:
 * a tela escapa na saída, e aqui só se tiram espaços, quebras de linha estranhas e caracteres de controle.
 */
final class RegistroValidador
{
    /** Texto de uma linha ou de várias: sem controle, sem espaços nas pontas, dentro do tamanho. null se vazio (e obrigatório) ou longo demais. */
    public static function texto($valor, int $max, bool $multilinha = false): ?string
    {
        if (!is_string($valor) && !is_numeric($valor)) {
            return '';
        }
        $t = str_replace("\r\n", "\n", (string) $valor);
        $t = preg_replace($multilinha ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', '', $t) ?? '';
        $t = trim($t);
        return self::tamanho($t) > $max ? null : $t;
    }

    /** Data AAAA-MM-DD válida (existe no calendário), ou null. */
    public static function data($valor): ?string
    {
        if (!is_string($valor) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($valor), $m)) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }

    /** Data e hora (AAAA-MM-DD HH:MM[:SS], com espaço ou "T"), válidas, ou null. Devolve AAAA-MM-DD HH:MM:SS. */
    public static function dataHora($valor): ?string
    {
        if (!is_string($valor) || !preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', trim($valor), $m)) {
            return null;
        }
        $dia = self::data($m[1]);
        if ($dia === null || (int) $m[2] > 23 || (int) $m[3] > 59 || (isset($m[4]) && (int) $m[4] > 59)) {
            return null;
        }
        return sprintf('%s %02d:%02d:%02d', $dia, $m[2], $m[3], $m[4] ?? 0);
    }

    /** A data está numa janela razoável em volta de hoje (evita o ano digitado errado): de $anosAntes até $anosDepois. */
    public static function naJanela(string $dataOuDataHora, int $anosAntes = 2, int $anosDepois = 2): bool
    {
        $dia = substr($dataOuDataHora, 0, 10);
        $hoje = Relogio::agora();
        return $dia >= $hoje->modify("-$anosAntes years")->format('Y-m-d') && $dia <= $hoje->modify("+$anosDepois years")->format('Y-m-d');
    }

    /** Quantos caracteres tem o texto (conta letra acentuada como 1, mesmo sem a extensão mbstring). */
    public static function tamanho(string $t): int
    {
        return function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : (int) preg_match_all('/./us', $t);
    }
}
