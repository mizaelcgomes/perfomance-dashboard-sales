<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AtividadeLiderService;
use App\Services\TabelasDoLider;
use Throwable;

/**
 * "Atividades dos líderes" da Produtividade (admin), só leitura. Exige o login do admin (rota auth:admin e a ponte do api.php).
 * Se as tabelas do módulo ainda não existem, responde 503 com a instrução de rodar a migração (em vez de um erro genérico).
 */
final class AtividadeLiderController
{
    public function __construct(private AtividadeLiderService $atividades)
    {
    }

    /** Parâmetros: periodo (hoje | semana | mes | custom), from e to (para custom) e equipe_id (opcional), como na aba Produtividade. @param array<string, string> $p */
    public function resumo(Request $r, array $p = []): Response
    {
        try {
            $dados = $this->atividades->resumo(
                (string) $r->entrada('periodo', 'hoje'),
                (string) $r->entrada('from', ''),
                (string) $r->entrada('to', ''),
                (int) $r->entrada('equipe_id', 0)
            );
        } catch (Throwable $erro) {
            return $this->tratar($erro);
        }
        return Response::json(['success' => true] + $dados);
    }

    /** Os últimos registros do líder de uma equipe. A equipe vem da rota ({equipe}) ou do parâmetro equipe_id. @param array<string, string> $p */
    public function detalhe(Request $r, array $p = []): Response
    {
        try {
            $dados = $this->atividades->detalhe((int) ($p['equipe'] ?? $r->entrada('equipe_id', 0)));
        } catch (Throwable $erro) {
            return $this->tratar($erro);
        }
        if ($dados === null) {
            return Response::json(['success' => false, 'message' => 'Equipe não encontrada.'], 404);
        }
        return Response::json(['success' => true] + $dados);
    }

    /** Tabelas do módulo ainda não criadas: 503 com a instrução; qualquer outro erro segue para o tratamento padrão. */
    private function tratar(Throwable $erro): Response
    {
        if (!TabelasDoLider::ausente($erro)) {
            throw $erro;
        }
        return Response::json(['success' => false, 'code' => 'migracao', 'message' => TabelasDoLider::MENSAGEM], 503);
    }
}
