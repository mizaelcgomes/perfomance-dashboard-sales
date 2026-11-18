<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Contrato\AdicionarVenda;
use App\Actions\Contrato\AtualizarVenda;
use App\Actions\Contrato\ExcluirVenda;
use App\Actions\Contrato\SalvarContratos;
use App\Actions\Contrato\SalvarDesempate;
use App\Actions\Contrato\SalvarTiposDoDia;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\LancamentoRepository;
use App\Services\DesempateService;
use App\Services\FeedService;
use App\Services\MatrizService;

/**
 * Contratos do admin (aba Contratos): o lançamento por dia e por benefício, o feed de vendas, a matriz de auditoria e os critérios de
 * desempate. Tudo exige o login do admin (middleware nas rotas novas; o api.php confere antes de chamar pela ponte). Falhas de validação
 * respondem com HTTP 200 e {success:false, message}, como o api.php sempre fez.
 */
final class ContratoController
{
    public function __construct(
        private LancamentoRepository $lancamentos,
        private FeedService $feed,
        private MatrizService $matriz,
        private DesempateService $desempate,
        private SalvarContratos $salvarContratos,
        private SalvarTiposDoDia $salvarTipos,
        private AdicionarVenda $adicionarVenda,
        private AtualizarVenda $atualizarVenda,
        private ExcluirVenda $excluirVenda,
        private SalvarDesempate $salvarDesempate
    ) {
    }

    /** Contratos da semana corrente, dia a dia (campos vendedor_id e daily[]). @param array<string, string> $p */
    public function salvar(Request $r, array $p = []): Response
    {
        return $this->salvarContratos->executar($r->corpo('vendedor_id', 0), $r->corpo('daily', []))->paraResponse(200);
    }

    /** O detalhe por benefício de um dia de um closer. @param array<string, string> $p */
    public function tiposDoDia(Request $r, array $p = []): Response
    {
        $vendedorId = (int) $r->query('vendedor_id', 0);
        $data = (string) $r->query('data_registro', '');
        if (!$vendedorId || !$data) {
            return Response::json(['success' => false, 'message' => 'Dados inválidos']);
        }
        $mapa = [];
        foreach ($this->lancamentos->tiposDoDia($vendedorId, $data) as $linha) {
            $mapa[$linha['tipo']] = ['qty' => (int) $linha['quantidade'], 'desc' => $linha['tipo_descricao']];
        }
        return Response::json(['success' => true, 'data' => $mapa]);
    }

    /** @param array<string, string> $p */
    public function salvarTipos(Request $r, array $p = []): Response
    {
        return $this->salvarTipos->executar($r->corpo('vendedor_id', 0), $r->corpo('data_registro', ''), $r->corpo('tipos', []), $r->corpo('outros_desc', ''))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function feed(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true] + $this->feed->dia($r->query('data', '')));
    }

    /** @param array<string, string> $p */
    public function adicionarVenda(Request $r, array $p = []): Response
    {
        return $this->adicionarVenda->executar(
            $r->corpo('vendedor_id', 0), $r->corpo('data_registro', ''), $r->corpo('tipo', ''), $r->corpo('quantidade', 0), $r->corpo('descricao', '')
        )->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function atualizarVenda(Request $r, array $p = []): Response
    {
        return $this->atualizarVenda->executar(
            $r->corpo('id', 0), $r->corpo('vendedor_id', 0), $r->corpo('data_registro', ''), $r->corpo('tipo', ''), $r->corpo('quantidade', 0), $r->corpo('descricao', '')
        )->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function excluirVenda(Request $r, array $p = []): Response
    {
        return $this->excluirVenda->executar($r->corpo('id', 0))->paraResponse(200);
    }

    /** A matriz benefício × dia de um closer, ou de todos com vendedor_id 0. @param array<string, string> $p */
    public function matriz(Request $r, array $p = []): Response
    {
        return Response::json($this->matriz->matriz($r->query('start', ''), $r->query('end', ''), (int) $r->query('vendedor_id', 0)));
    }

    /** @param array<string, string> $p */
    public function desempate(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'data' => $this->desempate->ordem()]);
    }

    /** @param array<string, string> $p */
    public function salvarDesempate(Request $r, array $p = []): Response
    {
        return $this->salvarDesempate->executar($r->corpo('order', '[]'))->paraResponse(200);
    }
}
