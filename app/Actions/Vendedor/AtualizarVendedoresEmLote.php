<?php
declare(strict_types=1);

namespace App\Actions\Vendedor;

use App\Core\Resultado;
use App\Repositories\VendedorRepository;

/** Caso de uso: aplicar meta semanal e/ou mensal a vários closers ativos de uma vez (a ação em lote da tela Time). */
final class AtualizarVendedoresEmLote
{
    public function __construct(private VendedorRepository $vendedores)
    {
    }

    /** @param mixed $ids @param mixed $metaMensal @param mixed $metas */
    public function executar($ids, $metaMensal, $metas): Resultado
    {
        $lista = MoverVendedor::ids($ids);
        if (!$lista) {
            return Resultado::falha('Nenhum closer selecionado');
        }
        $colunas = [];
        if ($metaMensal !== null && $metaMensal !== '') {
            $colunas['meta_mensal'] = max(0, (int) $metaMensal);
        }
        if ($metas !== null && $metas !== '') {
            $colunas['metas'] = max(1, (int) $metas);
        }
        if (!$colunas) {
            return Resultado::falha('Nada para atualizar');
        }
        $n = $this->vendedores->atualizarMetas($lista, $colunas);
        return Resultado::ok(['updated' => $n], 'Meta atualizada (' . count($lista) . ' closer' . (count($lista) === 1 ? '' : 's') . ')');
    }
}
