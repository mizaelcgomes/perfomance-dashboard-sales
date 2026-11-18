<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Relogio;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\ContratoRepository;
use App\Services\MusicaService;
use App\Services\RankingAoVivoService;

/**
 * O que a TV (index.php) lê, sem login: o ranking ao vivo com tudo que o painel precisa (get_data) e o total público de contratos
 * do mês (usado pelas telas de propaganda).
 */
final class TvController
{
    public function __construct(
        private RankingAoVivoService $ranking,
        private ContratoRepository $contratos,
        private MusicaService $musicas,
        private View $view
    ) {
    }

    /**
     * A página da TV (HTML): a casca em app/Views/tv/index.php, com o CSS e o JavaScript em public/assets (tv.css e tv.js) e a lista
     * de músicas que existem no servidor. Hoje é servida pelo index.php do site (mesmo endereço de sempre), pela ponte.
     * @param array<string, string> $p
     */
    public function pagina(Request $r, array $p = []): Response
    {
        $trilhas = $this->musicas->trilhasDaTv();
        return Response::html($this->view->parcial('tv/index', ['config' => ['celebrationTracks' => $trilhas['celebracao'], 'bgTracks' => $trilhas['fundo']]]));
    }

    /** Ranking semanal e mensal de closers e equipes, rotação, propagandas, próxima música e CAC. @param array<string, string> $p */
    public function dados(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true] + $this->ranking->dados());
    }

    /** Só o total agregado de contratos do mês corrente (público e somente leitura, para telas de propaganda). @param array<string, string> $p */
    public function contratosTotal(Request $r, array $p = []): Response
    {
        $agora = Relogio::agora();
        return Response::json([
            'success' => true,
            'total' => $this->contratos->totalDoMes($agora->format('Y-m')),
            'year' => (int) $agora->format('Y'),
            'month' => (int) $agora->format('m'),
        ]);
    }
}
