<?php
declare(strict_types=1);

namespace App\Actions\Contrato;

use App\Core\Database;
use App\Core\Resultado;
use App\Repositories\LancamentoRepository;
use App\Services\ContratoService;
use App\Services\TiposBeneficioService;

/**
 * Caso de uso: gravar o detalhe por benefício de UM dia de um closer (a matriz). Troca só os benefícios ATIVOS do dia: contratos de um
 * benefício desativado ou removido ficam intactos e continuam somando no total do dia. Depois atualiza o total do dia, a semana corrente e
 * o feed do dia, que passa a refletir exatamente o que a matriz gravou. Tudo numa transação.
 */
final class SalvarTiposDoDia
{
    public function __construct(
        private Database $banco,
        private LancamentoRepository $lancamentos,
        private ContratoService $contratos,
        private TiposBeneficioService $tipos
    ) {
    }

    /** @param mixed $vendedorId @param mixed $data @param mixed $tipos benefício => quantidade @param mixed $outrosDescricao */
    public function executar($vendedorId, $data, $tipos, $outrosDescricao): Resultado
    {
        $vendedorId = (int) (is_scalar($vendedorId) ? $vendedorId : 0);
        $data = is_scalar($data) ? (string) $data : '';
        $tipos = is_array($tipos) ? $tipos : [];
        $descricaoDeOutros = trim(is_scalar($outrosDescricao) ? (string) $outrosDescricao : '');
        if (!$vendedorId || !$data) {
            return Resultado::falha('Dados inválidos');
        }
        $ativos = array_keys($this->tipos->ativos());
        $totais = $this->banco->transacao(function () use ($vendedorId, $data, $tipos, $descricaoDeOutros, $ativos): array {
            $this->lancamentos->apagarTiposAtivos($vendedorId, $data, array_map('strval', $ativos));
            foreach ($ativos as $tipo) {
                $bruto = $tipos[$tipo] ?? 0;
                $quantidade = max(0, (int) (is_scalar($bruto) ? $bruto : 0));
                if ($quantidade > 0) {
                    $this->lancamentos->inserirTipo($vendedorId, $data, (string) $tipo, $tipo === 'outros' ? $descricaoDeOutros : '', $quantidade);
                }
            }
            $totalDoDia = $this->lancamentos->somaDosTiposDoDia($vendedorId, $data);
            $totalDaSemana = $this->contratos->sincronizarVendedor($vendedorId, $data, $totalDoDia);   // histórico + legado + vendas/score da semana
            $this->contratos->sincronizarFeed($data, $vendedorId, false);
            return ['day_total' => $totalDoDia, 'week_total' => $totalDaSemana];
        });
        return Resultado::ok($totais, 'Tipos salvos');
    }
}
