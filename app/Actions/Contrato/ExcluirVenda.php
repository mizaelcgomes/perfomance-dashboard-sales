<?php
declare(strict_types=1);

namespace App\Actions\Contrato;

use App\Core\Database;
use App\Core\Resultado;
use App\Repositories\LancamentoRepository;
use App\Services\ContratoService;

/** Caso de uso: excluir um lançamento do feed. Desfaz a quantidade dele nos totais do closer (benefício, dia e semana). */
final class ExcluirVenda
{
    public function __construct(private Database $banco, private LancamentoRepository $lancamentos, private ContratoService $contratos)
    {
    }

    /** @param mixed $id */
    public function executar($id): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        $antigo = $this->lancamentos->lancamento($id);
        if (!$antigo) {
            return Resultado::falha('Lançamento não encontrado.');
        }
        $total = $this->banco->transacao(function () use ($id, $antigo): int {
            $total = $this->contratos->aplicarDelta((int) $antigo['vendedor_id'], (string) $antigo['data_registro'], (string) $antigo['tipo'], '', -(int) $antigo['quantidade']);
            $this->lancamentos->excluirLancamento($id);
            return $total;
        });
        return Resultado::ok(['total_dia' => $total], 'Venda excluída');
    }
}
