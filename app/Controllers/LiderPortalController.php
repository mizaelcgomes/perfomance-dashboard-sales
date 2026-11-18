<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Relogio;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\EquipeRepository;
use App\Services\AgendaLiderService;
use App\Services\LiderBuscaService;
use App\Services\LiderDashboardService;
use App\Services\LiderEquipeService;
use App\Services\LiderPreferenciasService;
use App\Services\TabelasDoLider;
use Throwable;

/**
 * O portal do líder (fase L4): Dashboard, Equipe, Agenda, busca global e Configurações. Tudo atrás do middleware auth:lider: a equipe vem
 * SEMPRE dele (parâmetro equipe_id) e o líder vem do cadastro da equipe; nada na requisição escolhe a equipe, então o líder só enxerga a dele.
 * Se as tabelas do módulo ainda não existem (migração não rodada), responde 503 com a instrução em vez de um erro genérico.
 */
final class LiderPortalController
{
    public function __construct(
        private LiderDashboardService $dashboard,
        private LiderEquipeService $equipeService,
        private AgendaLiderService $agenda,
        private LiderBuscaService $busca,
        private LiderPreferenciasService $preferencias,
        private EquipeRepository $equipes
    ) {
    }

    /** @param array<string, string> $p */
    public function dashboardGeral(Request $r, array $p = []): Response
    {
        return $this->protegido(function () use ($p): Response {
            [$equipe, $lider] = $this->contexto($p);
            return Response::json(['success' => true] + $this->dashboard->dashboard($equipe, $lider));
        });
    }

    /** @param array<string, string> $p */
    public function equipe(Request $r, array $p = []): Response
    {
        return $this->protegido(function () use ($p): Response {
            [$equipe, $lider] = $this->contexto($p);
            return Response::json(['success' => true] + $this->equipeService->equipe($equipe, $lider));
        });
    }

    /** O detalhe de um closer da equipe: o id vem da rota ({id}) ou do corpo (closer_id); periodo = semana ou mes. Closer de outra equipe: 404. @param array<string, string> $p */
    public function closer(Request $r, array $p = []): Response
    {
        return $this->protegido(function () use ($r, $p): Response {
            [$equipe, $lider] = $this->contexto($p);
            $dados = $this->equipeService->closer($equipe, $lider, (int) ($p['id'] ?? $r->entrada('closer_id', 0)), (string) $r->entrada('periodo', 'semana'));
            return $dados === null
                ? Response::json(['success' => false, 'message' => 'Closer não encontrado.'], 404)
                : Response::json(['success' => true] + $dados);
        });
    }

    /** Parâmetros: ano e mes (padrão: o mês atual). Devolve os eventos do mês e os dos próximos 7 dias. @param array<string, string> $p */
    public function agenda(Request $r, array $p = []): Response
    {
        return $this->protegido(function () use ($r, $p): Response {
            [$equipe] = $this->contexto($p);
            $agora = Relogio::agora();
            $ano = (int) $r->entrada('ano', $agora->format('Y'));
            $mes = (int) $r->entrada('mes', $agora->format('n'));
            $eventos = $this->agenda->mes($equipe, $ano, $mes);
            if ($ano < 2020 || $ano > 2100 || $mes < 1 || $mes > 12) {
                $ano = (int) $agora->format('Y');
                $mes = (int) $agora->format('n');
            }
            return Response::json(['success' => true, 'ano' => $ano, 'mes' => $mes, 'eventos' => $eventos, 'proximos' => $this->agenda->proximos($equipe, 7)]);
        });
    }

    /** Parâmetro: q (mínimo 2 caracteres). @param array<string, string> $p */
    public function busca(Request $r, array $p = []): Response
    {
        return $this->protegido(function () use ($r, $p): Response {
            [$equipe, $lider] = $this->contexto($p);
            return Response::json(['success' => true] + $this->busca->buscar($equipe, $lider, $r->entrada('q', '')));
        });
    }

    /** @param array<string, string> $p */
    public function preferencias(Request $r, array $p = []): Response
    {
        return $this->protegido(function () use ($p): Response {
            [, $lider] = $this->contexto($p);
            return Response::json(['success' => true, 'preferencias' => $this->preferencias->obter($lider), 'alertas_nomes' => LiderPreferenciasService::ALERTAS,
                'limites' => ['sem_venda' => LiderPreferenciasService::LIMITE_DIAS_SEM_VENDA, 'sem_feedback' => LiderPreferenciasService::DIAS_SEM_FEEDBACK]]);
        });
    }

    /** @param array<string, string> $p */
    public function preferenciasSalvar(Request $r, array $p = []): Response
    {
        return $this->protegido(function () use ($r, $p): Response {
            [, $lider] = $this->contexto($p);
            $entrada = [];
            foreach (['limite_dias_sem_venda', 'dias_sem_feedback', 'alertas'] as $campo) {
                if ($r->entrada($campo) !== null) {
                    $entrada[$campo] = $r->entrada($campo);
                }
            }
            return $this->preferencias->salvar($lider, $entrada)->paraResponse();
        });
    }

    /**
     * A equipe (do middleware) e o líder dela (do cadastro).
     * @param array<string, string> $p
     * @return array{0: int, 1: int}
     */
    private function contexto(array $p): array
    {
        $equipe = (int) ($p['equipe_id'] ?? 0);
        $linha = $this->equipes->comLider($equipe);
        return [$equipe, $linha === null || $linha['lider_id'] === null ? 0 : (int) $linha['lider_id']];
    }

    private function protegido(callable $acao): Response
    {
        try {
            return $acao();
        } catch (Throwable $erro) {
            if (!TabelasDoLider::ausente($erro)) {
                throw $erro;
            }
            return Response::json(['success' => false, 'code' => 'migracao', 'message' => TabelasDoLider::MENSAGEM], 503);
        }
    }
}
