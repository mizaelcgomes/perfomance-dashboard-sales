<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Vendedor\AdicionarVendedor;
use App\Actions\Vendedor\ArquivarVendedor;
use App\Actions\Vendedor\AtualizarVendedor;
use App\Actions\Vendedor\AtualizarVendedoresEmLote;
use App\Actions\Vendedor\DefinirFerias;
use App\Actions\Vendedor\MoverVendedor;
use App\Actions\Vendedor\ReativarSuspenso;
use App\Actions\Vendedor\RestaurarVendedor;
use App\Actions\Vendedor\SuspenderVendedor;
use App\Core\Request;
use App\Core\Response;
use App\Services\VendedorService;

/**
 * Closers ("vendedores" no banco) do admin: cadastro, edição, arquivamento (exclusão lógica), férias, transferência entre equipes e ação em
 * lote. Tudo exige o login do admin (middleware nas rotas novas; o api.php confere antes de chamar pela ponte). Falhas de validação respondem
 * com HTTP 200 e {success:false, message}, como o api.php sempre fez.
 */
final class VendedorController
{
    public function __construct(
        private VendedorService $vendedores,
        private AdicionarVendedor $adicionar,
        private AtualizarVendedor $atualizar,
        private ArquivarVendedor $arquivar,
        private RestaurarVendedor $restaurar,
        private SuspenderVendedor $suspender,
        private ReativarSuspenso $reativar,
        private DefinirFerias $ferias,
        private MoverVendedor $mover,
        private AtualizarVendedoresEmLote $emLote
    ) {
    }

    /** @param array<string, string> $p */
    public function suspensos(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'data' => $this->vendedores->suspensos()]);
    }

    /** @param array<string, string> $p */
    public function elenco(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'data' => $this->vendedores->elenco()]);
    }

    /** @param array<string, string> $p */
    public function arquivados(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'data' => $this->vendedores->arquivados()]);
    }

    /** Último fechamento de cada closer e as férias marcadas (apoio da tela Time). @param array<string, string> $p */
    public function visaoDoTime(Request $r, array $p = []): Response
    {
        $v = $this->vendedores->visaoDoTime();
        return Response::json(['success' => true, 'today' => $v['today'], 'last_sale' => (object) $v['last_sale'], 'ferias' => (object) $v['ferias']]);
    }

    /** @param array<string, string> $p */
    public function adicionar(Request $r, array $p = []): Response
    {
        return $this->adicionar->executar(
            $r->corpo('nome', ''), $r->corpo('metas'), $r->corpo('meta_mensal', 0), $r->corpo('cor', '#d4af37'), $r->corpo('equipe_id'), $r->arquivo('foto')
        )->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function atualizar(Request $r, array $p = []): Response
    {
        return $this->atualizar->executar(
            $r->corpo('id', 0), $r->corpo('nome', ''), $r->corpo('cor', '#d4af37'), $r->corpo('equipe_id'), $r->corpo('metas'), $r->corpo('meta_mensal'), $r->arquivo('foto')
        )->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function arquivar(Request $r, array $p = []): Response
    {
        return $this->arquivar->executar($r->corpo('id', 0), $r->corpo('motivo', ''))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function restaurar(Request $r, array $p = []): Response
    {
        return $this->restaurar->executar($r->corpo('id', 0), $r->corpo('equipe_id'))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function suspender(Request $r, array $p = []): Response
    {
        return $this->suspender->executar($r->corpo('id', 0))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function reativar(Request $r, array $p = []): Response
    {
        return $this->reativar->executar($r->corpo('id', 0))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function ferias(Request $r, array $p = []): Response
    {
        return $this->ferias->executar($r->corpo('id', 0), $r->corpo('inicio', ''), $r->corpo('fim', ''))->paraResponse(200);
    }

    /** "ids" (separados por vírgula) ou, no formato antigo, "id". @param array<string, string> $p */
    public function mover(Request $r, array $p = []): Response
    {
        return $this->mover->executar($r->corpo('ids', $r->corpo('id', '')), $r->corpo('equipe_id', ''))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function emLote(Request $r, array $p = []): Response
    {
        return $this->emLote->executar($r->corpo('ids', ''), $r->corpo('meta_mensal'), $r->corpo('metas'))->paraResponse(200);
    }
}
