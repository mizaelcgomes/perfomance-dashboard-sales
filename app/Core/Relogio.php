<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * "Agora" do sistema, SEMPRE em America/Sao_Paulo (o mesmo fuso que o api.php atual usa de forma explícita), sem depender do fuso
 * padrão do PHP (que é America/Fortaleza no servidor e UTC no ambiente local de teste). Nos testes dá para congelar a data.
 */
final class Relogio
{
    public const FUSO = 'America/Sao_Paulo';

    private static ?string $fixo = null;

    public static function fuso(): DateTimeZone
    {
        return new DateTimeZone(self::FUSO);
    }

    public static function agora(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::$fixo ?? 'now', self::fuso());
    }

    /** Dia de hoje, "Y-m-d". */
    public static function hoje(): string
    {
        return self::agora()->format('Y-m-d');
    }

    /** Só para testes: congela o "agora" (ex.: '2026-11-05 10:00:00', em America/Sao_Paulo). null volta a usar o relógio real. */
    public static function fixar(?string $dataHora): void
    {
        self::$fixo = $dataHora;
    }
}
