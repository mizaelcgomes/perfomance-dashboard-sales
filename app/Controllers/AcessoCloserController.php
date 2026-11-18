<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AcessoCloserService;

/**
 * Gestão dos acessos ao portal do closer pelo ADMIN (aba Equipes). O login do admin é conferido antes (porta fina do api.php ou middleware da rota).
 * As respostas são as do api.php de antes: success true com o corpo, ou success false, message e, se for de um campo do formulário, "field" (HTTP 200).
 */
final class AcessoCloserController
{
    public function __construct(private AcessoCloserService $acessos)
    {
    }

    /** @param array<string, mixed> $d */
    private function responder(array $d): Response
    {
        if (!isset($d['erro'])) {
            return Response::json(['success' => true] + $d);
        }
        $corpo = ['success' => false, 'message' => $d['erro']];
        if (!empty($d['campo'])) {
            $corpo['field'] = $d['campo'];
        }
        return Response::json($corpo);
    }

    /** @param array<string, string> $p */
    public function listar(Request $r, array $p = []): Response
    {
        return $this->responder($this->acessos->listar());
    }

    /** @param array<string, string> $p */
    public function aprovar(Request $r, array $p = []): Response
    {
        return $this->responder($this->acessos->aprovar((int) $r->entrada('id', 0), (int) $r->entrada('vendedor_id', 0), !empty($r->entrada('novo'))));
    }

    /** @param array<string, string> $p */
    public function editar(Request $r, array $p = []): Response
    {
        return $this->responder($this->acessos->editar((int) $r->entrada('id', 0), $r->entrada('nome', ''), $r->entrada('email', ''), (int) $r->entrada('equipe_id', 0)));
    }

    /** @param array<string, string> $p */
    public function rejeitar(Request $r, array $p = []): Response
    {
        return $this->responder($this->acessos->rejeitar((int) $r->entrada('id', 0), $r->entrada('motivo', '')));
    }

    /** @param array<string, string> $p */
    public function revogar(Request $r, array $p = []): Response
    {
        return $this->responder($this->acessos->revogar((int) $r->entrada('id', 0)));
    }

    /** @param array<string, string> $p */
    public function reconsiderar(Request $r, array $p = []): Response
    {
        return $this->responder($this->acessos->reconsiderar((int) $r->entrada('id', 0)));
    }

    /** @param array<string, string> $p */
    public function excluir(Request $r, array $p = []): Response
    {
        return $this->responder($this->acessos->excluir((int) $r->entrada('id', 0)));
    }

    /** @param array<string, string> $p */
    public function convite(Request $r, array $p = []): Response
    {
        return $this->responder($this->acessos->convite((int) $r->entrada('vendedor_id', 0)));
    }

    /** @param array<string, string> $p */
    public function tokenDeSenha(Request $r, array $p = []): Response
    {
        return $this->responder($this->acessos->tokenDeSenha((int) $r->entrada('id', 0)));
    }
}
