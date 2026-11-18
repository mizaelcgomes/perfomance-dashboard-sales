<?php
declare(strict_types=1);

namespace App\Actions\Contrato;

use App\Core\Database;
use App\Core\Resultado;
use App\Repositories\LancamentoRepository;
use App\Services\ContratoService;
use App\Services\Texto;

/**
 * Caso de uso: editar um lançamento do feed. Desfaz o antigo nos totais e aplica o novo (closer, data, benefício ou quantidade). Um
 * lançamento antigo ("legado", sem hora) editado ganha a hora da edição. Só exige closer ativo se o lançamento mudou de closer.
 */
final class AtualizarVenda
{
    public function __construct(private Database $banco, private LancamentoRepository $lancamentos, private ContratoService $contratos)
    {
    }

    /** @param mixed $id @param mixed $vendedorId @param mixed $data @param mixed $tipo @param mixed $quantidade @param mixed $descricao */
    public function executar($id, $vendedorId, $data, $tipo, $quantidade, $descricao): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        $vid = (int) (is_scalar($vendedorId) ? $vendedorId : 0);
        $data = is_scalar($data) ? (string) $data : '';
        $tipo = is_scalar($tipo) ? (string) $tipo : '';
        $qtd = (int) (is_scalar($quantidade) ? $quantidade : 0);
        $desc = $tipo === 'outros' ? Texto::limpar($descricao, 100) : '';
        $antigo = $this->lancamentos->lancamento($id);
        if (!$antigo) {
            return Resultado::falha('Lançamento não encontrado.');
        }
        $erro = $this->contratos->validarVenda($vid, $data, $tipo, $qtd, $vid !== (int) $antigo['vendedor_id']);
        if ($erro !== '') {
            return Resultado::falha($erro);
        }
        $total = $this->banco->transacao(function () use ($id, $vid, $data, $tipo, $qtd, $desc, $antigo): int {
            $this->contratos->aplicarDelta((int) $antigo['vendedor_id'], (string) $antigo['data_registro'], (string) $antigo['tipo'], '', -(int) $antigo['quantidade']);
            $total = $this->contratos->aplicarDelta($vid, $data, $tipo, $desc, $qtd);
            $legado = $antigo['origem'] === 'legado';
            $this->lancamentos->atualizarLancamento(
                $id, $vid, $data, $tipo, $desc, $qtd, $legado ? 'rapido' : (string) $antigo['origem'], $legado ? ContratoService::agora() : (string) $antigo['criado_em']
            );
            return $total;
        });
        return Resultado::ok(['id' => $id, 'total_dia' => $total], 'Venda atualizada');
    }
}
