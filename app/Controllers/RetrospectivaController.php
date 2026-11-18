<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\RetrospectivaService;

/** Retrospectiva da TV ("Hall da Fama"): leitura pública, como o ranking ao vivo. */
final class RetrospectivaController
{
    public function __construct(private RetrospectivaService $retrospectiva)
    {
    }

    /** Closers e melhor equipe da semana anterior e do mês anterior. @param array<string, string> $p */
    public function ver(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true] + $this->retrospectiva->retrospectiva());
    }
}
