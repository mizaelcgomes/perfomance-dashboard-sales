<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\GraficoService;
use App\Services\PainelService;
use App\Services\RankingFiltradoService;
use App\Services\ResumoSemanaService;

/**
 * Leituras do Painel do admin: o painel completo de uma semana e um mês, os rankings por período, os dados dos gráficos e a
 * tabela "Visão do Time — Semana". Todas exigem o login do admin (middleware nas rotas novas; o api.php confere antes de chamar
 * pela ponte). Falhas de parâmetro voltam com HTTP 200 e {success:false, message}, como o api.php sempre fez.
 */
final class PainelController
{
    public function __construct(
        private PainelService $painel,
        private RankingFiltradoService $rankings,
        private GraficoService $graficos,
        private ResumoSemanaService $resumoSemana
    ) {
    }

    /** Parâmetros opcionais: ano, mes e semana_num (sem eles: a semana atual e o mês corrente). @param array<string, string> $p */
    public function painel(Request $r, array $p = []): Response
    {
        return Response::json($this->painel->painel((int) $r->query('ano', 0), (int) $r->query('mes', 0), (int) $r->query('semana_num', 0)));
    }

    /** Parâmetros: type (semana | mes), periodo (nº da semana ou AAAA-MM), ref_type (closer | equipe) e, para semanas de outro mês, ano e mes. @param array<string, string> $p */
    public function rankings(Request $r, array $p = []): Response
    {
        return Response::json($this->rankings->consultar(
            (string) $r->query('type', ''),
            (string) $r->query('periodo', ''),
            (string) $r->query('ref_type', 'closer'),
            (int) $r->query('ano', 0),
            (int) $r->query('mes', 0)
        ));
    }

    /** Parâmetros: period (week | month | year | day), vendedor_id, ano, mes, semana_num e date. @param array<string, string> $p */
    public function grafico(Request $r, array $p = []): Response
    {
        $vendedor = $r->query('vendedor_id');
        $ano = $r->query('ano');
        $mes = $r->query('mes');
        $semana = $r->query('semana_num');
        $dia = $r->query('date');
        $dados = $this->graficos->dados(
            (string) $r->query('period', 'week'),
            ($vendedor !== null && $vendedor !== '') ? (int) $vendedor : null,
            $ano !== null ? (int) $ano : null,
            $mes !== null ? (int) $mes : null,
            ($semana !== null && $semana !== '') ? (int) $semana : null,
            $dia !== null ? (string) $dia : null
        );
        if ($dados === null) {
            // período desconhecido: resposta vazia (200), como o api.php sempre fez
            return new Response('', 200, ['Content-Type' => 'application/json; charset=utf-8']);
        }
        return Response::json($dados);
    }

    /** Parâmetros: ano, mes, semana_num (obrigatórios) e include_deleted. @param array<string, string> $p */
    public function resumoSemana(Request $r, array $p = []): Response
    {
        return Response::json($this->resumoSemana->semana((int) $r->query('ano', 0), (int) $r->query('mes', 0), (int) $r->query('semana_num', 0), !empty($r->query('include_deleted'))));
    }
}
