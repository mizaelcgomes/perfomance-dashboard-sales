<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\LiderVisaoService;

/** Seção Líderes da Produtividade (admin): uma linha por equipe, com o líder e as métricas do período. Exige o login do admin. */
final class LiderAdminController
{
    public function __construct(private LiderVisaoService $visoes)
    {
    }

    /** Parâmetros: periodo (hoje | semana | mes | custom), from e to (para custom), os mesmos da aba Produtividade. @param array<string, string> $p */
    public function lideres(Request $r, array $p = []): Response
    {
        $dados = $this->visoes->resumoPorEquipe(
            (string) $r->entrada('periodo', 'hoje'),
            (string) $r->entrada('from', ''),
            (string) $r->entrada('to', '')
        );
        return Response::json(['success' => true, 'equipes' => $dados]);
    }
}
