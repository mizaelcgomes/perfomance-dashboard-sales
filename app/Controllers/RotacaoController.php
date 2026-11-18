<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Rotacao\SalvarRotacao;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RotacaoRepository;

/** Rotação do painel da TV: a TV lê (público); o admin salva a lista (exige login). */
final class RotacaoController
{
    public function __construct(private RotacaoRepository $rotacao, private SalvarRotacao $salvar)
    {
    }

    /** @param array<string, string> $p */
    public function ver(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'data' => $this->rotacao->itens()]);
    }

    /** Campo: rotation (a lista em JSON). @param array<string, string> $p */
    public function salvar(Request $r, array $p = []): Response
    {
        return $this->salvar->executar((string) $r->corpo('rotation', '[]'))->paraResponse(200);
    }
}
