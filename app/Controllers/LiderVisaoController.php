<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\LiderVisaoService;

/**
 * Visão do time do líder (somente leitura). A equipe vem SEMPRE do middleware auth:lider (parâmetro equipe_id), nunca da requisição:
 * um líder não consegue pedir os dados de outra equipe.
 */
final class LiderVisaoController
{
    public function __construct(private LiderVisaoService $visoes)
    {
    }

    /** Parâmetros: periodo (hoje | semana | mes | custom), from e to (para custom). @param array<string, string> $p */
    public function visao(Request $r, array $p = []): Response
    {
        $dados = $this->visoes->visao(
            (int) ($p['equipe_id'] ?? 0),
            (string) $r->entrada('periodo', 'hoje'),
            (string) $r->entrada('from', ''),
            (string) $r->entrada('to', '')
        );
        return Response::json(['success' => true] + $dados);
    }
}
