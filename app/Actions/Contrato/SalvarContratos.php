<?php
declare(strict_types=1);

namespace App\Actions\Contrato;

use App\Core\Database;
use App\Core\Resultado;
use App\Repositories\LancamentoRepository;
use App\Repositories\VendedorRepository;
use App\Services\Calendario;

/**
 * Caso de uso: gravar os contratos da semana corrente de um closer, dia a dia (a tabela legada por dia_semana e o total de cada dia em
 * contratos_historico). O total da semana vira vendas/faltam/score do closer. Cada dia da semana configurada tem a sua data.
 */
final class SalvarContratos
{
    public function __construct(
        private Database $banco,
        private VendedorRepository $vendedores,
        private LancamentoRepository $lancamentos,
        private Calendario $calendario
    ) {
    }

    /** @param mixed $vendedorId @param mixed $porDia dia (0, 1, 2…) => quantidade */
    public function executar($vendedorId, $porDia): Resultado
    {
        $vendedorId = (int) (is_scalar($vendedorId) ? $vendedorId : 0);
        $diario = [];
        foreach (is_array($porDia) ? $porDia : [] as $i => $v) {
            $diario[(int) $i] = (int) (is_scalar($v) ? $v : 0);
        }
        ksort($diario);

        $linha = $this->vendedores->metasDoAtivo($vendedorId);
        if (!$linha) {
            return Resultado::falha('Closer não encontrado');
        }
        $total = array_sum($diario);
        $this->vendedores->gravarVendas($vendedorId, $total, max(0, $linha['metas'] - $total), min(100, (int) round($total / $linha['metas'] * 100)));

        foreach ($diario as $dia => $quantidade) {
            $this->lancamentos->gravarDiaDaSemana($vendedorId, $dia, $quantidade);
        }
        $semana = $this->calendario->semanaAtual();
        $datas = $this->calendario->datas($semana['start'], $semana['end']);
        foreach ($diario as $dia => $quantidade) {
            if (isset($datas[$dia])) {
                $this->lancamentos->gravarTotalDoDia($vendedorId, $datas[$dia], $quantidade);
            }
        }
        return Resultado::ok(['total' => $total], 'Contratos salvos');
    }
}
