<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Relatorio\GerarPdfRelatorio;
use App\Core\Relogio;
use App\Core\Request;
use App\Core\Response;
use App\Services\CustoPorContratoService;
use App\Services\RelatorioService;
use App\Services\ResumoMensalService;

/**
 * Relatórios do admin: Performance de Closers (tela, CSV e PDF), Custo por Contrato e a Visão do Time do mês.
 * Cada método lê a entrada, chama o Service (ou a Action) e devolve a resposta no mesmo formato JSON que o api.php sempre devolveu.
 * Chega aqui por duas portas: as rotas novas (/api/relatorios/..., com login pelo middleware) e as URLs antigas do api.php
 * (api.php?action=get_relatorio_closers etc., que checam o login e chamam estes mesmos métodos pela ponte).
 */
final class RelatorioController
{
    public function __construct(
        private RelatorioService $relatorio,
        private CustoPorContratoService $custo,
        private ResumoMensalService $resumo,
        private GerarPdfRelatorio $gerarPdf
    ) {
    }

    /** Performance de Closers de um mês: todas as semanas e o mix de benefícios de uma vez só (trocar de semana na tela não chama o servidor). @param array<string, string> $p */
    public function closers(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true] + $this->relatorio->closers((int) $r->query('ano', 0), (int) $r->query('mes', 0)));
    }

    /** Custo por Contrato: contratos, investimento e CAC do mês, mais a série diária acumulada. @param array<string, string> $p */
    public function custoContrato(Request $r, array $p = []): Response
    {
        return Response::json($this->custo->mes((int) $r->query('ano', 0), (int) $r->query('mes', 0)));
    }

    /** PDF da Performance de Closers (download). Parâmetros: ano, mes e equipe (opcional). @param array<string, string> $p */
    public function closersPdf(Request $r, array $p = []): Response
    {
        $resultado = $this->gerarPdf->executar((int) $r->query('ano', 0), (int) $r->query('mes', 0), trim((string) $r->query('equipe', '')));
        if (!$resultado->sucesso()) {
            return $resultado->paraResponse(500);
        }
        $bytes = (string) $resultado->dados()['pdf'];
        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $resultado->dados()['nome'] . '"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Tabela "Visão do Time — Mês" (uma coluna por dia do mês). Parâmetros: year, month e include_deleted. @param array<string, string> $p */
    public function resumoMensal(Request $r, array $p = []): Response
    {
        $agora = Relogio::agora();
        $dados = $this->resumo->mes(
            (int) $r->query('year', $agora->format('Y')),
            (int) $r->query('month', $agora->format('m')),
            !empty($r->query('include_deleted'))
        );
        return Response::json(['success' => true] + $dados);
    }
}
