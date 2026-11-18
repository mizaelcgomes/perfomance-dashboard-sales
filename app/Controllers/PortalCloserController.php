<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\InteracoesCloserService;
use App\Services\PortalCloserService;
use App\Services\TabelasDoLider;
use Throwable;

/**
 * O portal do closer (closer.php): "Meu dia" (meta, ranking e evolução), a página Ranking e "Minhas Interações" (feedbacks, cobranças, revisões,
 * reuniões e treinamentos, com a resposta do closer). O closer vem SEMPRE da conta da sessão, nunca de um parâmetro: ninguém lê nem responde o que
 * é de outro closer. Se as tabelas do módulo do líder ainda não existem (a migração não foi rodada), as interações respondem "nada" e a tela avisa
 * que está em preparação; o resto do portal segue normal.
 */
final class PortalCloserController
{
    public function __construct(private PortalCloserService $portal, private InteracoesCloserService $interacoes)
    {
    }

    private function conta(): int
    {
        return (int) Session::obter('closer_id', 0);
    }

    private function semConta(): Response
    {
        return Response::json(['success' => false, 'message' => 'Acesso restrito a closers com conta ativa.'], 403);
    }

    /** @param array<string, string> $p */
    public function meuDia(Request $r, array $p = []): Response
    {
        $d = $this->portal->meuDia($this->conta());
        return $d === null ? $this->semConta() : Response::json(['success' => true] + $d);
    }

    /** @param array<string, string> $p */
    public function ranking(Request $r, array $p = []): Response
    {
        $d = $this->portal->ranking($this->conta(), (string) $r->entrada('periodo', 'semana'));
        if ($d === null) {
            return $this->semConta();
        }
        return isset($d['erro']) ? Response::json(['success' => false, 'message' => $d['erro']]) : Response::json(['success' => true] + $d);
    }

    /** Quantas interações novas há (a bolinha do menu e do sino). @param array<string, string> $p */
    public function contagens(Request $r, array $p = []): Response
    {
        try {
            $d = $this->interacoes->contagens($this->conta());
        } catch (Throwable $erro) {
            return $this->semTabelas($erro, ['feedback' => 0, 'cobranca' => 0, 'revisao' => 0, 'total' => 0]);
        }
        return $d === null ? $this->semConta() : Response::json(['success' => true] + $d);
    }

    /** Os itens de um tipo de interação (parâmetro "tipo"). @param array<string, string> $p */
    public function interacoes(Request $r, array $p = []): Response
    {
        try {
            $d = $this->interacoes->listar($this->conta(), (string) $r->entrada('tipo', 'feedback'));
        } catch (Throwable $erro) {
            return $this->semTabelas($erro, ['tipo' => (string) $r->entrada('tipo', 'feedback'), 'itens' => [], 'pode_responder' => false, 'maximo' => InteracoesCloserService::MAX_RESPOSTA]);
        }
        if ($d === null) {
            return $this->semConta();
        }
        return isset($d['erro']) ? Response::json(['success' => false, 'message' => $d['erro']]) : Response::json(['success' => true] + $d);
    }

    /** O closer abriu um item: marca como visto (parâmetros "tipo" e "id"). @param array<string, string> $p */
    public function interacaoVista(Request $r, array $p = []): Response
    {
        try {
            $d = $this->interacoes->marcarVisto($this->conta(), (string) $r->corpo('tipo', ''), (int) $r->corpo('id', 0));
        } catch (Throwable $erro) {
            return $this->semTabelas($erro, ['marcados' => 0]);
        }
        return $d === null ? $this->semConta() : Response::json(['success' => true] + $d);
    }

    /** A resposta do closer (parâmetros "tipo", "id" e "texto"). @param array<string, string> $p */
    public function interacaoResponder(Request $r, array $p = []): Response
    {
        $texto = $r->corpo('texto', '');
        try {
            $d = $this->interacoes->responder($this->conta(), (string) $r->corpo('tipo', ''), (int) $r->corpo('id', 0), is_string($texto) ? $texto : '');
        } catch (Throwable $erro) {
            if (TabelasDoLider::ausente($erro)) {
                return Response::json(['success' => false, 'message' => 'As respostas ainda não estão disponíveis. Fale com o administrador.']);
            }
            throw $erro;
        }
        return $d === null ? $this->semConta() : Response::json($d);
    }

    /** @param array<string, mixed> $vazio resposta de "nada" para o caso de as tabelas não existirem; qualquer outro erro segue adiante */
    private function semTabelas(Throwable $erro, array $vazio): Response
    {
        if (!TabelasDoLider::ausente($erro)) {
            throw $erro;
        }
        return Response::json(['success' => true, 'migracao_pendente' => true] + $vazio);
    }
}
