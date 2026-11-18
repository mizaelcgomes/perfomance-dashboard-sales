<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ContratoRepository;
use App\Repositories\MetaRepository;
use App\Repositories\VendedorRepository;

/**
 * Relatório "Performance de Closers" de um mês: por closer, contratos de cada semana configurada (com a meta e se bateu), contratos e meta
 * do mês e o mix de benefícios (do mês e de cada semana). É a fonte ÚNICA da tela, do CSV e do PDF.
 *
 * Semana = as configuradas em semanas_mes (ou a auto-composição quarta→terça); a meta do mês segue a precedência do painel
 * (meta do período → meta mensal do cadastro → projeção semanal × nº de semanas). Ex-closers só entram se tiverem contrato na janela.
 * Porte fiel de buildRelatorioClosers (api.php): a resposta tem exatamente os mesmos campos e valores.
 */
final class RelatorioService
{
    public function __construct(
        private ContratoRepository $contratos,
        private MetaRepository $metas,
        private VendedorRepository $vendedores,
        private Calendario $calendario,
        private TiposBeneficioService $tipos,
        private ImagemService $imagens,
        private DesempateService $desempate
    ) {
    }

    /** @return array<string, mixed> */
    public function closers(int $ano, int $mes): array
    {
        $mi = $this->calendario->mesInfo($ano, $mes);
        $ano = $mi['ano'];
        $mes = $mi['mes'];
        $hoje = $mi['hoje'];

        $semanas = $this->semanasDoMes($ano, $mes, $hoje);
        $totalSemanas = max(1, count($semanas));

        // Uma leitura só, cobrindo o mês e as semanas (uma semana configurada pode começar no mês anterior ou terminar no seguinte).
        $inicioLeitura = $mi['ini'];
        $fimLeitura = $mi['fim'];
        foreach ($semanas as $w) {
            if ($w['data_inicio'] < $inicioLeitura) {
                $inicioLeitura = $w['data_inicio'];
            }
            if ($w['data_fim'] > $fimLeitura) {
                $fimLeitura = $w['data_fim'];
            }
        }
        $porDia = $this->contratos->porVendedorEDia($inicioLeitura, $fimLeitura);
        $tiposPorDia = $this->contratos->tiposPorVendedorEDia($inicioLeitura, $fimLeitura);
        $metasPeriodo = $this->metas->doCloserNoMes($ano, $mes);   // semana_num 0 = meta do mês

        $estilo = $this->tipos->estilo();
        $datasMes = $this->calendario->datas($mi['ini'], $mi['fim']);
        foreach ($semanas as $i => $w) {
            $semanas[$i]['_datas'] = $this->calendario->datas($w['data_inicio'], $w['data_fim']);
        }

        $vendedores = array_values(array_filter($this->vendedores->paraRelatorio(), static function (array $v) use ($porDia): bool {
            return $v['deleted_at'] === null || array_sum($porDia[(int) $v['id']] ?? []) > 0;   // linha com quantidade 0 não é contrato
        }));

        $usados = [];   // benefícios que aparecem nos números (mesmo os desativados depois)
        $linhas = [];
        foreach ($vendedores as $v) {
            $vid = (int) $v['id'];
            $metasDoCloser = $metasPeriodo[$vid] ?? [];
            $metaSemanalPadrao = max(1, (int) $v['metas']);

            $detalheSemanas = [];
            $batidas = 0;
            foreach ($semanas as $w) {
                $numero = $w['semana_num'];
                $total = 0;
                $porTipo = [];
                foreach ($w['_datas'] as $data) {
                    $total += $porDia[$vid][$data] ?? 0;
                    foreach ($tiposPorDia[$vid][$data] ?? [] as $tipo => $qtd) {
                        $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + $qtd;
                    }
                }
                $metaSemana = (isset($metasDoCloser[$numero]) && $metasDoCloser[$numero] > 0) ? (int) $metasDoCloser[$numero] : $metaSemanalPadrao;
                $bateu = $metaSemana > 0 && $total >= $metaSemana;
                if ($bateu) {
                    $batidas++;
                }
                foreach ($porTipo as $tipo => $qtd) {
                    if ($qtd > 0) {
                        $usados[$tipo] = true;
                    }
                }
                $detalheSemanas[] = ['semana_num' => $numero, 'vendas' => $total, 'meta' => $metaSemana, 'bateu' => $bateu,
                    'tipos' => (object) $porTipo, 'sem_tipo' => max(0, $total - array_sum($porTipo))];
            }

            $vendasMes = 0;
            $tiposMes = [];
            foreach ($datasMes as $data) {
                $vendasMes += $porDia[$vid][$data] ?? 0;
                foreach ($tiposPorDia[$vid][$data] ?? [] as $tipo => $qtd) {
                    $tiposMes[$tipo] = ($tiposMes[$tipo] ?? 0) + $qtd;
                }
            }
            foreach ($tiposMes as $tipo => $qtd) {
                if ($qtd > 0) {
                    $usados[$tipo] = true;
                }
            }

            if (isset($metasDoCloser[0]) && $metasDoCloser[0] > 0) {
                $metaMes = (int) $metasDoCloser[0];
                $origem = 'periodo';
            } elseif ((int) $v['meta_mensal'] > 0) {
                $metaMes = (int) $v['meta_mensal'];
                $origem = 'cadastro';
            } else {
                $metaMes = $metaSemanalPadrao * $totalSemanas;
                $origem = 'projecao';
            }

            $linhas[] = [
                'id' => $vid,
                'nome' => $v['nome'],
                'cor' => $v['cor'] ?: '#d4af37',
                'imageUrl' => $this->imagens->url($v['img_url']),
                'equipe_id' => $v['equipe_id'] !== null ? (int) $v['equipe_id'] : null,
                'equipe_nome' => $v['equipe_nome'],
                'equipe_cor' => $v['equipe_cor'],
                'equipe_lider' => $v['equipe_lider'],
                'e_lider' => $v['equipe_lider'] !== null && (int) $v['lider_id'] === $vid,
                'suspenso' => $v['suspended_at'] !== null,
                'ex_closer' => $v['deleted_at'] !== null,
                'semanas' => $detalheSemanas,
                'metas_sem_batidas' => $batidas,
                'total_semanas' => count($semanas),
                'vendas_mes' => $vendasMes,
                'meta_mes' => $metaMes,
                'meta_mes_origem' => $origem,
                'bateu_mes' => $metaMes > 0 && $vendasMes >= $metaMes,
                'score_mes' => $metaMes > 0 ? (int) round($vendasMes / $metaMes * 100) : 0,
                'tipos' => (object) $tiposMes,
                // Contratos lançados direto no total do dia, sem detalhar o benefício (contratos_historico aceita isso)
                'sem_tipo' => max(0, $vendasMes - array_sum($tiposMes)),
            ];
        }

        // Posição no mês e em cada semana: total, depois os critérios de desempate do admin, depois o nome (a tela e o PDF usam esta posição).
        $posMes = $this->desempate->posicoes(array_map(static fn(array $l): array => ['id' => $l['id'], 'nome' => $l['nome'], 'total' => $l['vendas_mes'], 'tipos' => (array) $l['tipos']], $linhas));
        $posSemana = [];
        foreach ($semanas as $wi => $w) {
            $posSemana[$wi] = $this->desempate->posicoes(array_map(static fn(array $l): array => ['id' => $l['id'], 'nome' => $l['nome'], 'total' => $l['semanas'][$wi]['vendas'], 'tipos' => (array) $l['semanas'][$wi]['tipos']], $linhas));
        }
        foreach ($linhas as $k => $l) {
            $linhas[$k]['posicao_mes'] = $posMes[$l['id']];
            foreach (array_keys($semanas) as $wi) {
                $linhas[$k]['semanas'][$wi]['posicao'] = $posSemana[$wi][$l['id']];
            }
        }

        // Ativos primeiro, ex-closers no fim; alfabético dentro de cada grupo (a tela reordena por desempenho).
        usort($linhas, static function (array $a, array $b): int {
            if ($a['ex_closer'] !== $b['ex_closer']) {
                return $a['ex_closer'] ? 1 : -1;
            }
            return strcasecmp($a['nome'], $b['nome']);
        });

        // Benefícios na ordem escolhida pelo admin: os ativos + os desativados que ainda têm contrato no período.
        $tipos = [];
        foreach ($estilo as $chave => $e) {
            if (!$e['ativo'] && empty($usados[$chave])) {
                continue;
            }
            $tipos[] = ['k' => $chave, 'label' => $e['label'], 'cor' => $e['cor'], 'icone' => $e['icone'], 'ativo' => (bool) $e['ativo']];
        }
        foreach ($usados as $chave => $_) {   // tipo gravado que nem consta mais na lista (não deve ocorrer, mas não some do total)
            if (!isset($estilo[$chave])) {
                $tipos[] = ['k' => $chave, 'label' => $chave, 'cor' => TiposBeneficioService::cor((string) $chave), 'icone' => 'file', 'ativo' => false];
            }
        }
        foreach ($semanas as $i => $_) {
            unset($semanas[$i]['_datas']);
        }

        return [
            'ano' => $ano, 'mes' => $mes, 'hoje' => $hoje, 'estado' => $mi['estado'],
            'mes_inicio' => $mi['ini'], 'mes_fim' => $mi['fim'], 'dias_mes' => $mi['dias_mes'], 'dias_decorridos' => $mi['dias_decorridos'],
            'weeks' => $semanas, 'tipos' => $tipos, 'data' => $linhas,
        ];
    }

    /**
     * As semanas do mês com o estado de cada uma em relação a hoje (futura, atual ou encerrada) e quantos dias já decorreram.
     * @return array<int, array<string, mixed>>
     */
    private function semanasDoMes(int $ano, int $mes, string $hoje): array
    {
        $saida = [];
        foreach ($this->calendario->semanasDoMes($ano, $mes) as $w) {
            $dias = count($this->calendario->datas($w['start'], $w['end']));
            if ($hoje < $w['start']) {
                $estado = 'futura';
                $decorridos = 0;
            } elseif ($hoje > $w['end']) {
                $estado = 'encerrada';
                $decorridos = $dias;
            } else {
                $estado = 'atual';
                $decorridos = count($this->calendario->datas($w['start'], $hoje));
            }
            $saida[] = ['semana_num' => $w['semana_num'], 'data_inicio' => $w['start'], 'data_fim' => $w['end'],
                'dias' => $dias, 'dias_decorridos' => $decorridos, 'estado' => $estado];
        }
        return $saida;
    }
}
