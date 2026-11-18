<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Calendario\ExcluirSemanasMes;
use App\Actions\Calendario\SalvarDiasDaSemana;
use App\Actions\Calendario\SalvarSemanasMes;
use App\Core\Relogio;
use App\Core\Request;
use App\Core\Response;
use App\Services\CalendarioService;

/**
 * A aba Calendário do admin: as semanas de cada mês e a quantidade de dias do ciclo. As LEITURAS são públicas (a TV e o Painel também as
 * usam, sem login); as gravações exigem o login do admin (middleware nas rotas novas; o api.php confere antes de chamar pela ponte).
 * Falhas de validação respondem com HTTP 200 e {success:false, message}, como o api.php sempre fez.
 */
final class CalendarioController
{
    public function __construct(
        private CalendarioService $calendario,
        private SalvarSemanasMes $salvarSemanas,
        private ExcluirSemanasMes $excluirSemanas,
        private SalvarDiasDaSemana $salvarDias
    ) {
    }

    /** As semanas CONFIGURADAS do mês (sem cálculo automático). @param array<string, string> $p */
    public function semanasMes(Request $r, array $p = []): Response
    {
        [$ano, $mes] = $this->anoMes($r);
        return Response::json(['success' => true, 'data' => $this->calendario->configuradas($ano, $mes), 'ano' => $ano, 'mes' => $mes]);
    }

    /** As semanas do mês com as datas de cada dia: as configuradas ou, sem configuração, as automáticas. @param array<string, string> $p */
    public function semanasDoMes(Request $r, array $p = []): Response
    {
        [$ano, $mes] = $this->anoMes($r);
        if ($mes < 1 || $mes > 12) {
            return Response::json(['success' => false, 'message' => 'Mês inválido']);
        }
        $semanas = $this->calendario->doMes($ano, $mes);
        return Response::json(['success' => true, 'data' => $semanas['data'], 'ano' => $ano, 'mes' => $mes, 'configured' => $semanas['configured']]);
    }

    /** @param array<string, string> $p */
    public function historico(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'data' => $this->calendario->historico()]);
    }

    /** @param array<string, string> $p */
    public function salvarSemanas(Request $r, array $p = []): Response
    {
        return $this->salvarSemanas->executar($r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('semanas', '[]'))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function excluirSemanas(Request $r, array $p = []): Response
    {
        return $this->excluirSemanas->executar($r->corpo('ano', 0), $r->corpo('mes', 0))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function salvarDias(Request $r, array $p = []): Response
    {
        return $this->salvarDias->executar($r->corpo('week_days', 7))->paraResponse(200);
    }

    /** @return array{0: int, 1: int} ano e mês pedidos (padrão: o mês atual) */
    private function anoMes(Request $r): array
    {
        $agora = Relogio::agora();
        return [(int) $r->query('ano', $agora->format('Y')), (int) $r->query('mes', $agora->format('n'))];
    }
}
