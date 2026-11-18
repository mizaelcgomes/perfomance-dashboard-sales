<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ContratoRepository;
use App\Repositories\MetaRepository;
use App\Repositories\VendedorRepository;

/**
 * Tabela "Visão do Time — Mês": contratos por dia do mês de cada closer, na ordem do ranking (total, depois os critérios de desempate).
 * Porte fiel do case get_monthly_summary do api.php.
 */
final class ResumoMensalService
{
    public function __construct(
        private ContratoRepository $contratos,
        private MetaRepository $metas,
        private VendedorRepository $vendedores,
        private DesempateService $desempate,
        private ImagemService $imagens
    ) {
    }

    /** @return array{data: array<int, array<string, mixed>>, month: int, year: int, daysInMonth: int} */
    public function mes(int $ano, int $mes, bool $incluirDesligados): array
    {
        $dias = (int) date('t', mktime(0, 0, 0, $mes, 1, $ano));
        $ini = sprintf('%04d-%02d-01', $ano, $mes);
        $fim = sprintf('%04d-%02d-%02d', $ano, $mes, $dias);

        $vendedores = $this->vendedores->paraResumoMensal($incluirDesligados);
        $porDia = $this->contratos->quantidadePorVendedorEDiaDoMes($ini, $fim);

        // Desligados só entram se tiverem contrato no período
        if ($incluirDesligados) {
            $vendedores = array_values(array_filter($vendedores, static function (array $v) use ($porDia): bool {
                return $v['deleted_at'] === null || isset($porDia[(int) $v['id']]);
            }));
        }

        $tiposPorVendedor = $this->contratos->tiposPorVendedor($ini, $fim);
        $metaDoMes = $this->metas->mensalDosClosers($ano, $mes);   // semana_num 0 = meta do mês
        $ordemDesempate = $this->desempate->ordem();

        $resultado = array_map(function (array $v) use ($porDia, $dias, $tiposPorVendedor, $metaDoMes): array {
            $vid = (int) $v['id'];
            $diario = [];
            $total = 0;
            for ($d = 1; $d <= $dias; $d++) {
                $q = $porDia[$vid][$d] ?? 0;
                $diario[] = $q;
                $total += $q;
            }
            $metaMensal = (int) $v['meta_mensal'];
            $metaEfetiva = $metaDoMes[$vid] ?? ($metaMensal > 0 ? $metaMensal : (int) $v['metas']);
            return [
                'id' => $vid, 'name' => $v['nome'], 'metas' => $metaEfetiva, 'color' => $v['cor'],
                'img' => $v['img_pravatar'] ? (int) $v['img_pravatar'] : null, 'imageUrl' => $this->imagens->url($v['img_url']),
                'daily' => $diario, 'total' => $total, 'tipo_totals' => $tiposPorVendedor[$vid] ?? [],
                'deleted' => $v['deleted_at'] !== null,
            ];
        }, $vendedores);

        usort($resultado, function (array $a, array $b) use ($ordemDesempate): int {
            if ($a['deleted'] !== $b['deleted']) {   // ativos antes dos desligados
                return $a['deleted'] ? 1 : -1;
            }
            if ($b['total'] !== $a['total']) {
                return $b['total'] - $a['total'];
            }
            foreach ($ordemDesempate as $criterio) {
                $c = $this->desempate->comparar($a, $b, $criterio, 'tipo_totals', 'conv_rate');
                if ($c !== 0) {
                    return $c;
                }
            }
            return strcmp($a['name'], $b['name']);
        });

        return ['data' => $resultado, 'month' => $mes, 'year' => $ano, 'daysInMonth' => $dias];
    }
}
