<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Soma de contratos por benefício, expandindo o "outros" pela descrição digitada (vira "outros:<descrição>"), para os gráficos e o
 * Painel mostrarem cada "outro" separado. Reproduz o buildTiposMap do api.php.
 */
final class MixDeBeneficios
{
    /**
     * @param array<int, array<string, mixed>> $linhas cada uma com tipo, tipo_descricao (pode faltar) e total
     * @return array<string, int>
     */
    public static function expandir(array $linhas): array
    {
        $mapa = [];
        foreach ($linhas as $r) {
            $chave = ($r['tipo'] === 'outros' && trim((string) ($r['tipo_descricao'] ?? '')) !== '')
                ? 'outros:' . trim((string) $r['tipo_descricao'])
                : $r['tipo'];
            $mapa[$chave] = ($mapa[$chave] ?? 0) + (int) $r['total'];
        }
        return $mapa;
    }
}
