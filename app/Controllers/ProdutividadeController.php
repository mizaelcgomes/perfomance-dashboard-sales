<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProdutividadeService;

/**
 * Produtividade do admin: o funil e o ranking do time, o detalhe de um closer e o histórico de ações dele. Só leitura; exige o login do admin
 * (middleware nas rotas novas; o api.php confere antes de chamar pela ponte). A seção Líderes e as Atividades dos líderes têm Controllers próprios.
 */
final class ProdutividadeController
{
    public function __construct(private ProdutividadeService $produtividade)
    {
    }

    /** Parâmetros: periodo (hoje|semana|mes|custom), from, to, equipe_id e closer_id. @param array<string, string> $p */
    public function dados(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true] + $this->produtividade->dados(
            (string) $r->query('periodo', 'hoje'), (string) $r->query('from', ''), (string) $r->query('to', ''),
            (int) $r->query('equipe_id', 0), (int) $r->query('closer_id', 0)
        ));
    }

    /** Evolução e últimas ações de um closer: closer_id e, opcionais, ano e mes. @param array<string, string> $p */
    public function closer(Request $r, array $p = []): Response
    {
        return Response::json($this->produtividade->detalheDoCloser((int) $r->query('closer_id', 0), $r->query('ano'), $r->query('mes')));
    }

    /** O histórico de ações de um closer: closer_id, tipo, from, to, limit e offset. @param array<string, string> $p */
    public function acoes(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true] + $this->produtividade->historicoDeAcoes((int) $r->query('closer_id', 0), $r->query()));
    }
}
