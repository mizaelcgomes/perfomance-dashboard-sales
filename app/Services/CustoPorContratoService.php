<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ContratoRepository;
use App\Repositories\InvestimentoRepository;
use App\Repositories\MetaRepository;

/**
 * Aba "Custo por Contrato": contratos, investimento e CAC do mês, mais a série diária acumulada. O sistema guarda o investimento por MÊS
 * (investimento_mensal; o mês corrente é o valor manual), então a curva diária distribui o total uniformemente
 * pelos dias já decorridos. NÃO chama a API do Meta: só lê o banco. Porte fiel do case get_custo_contrato do api.php.
 */
final class CustoPorContratoService
{
    public function __construct(
        private ContratoRepository $contratos,
        private InvestimentoRepository $investimentos,
        private MetaRepository $metas,
        private ConfiguracaoRepository $config,
        private Calendario $calendario,
        private RelatorioService $relatorio
    ) {
    }

    /** @return array<string, mixed> */
    public function mes(int $ano, int $mes): array
    {
        $mi = $this->calendario->mesInfo($ano, $mes);
        $ano = $mi['ano'];
        $mes = $mi['mes'];
        $decorridos = $mi['dias_decorridos'];

        $porDia = $this->contratos->totaisPorDia($mi['ini'], $mi['fim']);
        $contratos = array_sum($porDia);
        $investimento = $this->investimentos->doMes($ano, $mes);

        // Meta de contratos do mês: a meta "geral" cadastrada na aba Metas; senão a soma das metas dos closers ativos (como o painel).
        $metaContratos = 0;
        foreach ($this->relatorio->closers($ano, $mes)['data'] as $linha) {
            if (!$linha['ex_closer']) {
                $metaContratos += $linha['meta_mes'];
            }
        }
        $geral = $this->metas->geralDoMes($ano, $mes);
        if ($geral !== null && $geral > 0) {
            $metaContratos = $geral;
        }

        $metaCac = (float) $this->config->valor('meta_cac', '85');
        $serie = [];
        $acumulado = 0;
        foreach ($this->calendario->datas($mi['ini'], $mi['fim']) as $i => $data) {
            $dia = $i + 1;
            if ($dia <= $decorridos) {
                $q = $porDia[$data] ?? 0;
                $acumulado += $q;
                $invAcum = $decorridos > 0 ? round($investimento * $dia / $decorridos, 2) : 0.0;
                $serie[] = ['dia' => $dia, 'data' => $data, 'contratos' => $q, 'ctr_acum' => $acumulado, 'inv_acum' => $invAcum,
                    'cac_acum' => ($invAcum > 0 && $acumulado > 0) ? round($invAcum / $acumulado, 2) : null];
            } else {
                $serie[] = ['dia' => $dia, 'data' => $data, 'contratos' => null, 'ctr_acum' => null, 'inv_acum' => null, 'cac_acum' => null];
            }
        }

        return [
            'success' => true, 'ano' => $ano, 'mes' => $mes, 'hoje' => $mi['hoje'], 'estado' => $mi['estado'],
            'dias_mes' => $mi['dias_mes'], 'dias_decorridos' => $decorridos,
            'contratos' => $contratos, 'meta_contratos' => $metaContratos,
            'investimento' => $investimento, 'meta_cac' => $metaCac,
            'cac' => ($investimento > 0 && $contratos > 0) ? round($investimento / $contratos, 2) : null,
            'contratos_dia' => $decorridos > 0 ? round($contratos / $decorridos, 2) : 0,
            'meta_contratos_dia' => $mi['dias_mes'] > 0 ? round($metaContratos / $mi['dias_mes'], 2) : 0,
            'orcamento' => round($metaCac * $metaContratos, 2),
            'fonte' => 'manual',
            'serie' => $serie,
        ];
    }
}
