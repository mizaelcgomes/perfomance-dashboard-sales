<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\MeusFeedbacksService;
use App\Services\TabelasDoLider;
use Throwable;

/**
 * "Meus feedbacks" do portal do closer: o que o líder registrou PARA o closer logado (feedbacks e cobranças de cadastramento).
 * O closer vem da sessão (conta ativa), nunca de um parâmetro: ninguém lê o que outro closer recebeu.
 * Se as tabelas do módulo ainda não existem (a migração não foi rodada), responde "nada recebido": o closer não precisa saber disso e o
 * portal dele segue normal.
 */
final class MeusFeedbacksController
{
    public function __construct(private MeusFeedbacksService $meus)
    {
    }

    /** @param array<string, string> $p */
    public function listar(Request $r, array $p = []): Response
    {
        try {
            $dados = $this->meus->listar((int) Session::obter('closer_id', 0));
        } catch (Throwable $erro) {
            return $this->semTabelas($erro, ['itens' => [], 'novos' => 0]);
        }
        if ($dados === null) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito a closers com conta ativa.'], 403);
        }
        return Response::json(['success' => true] + $dados);
    }

    /** Só o contador de novos (leve, para o botão do portal). @param array<string, string> $p */
    public function novos(Request $r, array $p = []): Response
    {
        try {
            return Response::json(['success' => true, 'novos' => $this->meus->contarNovos((int) Session::obter('closer_id', 0))]);
        } catch (Throwable $erro) {
            return $this->semTabelas($erro, ['novos' => 0]);
        }
    }

    /** O closer abriu a seção: marca tudo como visto. @param array<string, string> $p */
    public function vistos(Request $r, array $p = []): Response
    {
        try {
            return Response::json(['success' => true, 'marcados' => $this->meus->marcarVistos((int) Session::obter('closer_id', 0))]);
        } catch (Throwable $erro) {
            return $this->semTabelas($erro, ['marcados' => 0]);
        }
    }

    /** @param array<string, mixed> $vazio resposta de "nada" para o caso de as tabelas não existirem; qualquer outro erro segue adiante */
    private function semTabelas(Throwable $erro, array $vazio): Response
    {
        if (!TabelasDoLider::ausente($erro)) {
            throw $erro;
        }
        return Response::json(['success' => true] + $vazio);
    }
}
