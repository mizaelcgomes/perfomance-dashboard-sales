<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Equipe\AdicionarEquipe;
use App\Actions\Equipe\AtualizarEquipe;
use App\Actions\Equipe\DefinirLiderEquipe;
use App\Actions\Equipe\ExcluirEquipe;
use App\Core\Request;
use App\Core\Response;
use App\Services\EquipeService;

/**
 * Equipes do admin (aba Time): a lista com totais e as gravações. Tudo exige o login do admin (middleware nas rotas novas; o api.php confere
 * antes de chamar pela ponte). Falhas de validação respondem com HTTP 200 e {success:false, message}, como o api.php sempre fez.
 */
final class EquipeController
{
    public function __construct(
        private EquipeService $equipes,
        private AdicionarEquipe $adicionar,
        private AtualizarEquipe $atualizar,
        private ExcluirEquipe $excluir,
        private DefinirLiderEquipe $definirLider
    ) {
    }

    /** @param array<string, string> $p */
    public function listar(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'data' => $this->equipes->lista()]);
    }

    /** @param array<string, string> $p */
    public function adicionar(Request $r, array $p = []): Response
    {
        return $this->adicionar->executar(
            $r->corpo('nome', ''), $r->corpo('cor', '#d4af37'), $r->corpo('meta_semanal', 0), $r->corpo('meta_mensal', 0), $r->corpo('oculta_tv', 0), $r->arquivo('foto')
        )->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function atualizar(Request $r, array $p = []): Response
    {
        return $this->atualizar->executar(
            $r->corpo('id', 0), $r->corpo('nome', ''), $r->corpo('cor', '#d4af37'), $r->corpo('meta_semanal'), $r->corpo('meta_mensal'),
            $r->corpo('lider_id', 0), $r->corpo('oculta_tv'), $r->arquivo('foto')
        )->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function excluir(Request $r, array $p = []): Response
    {
        return $this->excluir->executar($r->corpo('id', 0))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function definirLider(Request $r, array $p = []): Response
    {
        return $this->definirLider->executar($r->corpo('equipe_id', 0), $r->corpo('lider_id', 0))->paraResponse(200);
    }
}
