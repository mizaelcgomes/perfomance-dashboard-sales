<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AutenticacaoCloserService;

/**
 * As ações PÚBLICAS do portal do closer: cadastro, login, pedido e uso do token de redefinição de senha, e logout. As respostas são as do
 * closer-api.php de antes: success true com o corpo, ou success false, message e, se for de um campo do formulário, "field"; "code" quando a tela
 * escolhe o aviso por ele (pendente, rejeitado, equipe_oculta, desativado, email_existe). O token CSRF do portal é conferido antes (rota ou porta fina).
 */
final class AutenticacaoCloserController
{
    public function __construct(private AutenticacaoCloserService $autenticacao)
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
        if (!empty($d['code'])) {
            $corpo['code'] = $d['code'];
        }
        return Response::json($corpo, (int) $d['status']);
    }

    /** @return array<string, mixed> */
    private function corpo(Request $r): array
    {
        $c = $r->corpo();
        return is_array($c) ? $c : [];
    }

    /** @param array<string, string> $p */
    public function registrar(Request $r, array $p = []): Response
    {
        return $this->responder($this->autenticacao->registrar($this->corpo($r), $r->ip()));
    }

    /** @param array<string, string> $p */
    public function entrar(Request $r, array $p = []): Response
    {
        return $this->responder($this->autenticacao->entrar($this->corpo($r), $r->ip()));
    }

    /** @param array<string, string> $p */
    public function pedirRedefinicao(Request $r, array $p = []): Response
    {
        return $this->responder($this->autenticacao->pedirRedefinicao($this->corpo($r), $r->ip()));
    }

    /** @param array<string, string> $p */
    public function redefinir(Request $r, array $p = []): Response
    {
        return $this->responder($this->autenticacao->redefinir($this->corpo($r)));
    }

    /** @param array<string, string> $p */
    public function sair(Request $r, array $p = []): Response
    {
        return $this->responder($this->autenticacao->sair());
    }
}
