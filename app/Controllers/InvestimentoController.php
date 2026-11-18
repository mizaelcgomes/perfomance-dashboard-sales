<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Investimento\ExcluirInvestimentoMensal;
use App\Actions\Investimento\SalvarInvestimentoDoMes;
use App\Actions\Investimento\SalvarInvestimentoMensal;
use App\Core\Request;
use App\Core\Response;
use App\Services\InvestimentoService;

/**
 * A aba Investimento do admin: o histórico mensal (investimento digitado à mão, CAC e contratos por dia) e as gravações. Tudo exige o login do
 * admin (middleware nas rotas novas; o api.php confere antes de chamar pela ponte). Falhas de validação respondem com HTTP 200 e
 * {success:false, message}, como o api.php sempre fez (o admin.php depende disso).
 */
final class InvestimentoController
{
    public function __construct(
        private InvestimentoService $investimento,
        private SalvarInvestimentoMensal $salvarMensal,
        private ExcluirInvestimentoMensal $excluirMensal,
        private SalvarInvestimentoDoMes $salvarDoMes
    ) {
    }

    /** @param array<string, string> $p */
    public function historico(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true] + $this->investimento->historico());
    }

    /** @param array<string, string> $p */
    public function salvarMensal(Request $r, array $p = []): Response
    {
        return $this->salvarMensal->executar(
            $r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('investimento', 0), $r->corpo('alcance', 0),
            $r->corpo('impressoes', 0), $r->corpo('cpm', 0), $r->corpo('frequencia', 0), $r->corpo('obs', '')
        )->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function excluirMensal(Request $r, array $p = []): Response
    {
        return $this->excluirMensal->executar($r->corpo('ano', 0), $r->corpo('mes', 0))->paraResponse(200);
    }

    /** O valor avulso do investimento do mês corrente (campo "valor"). @param array<string, string> $p */
    public function salvarDoMes(Request $r, array $p = []): Response
    {
        return $this->salvarDoMes->executar($r->corpo('valor', 0))->paraResponse(200);
    }
}
