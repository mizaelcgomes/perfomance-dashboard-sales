<?php
declare(strict_types=1);

namespace App\Actions\Contrato;

use App\Core\Database;
use App\Core\Resultado;
use App\Repositories\LancamentoRepository;
use App\Services\ContratoService;
use App\Services\Texto;

/** Caso de uso: venda rápida. SOMA a quantidade ao benefício do closer no dia (não mexe nos outros) e registra no feed. */
final class AdicionarVenda
{
    public function __construct(private Database $banco, private LancamentoRepository $lancamentos, private ContratoService $contratos)
    {
    }

    /** @param mixed $vendedorId @param mixed $data @param mixed $tipo @param mixed $quantidade @param mixed $descricao só vale para "outros" */
    public function executar($vendedorId, $data, $tipo, $quantidade, $descricao): Resultado
    {
        $vid = (int) (is_scalar($vendedorId) ? $vendedorId : 0);
        $data = is_scalar($data) ? (string) $data : '';
        $tipo = is_scalar($tipo) ? (string) $tipo : '';
        $qtd = (int) (is_scalar($quantidade) ? $quantidade : 0);
        $desc = $tipo === 'outros' ? Texto::limpar($descricao, 100) : '';
        $erro = $this->contratos->validarVenda($vid, $data, $tipo, $qtd, true);
        if ($erro !== '') {
            return Resultado::falha($erro);
        }
        [$id, $total] = $this->banco->transacao(function () use ($vid, $data, $tipo, $qtd, $desc): array {
            $total = $this->contratos->aplicarDelta($vid, $data, $tipo, $desc, $qtd);
            return [$this->lancamentos->inserirNoFeed($vid, $data, $tipo, $desc, $qtd, 'rapido', ContratoService::agora()), $total];
        });
        return Resultado::ok(['id' => $id, 'total_dia' => $total], 'Venda registrada');
    }
}
