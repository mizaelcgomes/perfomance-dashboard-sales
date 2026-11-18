<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Atendimento\SalvarAtendimento;
use App\Actions\Atendimento\SalvarAtendimentosEmLote;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\AtendimentoRepository;

/** Atendimentos lançados à mão por closer e dia (base da taxa de conversão). Tudo exige o login do admin. Falhas respondem com HTTP 200. */
final class AtendimentoController
{
    public function __construct(
        private AtendimentoRepository $atendimentos,
        private SalvarAtendimento $salvar,
        private SalvarAtendimentosEmLote $salvarEmLote
    ) {
    }

    /** Os atendimentos de um closer entre duas datas: {data: quantidade}. @param array<string, string> $p */
    public function listar(Request $r, array $p = []): Response
    {
        $vendedorId = (int) $r->query('vendedor_id', 0);
        $inicio = (string) $r->query('start', '');
        $fim = (string) $r->query('end', '');
        if (!$vendedorId || !$inicio || !$fim) {
            return Response::json(['success' => false, 'message' => 'Parâmetros inválidos']);
        }
        return Response::json(['success' => true, 'data' => $this->atendimentos->doVendedorNoPeriodo($vendedorId, $inicio, $fim)]);
    }

    /** @param array<string, string> $p */
    public function salvar(Request $r, array $p = []): Response
    {
        return $this->salvar->executar($r->corpo('vendedor_id', 0), $r->corpo('data_registro', ''), $r->corpo('quantidade', 0))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function salvarEmLote(Request $r, array $p = []): Response
    {
        return $this->salvarEmLote->executar($r->corpo('vendedor_id', 0), $r->corpo('days', '{}'))->paraResponse(200);
    }
}
