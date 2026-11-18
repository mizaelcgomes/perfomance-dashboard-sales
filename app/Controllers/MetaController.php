<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Meta\AplicarMetaDiaria;
use App\Actions\Meta\RemoverMetaDiariaExcecao;
use App\Actions\Meta\RemoverMetaExcecao;
use App\Actions\Meta\RemoverMetaExcecaoGrupo;
use App\Actions\Meta\SalvarMetaCac;
use App\Actions\Meta\SalvarMetaDiariaCloser;
use App\Actions\Meta\SalvarMetaExcecao;
use App\Actions\Meta\SalvarMetaExcecaoGrupo;
use App\Actions\Meta\SalvarMetasDiariasMes;
use App\Actions\Meta\SalvarMetasPeriodo;
use App\Core\Relogio;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MetaRepository;
use App\Services\MetasService;

/**
 * A aba "Metas & Regras" do admin: metas por período (closer, equipe e geral), metas diárias, a visão por EXCEÇÃO (padrão × exceção) dos closers,
 * das equipes e do Comercial, os templates de meta diária e a meta de CAC. Tudo exige o login do admin (middleware nas rotas novas; o api.php
 * confere antes de chamar pela ponte). Falhas de validação respondem com HTTP 200 e {success:false, message}, como o api.php sempre fez.
 */
final class MetaController
{
    public function __construct(
        private MetaRepository $metas,
        private MetasService $metasService,
        private SalvarMetaCac $salvarCac,
        private SalvarMetasPeriodo $salvarPeriodo,
        private SalvarMetasDiariasMes $salvarDiariasMes,
        private SalvarMetaExcecao $salvarExcecao,
        private RemoverMetaExcecao $removerExcecao,
        private SalvarMetaExcecaoGrupo $salvarExcecaoGrupo,
        private RemoverMetaExcecaoGrupo $removerExcecaoGrupo,
        private AplicarMetaDiaria $aplicarDiaria,
        private SalvarMetaDiariaCloser $salvarDiariaCloser,
        private RemoverMetaDiariaExcecao $removerDiariaExcecao
    ) {
    }

    // ── leituras ─────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $p */
    public function periodo(Request $r, array $p = []): Response
    {
        [$ano, $mes] = $this->anoMes($r);
        return Response::json(['success' => true, 'data' => $this->metas->periodoDoMes($ano, $mes), 'ano' => $ano, 'mes' => $mes]);
    }

    /** @param array<string, string> $p */
    public function diariasDoMes(Request $r, array $p = []): Response
    {
        [$ano, $mes] = $this->anoMes($r);
        if ($mes < 1 || $mes > 12) {
            return $this->falha('Mês inválido');
        }
        return Response::json(['success' => true, 'data' => $this->metas->diariasDoMes($ano, $mes), 'ano' => $ano, 'mes' => $mes]);
    }

    /** O estado das metas dos closers (padrão × exceção). @param array<string, string> $p */
    public function excecoes(Request $r, array $p = []): Response
    {
        [$ano, $mes] = $this->anoMes($r);
        if ($ano < 2000 || $mes < 1 || $mes > 12) {
            return $this->falha('Mês inválido');
        }
        return Response::json(['success' => true] + $this->metasService->estadoPublico($ano, $mes));
    }

    /** O estado das metas das equipes (escopo=equipe) ou do Comercial (escopo=geral). @param array<string, string> $p */
    public function excecoesGrupo(Request $r, array $p = []): Response
    {
        [$ano, $mes] = $this->anoMes($r);
        $esc = (string) $r->query('escopo', '');
        if (!in_array($esc, ['equipe', 'geral'], true) || $ano < 2000 || $mes < 1 || $mes > 12) {
            return $this->falha('Dados inválidos');
        }
        return Response::json(['success' => true] + $this->metasService->estadoGrupo($esc, $ano, $mes));
    }

    /** @param array<string, string> $p */
    public function estadoDiario(Request $r, array $p = []): Response
    {
        [$ano, $mes] = $this->anoMes($r);
        if ($ano < 2000 || $mes < 1 || $mes > 12) {
            return $this->falha('Mês inválido');
        }
        return Response::json(['success' => true] + $this->metasService->estadoDiario($ano, $mes));
    }

    // ── escritas ─────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $p */
    public function salvarCac(Request $r, array $p = []): Response
    {
        return $this->salvarCac->executar($r->corpo('meta_cac', 0))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function salvarPeriodo(Request $r, array $p = []): Response
    {
        return $this->salvarPeriodo->executar($r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('metas', []))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function salvarDiariasDoMes(Request $r, array $p = []): Response
    {
        return $this->salvarDiariasMes->executar($r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('metas', '[]'))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function salvarExcecao(Request $r, array $p = []): Response
    {
        return $this->salvarExcecao->executar($r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('alvo', ''), $r->corpo('metas', '{}'))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function removerExcecao(Request $r, array $p = []): Response
    {
        return $this->removerExcecao->executar($r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('closer_id', 0))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function salvarExcecaoGrupo(Request $r, array $p = []): Response
    {
        return $this->salvarExcecaoGrupo->executar($r->corpo('escopo', ''), $r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('alvo', ''), $r->corpo('metas', '{}'))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function removerExcecaoGrupo(Request $r, array $p = []): Response
    {
        return $this->removerExcecaoGrupo->executar($r->corpo('escopo', ''), $r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('alvo', 0))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function aplicarDiaria(Request $r, array $p = []): Response
    {
        return $this->aplicarDiaria->executar($r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('tipo', ''), $r->corpo('segs', '[]'))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function salvarDiariaCloser(Request $r, array $p = []): Response
    {
        return $this->salvarDiariaCloser->executar($r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('closer_id', 0), $r->corpo('dias', '{}'))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function removerDiariaExcecao(Request $r, array $p = []): Response
    {
        return $this->removerDiariaExcecao->executar($r->corpo('ano', 0), $r->corpo('mes', 0), $r->corpo('closer_id', 0))->paraResponse(200);
    }

    /** @return array{0: int, 1: int} ano e mês pedidos (padrão: o mês atual) */
    private function anoMes(Request $r): array
    {
        $agora = Relogio::agora();
        return [(int) $r->query('ano', $agora->format('Y')), (int) $r->query('mes', $agora->format('n'))];
    }

    private function falha(string $mensagem): Response
    {
        return Response::json(['success' => false, 'message' => $mensagem]);
    }
}
