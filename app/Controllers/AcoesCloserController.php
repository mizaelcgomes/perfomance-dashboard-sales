<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ContaCloserRepository;
use App\Services\AcoesCloserService;
use App\Services\ContratosCloserService;

/**
 * As ações do PRÓPRIO closer (portal): resumo, histórico, registro, edição, exclusão, evolução e os contratos (Meus contratos). O closer vem SEMPRE da conta da sessão
 * (nunca de um parâmetro). As respostas são as do closer-api.php de antes: mesmo corpo, "field" nos erros de formulário e 404 quando a ação
 * não é do closer.
 */
final class AcoesCloserController
{
    public function __construct(private AcoesCloserService $acoes, private ContaCloserRepository $contas, private ContratosCloserService $contratos)
    {
    }

    /** O closer (vendedor) da conta logada, ou null se a conta não está ativa ou não tem vínculo. */
    private function closer(): ?int
    {
        return $this->contas->vendedorDaConta((int) Session::obter('closer_id', 0));
    }

    private function semConta(): Response
    {
        return Response::json(['success' => false, 'message' => 'Acesso restrito a closers com conta ativa.'], 403);
    }

    /** O corpo de sucesso, ou o erro no formato do portal: success false, message e, se for de um campo, "field". @param array<string, mixed> $d */
    private function responder(array $d): Response
    {
        if (!isset($d['erro'])) {
            return Response::json(['success' => true] + $d);
        }
        $corpo = ['success' => false, 'message' => $d['erro']];
        if (!empty($d['campo'])) {
            $corpo['field'] = $d['campo'];
        }
        return Response::json($corpo, (int) $d['status']);
    }

    /** @param array<string, string> $p */
    public function resumo(Request $r, array $p = []): Response
    {
        $vid = $this->closer();
        return $vid === null ? $this->semConta() : $this->responder($this->acoes->resumo($vid));
    }

    /** @param array<string, string> $p */
    public function listar(Request $r, array $p = []): Response
    {
        $vid = $this->closer();
        if ($vid === null) {
            return $this->semConta();
        }
        $filtros = [];
        foreach (['tipo', 'periodo', 'data', 'q', 'limit', 'offset'] as $chave) {
            $filtros[$chave] = $r->entrada($chave);
        }
        return $this->responder($this->acoes->listar($vid, $filtros));
    }

    /** @param array<string, string> $p */
    public function obter(Request $r, array $p = []): Response
    {
        $vid = $this->closer();
        return $vid === null ? $this->semConta() : $this->responder($this->acoes->obter($vid, (int) $r->entrada('id', 0)));
    }

    /** @param array<string, string> $p */
    public function criar(Request $r, array $p = []): Response
    {
        $vid = $this->closer();
        return $vid === null ? $this->semConta() : $this->responder($this->acoes->criar($vid, $this->dadosDaAcao($r)));
    }

    /** @param array<string, string> $p */
    public function editar(Request $r, array $p = []): Response
    {
        $vid = $this->closer();
        return $vid === null ? $this->semConta() : $this->responder($this->acoes->editar($vid, $this->dadosDaAcao($r) + ['id' => $r->entrada('id', 0)]));
    }

    /** @param array<string, string> $p */
    public function excluir(Request $r, array $p = []): Response
    {
        $vid = $this->closer();
        return $vid === null ? $this->semConta() : $this->responder($this->acoes->excluir($vid, (int) $r->entrada('id', 0)));
    }

    /** @param array<string, string> $p */
    public function evolucao(Request $r, array $p = []): Response
    {
        $vid = $this->closer();
        return $vid === null ? $this->semConta() : $this->responder($this->acoes->evolucao($vid));
    }

    /** Meus contratos: evolução, benefícios e meta de Hoje, Semana ou Mês (parâmetros periodo, ano, mes e semana). @param array<string, string> $p */
    public function contratos(Request $r, array $p = []): Response
    {
        $vid = $this->closer();
        if ($vid === null) {
            return $this->semConta();
        }
        $filtros = [];
        foreach (['periodo', 'ano', 'mes', 'semana'] as $chave) {
            $filtros[$chave] = $r->entrada($chave);
        }
        return $this->responder($this->contratos->contratos($vid, $filtros));
    }

    /** @return array<string, mixed> */
    private function dadosDaAcao(Request $r): array
    {
        $dados = [];
        foreach (['telefone', 'tipo', 'observacao', 'registrado_em'] as $chave) {
            $dados[$chave] = $r->entrada($chave);
        }
        return $dados;
    }
}
