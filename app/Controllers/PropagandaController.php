<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Propaganda\AdicionarPropaganda;
use App\Actions\Propaganda\AlternarPropaganda;
use App\Actions\Propaganda\AtualizarPropaganda;
use App\Actions\Propaganda\ExcluirPropaganda;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\PropagandaRepository;

/**
 * Propagandas da rotação da TV. A leitura é pública (a TV precisa dela sem login); cadastrar, editar, apagar e ligar/desligar
 * exigem o login do admin (middleware nas rotas novas; o api.php confere antes de chamar pela ponte).
 * Falhas de validação respondem com HTTP 200 e {success:false, message}, como o api.php sempre fez (o admin.php depende disso).
 */
final class PropagandaController
{
    public function __construct(
        private PropagandaRepository $propagandas,
        private AdicionarPropaganda $adicionar,
        private AtualizarPropaganda $atualizar,
        private ExcluirPropaganda $excluir,
        private AlternarPropaganda $alternar
    ) {
    }

    /** Todas as propagandas (a mais nova primeiro); com ?ativo=1 só as ativas. @param array<string, string> $p */
    public function listar(Request $r, array $p = []): Response
    {
        $filtro = $r->query('ativo');
        $soAtivas = $filtro !== null ? (bool) $filtro : false;
        return Response::json(['success' => true, 'data' => $this->propagandas->listar($soAtivas)]);
    }

    /** @param array<string, string> $p */
    public function adicionar(Request $r, array $p = []): Response
    {
        return $this->adicionar->executar((string) $r->corpo('titulo', ''), (string) $r->corpo('conteudo_html', ''), (int) (bool) $r->corpo('ativo', 1))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function atualizar(Request $r, array $p = []): Response
    {
        return $this->atualizar->executar((int) $r->corpo('id', 0), (string) $r->corpo('titulo', ''), (string) $r->corpo('conteudo_html', ''), (int) (bool) $r->corpo('ativo', 1))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function excluir(Request $r, array $p = []): Response
    {
        return $this->excluir->executar((int) $r->corpo('id', 0))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function alternar(Request $r, array $p = []): Response
    {
        return $this->alternar->executar((int) $r->corpo('id', 0))->paraResponse(200);
    }
}
