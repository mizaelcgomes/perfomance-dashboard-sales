<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\ContratoRepository;
use App\Repositories\MetaRepository;
use App\Repositories\VendedorRepository;
use DateTime;

/**
 * "Meus contratos" do portal do closer (e do líder, que também é closer): os contratos do PRÓPRIO closer por dia (a evolução), os benefícios
 * fechados e a meta do período, em Hoje · Semana · Mês. Porte fiel da ação "contratos" do closer-api.php e das regras de período do
 * closer-lib.php (cr_ct_range, cr_month_weeks, cr_tipo_label): mesma fonte do Painel (contratos_historico por dia e contratos_tipos por benefício,
 * com a descrição do "Outros"), a mesma meta do ranking e a mesma resposta. O closer vem SEMPRE da sessão; os contratos de closer de equipe oculta
 * da TV não aparecem (o ContratoRepository já os deixa de fora).
 */
final class ContratosCloserService
{
    public function __construct(
        private ContratoRepository $contratos,
        private MetaRepository $metas,
        private VendedorRepository $vendedores,
        private Calendario $calendario,
        private TiposBeneficioService $tipos
    ) {
    }

    /**
     * O período pedido (hoje, semana de um mês ou mês; qualquer outro valor vira semana) e o período de comparação.
     * Sem escolha, vale o mês atual e a semana que contém hoje. Só conta até hoje (eff_end). prev_* = a comparação: o dia anterior, a semana
     * imediatamente antes (mesmo nº de dias decorridos) ou o mesmo nº de dias iniciais do mês anterior.
     * @return array<string, mixed>
     */
    public function intervalo(string $periodo, int $ano = 0, int $mes = 0, int $sem = 0): array
    {
        $hoje = Relogio::hoje();
        $fuso = Relogio::fuso();
        $anoAtual = (int) substr($hoje, 0, 4);
        $mesAtual = (int) substr($hoje, 5, 2);
        if ($periodo !== 'mes' && $periodo !== 'hoje') {
            $periodo = 'semana';
        }
        // mês escolhido: o atual por padrão; inválido ou futuro volta para o atual
        if ($ano < 2000 || $mes < 1 || $mes > 12 || $ano * 12 + $mes > $anoAtual * 12 + $mesAtual) {
            $ano = $anoAtual;
            $mes = $mesAtual;
        }
        $semanas = [];
        $semNum = null;
        if ($periodo === 'hoje') {
            $ini = $fim = $hoje;
            $ano = $anoAtual;
            $mes = $mesAtual;
        } elseif ($periodo === 'mes') {
            $ini = sprintf('%04d-%02d-01', $ano, $mes);
            $fim = (new DateTime($ini, $fuso))->format('Y-m-t');
        } else {
            $semanas = $this->calendario->semanasDoMes($ano, $mes);
            $escolhida = null;
            foreach ($semanas as $w) {
                if ($w['semana_num'] === $sem) {
                    $escolhida = $w;
                }
            }
            if (!$escolhida && $semanas) {
                $escolhida = $semanas[0];                                       // mês passado: 1ª semana
                if ($ano === $anoAtual && $mes === $mesAtual) {                 // mês atual: a que contém hoje (ou a mais recente)
                    $contem = null;
                    $recente = null;
                    foreach ($semanas as $w) {
                        if ($w['start'] <= $hoje) {
                            $recente = $w;
                            if ($w['end'] >= $hoje) {
                                $contem = $w;
                            }
                        }
                    }
                    $escolhida = $contem ?: ($recente ?: $escolhida);
                }
            }
            if ($escolhida) {
                $ini = $escolhida['start'];
                $fim = $escolhida['end'];
                $semNum = $escolhida['semana_num'];
            } else {
                $ini = sprintf('%04d-%02d-01', $ano, $mes);
                $fim = (new DateTime($ini, $fuso))->format('Y-m-t');
            }
        }
        $efetivo = min($fim, $hoje);
        if ($efetivo < $ini) {
            $efetivo = $ini;
        }
        $dias = (new DateTime($ini, $fuso))->diff(new DateTime($efetivo, $fuso))->days + 1;
        if ($periodo === 'semana' || $periodo === 'hoje') {
            $antFim = (new DateTime($ini, $fuso))->modify('-1 day')->format('Y-m-d');
            $antIni = (new DateTime($antFim, $fuso))->modify('-' . ($dias - 1) . ' days')->format('Y-m-d');
        } else {
            $mesAnterior = (new DateTime($ini, $fuso))->modify('-1 month');
            $antIni = $mesAnterior->format('Y-m-01');
            $antFim = min((new DateTime($antIni, $fuso))->modify('+' . ($dias - 1) . ' days')->format('Y-m-d'), $mesAnterior->format('Y-m-t'));
        }
        return ['periodo' => $periodo, 'ano' => $ano, 'mes' => $mes, 'semana' => $semNum, 'semanas' => $semanas,
            'start' => $ini, 'end' => $fim, 'eff_end' => $efetivo, 'hoje' => $hoje, 'dias' => $dias, 'prev_start' => $antIni, 'prev_end' => $antFim];
    }

    /**
     * A resposta da ação "contratos" (sem o "success").
     * @param array<string, mixed> $in periodo, ano, mes, semana
     * @return array<string, mixed>
     */
    public function contratos(int $vendedorId, array $in): array
    {
        $rg = $this->intervalo((string) ($in['periodo'] ?? 'semana'), (int) ($in['ano'] ?? 0), (int) ($in['mes'] ?? 0), (int) ($in['semana'] ?? 0));
        $hoje = $rg['hoje'];
        $fuso = Relogio::fuso();
        $porDia = $this->contratos->totaisPorDia($rg['start'], $rg['eff_end'], $vendedorId);
        $total = array_sum($porDia);

        // um ponto por dia do período; dias futuros ficam vazios (null)
        $serie = [];
        $melhor = null;
        for ($d = new DateTime($rg['start'], $fuso), $f = new DateTime($rg['end'], $fuso); $d <= $f; $d->modify('+1 day')) {
            $dia = $d->format('Y-m-d');
            $q = $dia <= $hoje ? ($porDia[$dia] ?? 0) : null;
            $serie[] = ['data' => $dia, 'qtd' => $q];
            if ($q !== null && $q > 0 && ($melhor === null || $q > $melhor['qtd'])) {
                $melhor = ['data' => $dia, 'qtd' => $q];
            }
        }
        $media = $rg['dias'] > 0 ? round($total / $rg['dias'], 1) : 0;

        $anterior = (int) array_sum($this->contratos->totaisPorDia($rg['prev_start'], $rg['prev_end'], $vendedorId));
        // limites da navegação por mês: do primeiro mês com contrato lançado até o mês atual
        $primeiro = $this->contratos->primeiroDia($vendedorId);
        $mesMax = substr($hoje, 0, 7);
        $mesMin = $primeiro ? min(substr($primeiro, 0, 7), $mesMax) : $mesMax;
        $semanasSaida = array_map(static function (array $w) use ($hoje): array {
            return ['num' => $w['semana_num'], 'start' => $w['start'], 'end' => $w['end'], 'atual' => $w['start'] <= $hoje && $w['end'] >= $hoje, 'futura' => $w['start'] > $hoje];
        }, $rg['semanas']);

        [$beneficios, $comTipo] = $this->beneficios($vendedorId, $rg, $total);

        return ['periodo' => $rg['periodo'], 'ano' => $rg['ano'], 'mes' => $rg['mes'], 'semana' => $rg['semana'], 'meta' => $this->meta($vendedorId, $rg),
            'semanas' => $semanasSaida, 'mes_min' => $mesMin, 'mes_max' => $mesMax,
            'range' => ['start' => $rg['start'], 'end' => $rg['end'], 'hoje' => $hoje],
            'serie' => $serie, 'total' => $total, 'media' => $media, 'melhor' => $melhor, 'anterior' => $anterior,
            'beneficios' => $beneficios, 'com_tipo' => $comTipo, 'sem_tipo' => max(0, $total - $comTipo)];
    }

    /**
     * Benefícios fechados no período ("Outros" com descrição vira um item por descrição), com a cor e o ícone do admin, em ordem de quantidade
     * (o "Outros" por último) e a porcentagem de cada um. Contratos lançados sem tipo entram como "sem tipo".
     * @param array<string, mixed> $rg
     * @return array{0: array<int, array<string, mixed>>, 1: int} os benefícios e quantos contratos têm tipo
     */
    private function beneficios(int $vendedorId, array $rg, int $total): array
    {
        $ativos = $this->tipos->ativos();
        $estilo = $this->tipos->estilo();
        $itens = [];
        foreach ($this->contratos->tiposComDescricao($rg['start'], $rg['eff_end'], $vendedorId) as $r) {
            $tipo = (string) $r['tipo'];
            $desc = trim((string) $r['tipo_descricao']);
            $chave = ($tipo === 'outros' && $desc !== '') ? 'outros:' . $desc : $tipo;
            if (!isset($itens[$chave])) {
                $itens[$chave] = [
                    'chave' => $chave,
                    'label' => ($tipo === 'outros' && $desc !== '') ? 'Outros: ' . $desc : ($ativos[$tipo] ?? ucfirst(str_replace('_', ' ', $tipo))),
                    'qtd' => 0,
                    'cor' => $estilo[$tipo]['cor'] ?? TiposBeneficioService::cor($tipo),                                    // mesma cor e ícone do admin
                    'icone' => $estilo[$tipo]['icone'] ?? ($tipo === 'outros' ? 'more' : 'file'),
                ];
            }
            $itens[$chave]['qtd'] += (int) $r['total'];
        }
        $itens = array_values($itens);
        usort($itens, static fn(array $a, array $b): int => TiposBeneficioService::ehOutros($a['chave']) <=> TiposBeneficioService::ehOutros($b['chave'])
            ?: ($b['qtd'] <=> $a['qtd']) ?: strcmp($a['label'], $b['label']));
        $comTipo = (int) array_sum(array_column($itens, 'qtd'));
        $den = max($total, $comTipo);
        foreach ($itens as &$b) {
            $b['pct'] = $den > 0 ? round($b['qtd'] / $den * 100, 1) : 0;
        }
        unset($b);
        return [$itens, $comTipo];
    }

    /**
     * A meta do período (a mesma regra do ranking: meta por período do admin; sem ela, a meta base do closer). Em "hoje", a meta diária, se houver.
     * @param array<string, mixed> $rg
     */
    private function meta(int $vendedorId, array $rg): ?int
    {
        $base = $this->vendedores->metasDoCloser($vendedorId) ?? ['metas' => 1, 'meta_mensal' => 0];
        if ($rg['periodo'] === 'semana' && $rg['semana']) {
            $x = (int) ($this->metas->doCloserNaSemana($rg['ano'], $rg['mes'], (int) $rg['semana'])[$vendedorId] ?? 0);
            return $x > 0 ? $x : max(1, (int) $base['metas']);
        }
        if ($rg['periodo'] === 'mes') {
            $x = (int) ($this->metas->doCloserNoMes($rg['ano'], $rg['mes'])[$vendedorId][0] ?? 0);
            if ($x > 0) {
                return $x;
            }
            return (int) $base['meta_mensal'] > 0
                ? (int) $base['meta_mensal']
                : max(1, (int) $base['metas']) * max(1, count($this->calendario->semanasDoMes($rg['ano'], $rg['mes'])));
        }
        if ($rg['periodo'] === 'hoje') {
            $dia = (int) substr($rg['hoje'], 8, 2);
            foreach ($this->metas->diariasDoMes((int) substr($rg['hoje'], 0, 4), (int) substr($rg['hoje'], 5, 2)) as $d) {
                if ((int) $d['closer_id'] === $vendedorId && (int) $d['dia'] === $dia) {
                    return (int) $d['meta'];
                }
            }
        }
        return null;
    }
}
