<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\AtendimentoRepository;
use App\Repositories\ContratoRepository;
use App\Repositories\EquipeRepository;
use App\Repositories\MetaRepository;
use App\Repositories\VendedorRepository;
use DateTime;

/**
 * Retrospectiva da TV ("Hall da Fama"): ranking dos closers e a melhor equipe de um período JÁ ENCERRADO (semana anterior e mês
 * anterior). Mesmas regras do ranking ao vivo: meta efetiva por período, desempate do admin e contratos de ex-membros somando na
 * equipe. Reproduz get_retrospectiva e computeRetroPeriodo do api.php.
 */
final class RetrospectivaService
{
    private const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public function __construct(
        private Calendario $calendario,
        private DesempateService $desempate,
        private VendedorRepository $vendedores,
        private EquipeRepository $equipes,
        private ContratoRepository $contratos,
        private AtendimentoRepository $atendimentos,
        private MetaRepository $metas,
        private ImagemService $imagens
    ) {
    }

    /**
     * Os dois períodos que a TV mostra: 'semana' (a anterior) e 'mes' (o mês-calendário anterior), cada um com rótulo e dias/semanas.
     * @return array{semana: array<string, mixed>, mes: array<string, mixed>}
     */
    public function retrospectiva(): array
    {
        $tz = Relogio::fuso();
        $prev = $this->calendario->semanaAnterior();
        $anoS = $prev['ano'] ?: (int) substr($prev['start'], 0, 4);
        $mesS = $prev['mes'] ?: (int) substr($prev['start'], 5, 2);
        $semana = $this->periodo('semana', $prev['start'], $prev['end'], $anoS, $mesS, $prev['semana_num']);
        $semana['label'] = 'Semana anterior · ' . substr($prev['start'], 8, 2) . '/' . substr($prev['start'], 5, 2) . ' a ' . substr($prev['end'], 8, 2) . '/' . substr($prev['end'], 5, 2);
        $semana['dias'] = array_map(static fn(string $d): string => substr($d, 8, 2), $this->calendario->datas($prev['start'], $prev['end']));

        $mPrev = (new DateTime(Relogio::agora()->format('Y-m-01'), $tz))->modify('-1 month');
        $anoM = (int) $mPrev->format('Y');
        $mesM = (int) $mPrev->format('n');
        $mes = $this->periodo('mes', $mPrev->format('Y-m-01'), $mPrev->format('Y-m-t'), $anoM, $mesM, null);
        $mes['label'] = 'Mês anterior · ' . self::MESES[$mesM - 1] . ' ' . $anoM;
        $mes['semanas'] = array_map(static fn(array $w): string => 'S' . $w['semana_num'], $this->calendario->semanasDoMes($anoM, $mesM));
        return ['semana' => $semana, 'mes' => $mes];
    }

    /**
     * Ranking de closers e de equipes de um período encerrado. $tipo = 'semana' | 'mes'; $semanaNum só vale para a semana
     * (meta por período daquela semana).
     * @return array<string, mixed>
     */
    public function periodo(string $tipo, string $ini, string $fim, int $ano, int $mes, ?int $semanaNum): array
    {
        $ordem = $this->desempate->ordem();
        $semanasMes = $tipo === 'mes' ? $this->calendario->semanasDoMes($ano, $mes) : [];
        $nSem = max(1, count($semanasMes));

        // Leitura única: o período (+ as semanas do calendário do mês, que podem passar das bordas dele)
        $lerIni = $ini;
        $lerFim = $fim;
        foreach ($semanasMes as $w) {
            if ($w['start'] < $lerIni) {
                $lerIni = $w['start'];
            }
            if ($w['end'] > $lerFim) {
                $lerFim = $w['end'];
            }
        }
        $dia = $this->contratos->porVendedorEDia($lerIni, $lerFim);

        $datas = $this->calendario->datas($ini, $fim);
        $somaV = static function (int $vid, array $lista) use ($dia): int {
            $s = 0;
            foreach ($lista as $d) {
                $s += $dia[$vid][$d] ?? 0;
            }
            return $s;
        };
        $total = 0;
        foreach ($dia as $vid => $porDia) {
            $total += $somaV($vid, $datas);        // inclui ex-closers e arquivados
        }
        $semDatas = [];
        foreach ($semanasMes as $w) {
            $semDatas[] = $this->calendario->datas($w['start'], $w['end']);
        }

        $tipoMap = $this->contratos->tiposPorVendedor($ini, $fim);
        $atMap = $this->atendimentos->totaisPorVendedor($ini, $fim);
        $mp = $this->metas->doCloserNoMes($ano, $mes);
        $ep = $this->metas->daEquipeNoMes($ano, $mes);
        $vend = $this->vendedores->ativosParaRanking();

        $cmp = function ($a, $b, string $nomeKey) use ($ordem): int {
            if ($b['score'] !== $a['score']) {
                return $b['score'] - $a['score'];
            }
            foreach ($ordem as $criterio) {
                $c = $this->desempate->comparar($a, $b, $criterio, 'tipo_totals', 'conv_rate');
                if ($c !== 0) {
                    return $c;
                }
            }
            return strcmp($a[$nomeKey], $b[$nomeKey]);
        };

        $closers = [];
        foreach ($vend as $v) {
            $vid = (int) $v['id'];
            $vendas = $somaV($vid, $datas);
            $base = max(1, (int) $v['metas']);
            $pm = $mp[$vid] ?? [];
            if ($tipo === 'semana') {
                $meta = ($semanaNum !== null && isset($pm[$semanaNum]) && $pm[$semanaNum] > 0) ? $pm[$semanaNum] : $base;
            } else {
                $meta = (isset($pm[0]) && $pm[0] > 0) ? $pm[0] : ((int) $v['meta_mensal'] > 0 ? (int) $v['meta_mensal'] : $base * $nSem);
            }
            $at = $atMap[$vid] ?? 0;
            $row = [
                'id' => $vid, 'name' => $v['nome'], 'vendas' => $vendas, 'metas' => $meta, 'meta_base' => $base,
                'score' => (int) round($vendas / max(1, $meta) * 100), 'faltam' => max(0, $meta - $vendas), 'bateu' => $vendas >= $meta,
                'color' => $v['cor'], 'img' => $v['img_pravatar'] ? (int) $v['img_pravatar'] : null, 'imageUrl' => $this->imagens->url($v['img_url']),
                'equipe_id' => $v['equipe_id'] ? (int) $v['equipe_id'] : null, 'equipe_nome' => $v['equipe_nome'], 'equipe_cor' => $v['equipe_cor'],
                'tipo_totals' => $tipoMap[$vid] ?? [], 'conv_rate' => $at > 0 ? round($vendas / $at * 100, 1) : 0.0,
            ];
            if ($tipo === 'semana') {
                $row['daily'] = array_map(static fn($d) => $dia[$vid][$d] ?? 0, $datas);
            } else {
                $row['week_scores'] = array_map(static fn($l) => $somaV($vid, $l), $semDatas);
            }
            $closers[] = $row;
        }
        usort($closers, static fn($a, $b) => $cmp($a, $b, 'name'));
        foreach ($closers as $i => &$c) {
            $c['pos'] = $i + 1;
        }
        unset($c);

        // Equipes: membros ativos + contratos de ex-membros/suspensos (como no ranking ao vivo)
        $nSlots = $tipo === 'semana' ? count($datas) : count($semDatas);
        $equipes = [];
        $eqRows = $this->equipes->todasParaTv();      // equipe oculta da TV não entra no Hall da Fama
        $exRows = $this->vendedores->inativosComEquipe();
        foreach ($eqRows as $e) {
            $eid = (int) $e['id'];
            $t = ['id' => $eid, 'nome' => $e['nome'], 'cor' => $e['cor'], 'imageUrl' => $this->imagens->url($e['img_url']), 'vendas' => 0, 'members' => 0,
                  'soma_base' => 0, 'soma_meta' => 0, 'slots' => array_fill(0, $nSlots, 0), 'tipo_totals' => [], 'conv_rate' => 0.0];
            foreach ($closers as $c) {
                if ($c['equipe_id'] !== $eid) {
                    continue;
                }
                $t['vendas'] += $c['vendas'];
                $t['members']++;
                $t['soma_base'] += $c['meta_base'];
                $t['soma_meta'] += $c['metas'];
                foreach (($tipo === 'semana' ? $c['daily'] : $c['week_scores']) as $i => $q) {
                    $t['slots'][$i] += $q;
                }
                foreach ($c['tipo_totals'] as $tp => $q) {
                    $t['tipo_totals'][$tp] = ($t['tipo_totals'][$tp] ?? 0) + $q;
                }
            }
            foreach ($exRows as $x) {
                if ((int) $x['equipe_id'] !== $eid) {
                    continue;
                }
                $xv = (int) $x['id'];
                $t['vendas'] += $somaV($xv, $datas);
                foreach ($tipoMap[$xv] ?? [] as $tp => $q) {
                    $t['tipo_totals'][$tp] = ($t['tipo_totals'][$tp] ?? 0) + $q;
                }
                if ($tipo === 'semana') {
                    foreach ($datas as $i => $d) {
                        $t['slots'][$i] += $dia[$xv][$d] ?? 0;
                    }
                } else {
                    foreach ($semDatas as $i => $l) {
                        $t['slots'][$i] += $somaV($xv, $l);
                    }
                }
            }
            if ($t['members'] === 0 && $t['vendas'] === 0) {
                continue;
            }
            $epe = $ep[$eid] ?? [];
            if ($tipo === 'semana') {
                $meta = ($semanaNum !== null && isset($epe[$semanaNum]) && $epe[$semanaNum] > 0) ? $epe[$semanaNum] : ((int) $e['meta_semanal'] > 0 ? (int) $e['meta_semanal'] : max(1, $t['soma_meta']));
            } else {
                $meta = (isset($epe[0]) && $epe[0] > 0) ? $epe[0] : ((int) $e['meta_mensal'] > 0 ? (int) $e['meta_mensal'] : max(1, $t['soma_base'] * $nSem));
            }
            $t['metas'] = $meta;
            $t['score'] = (int) round($t['vendas'] / max(1, $meta) * 100);
            $t['faltam'] = max(0, $meta - $t['vendas']);
            $t['bateu'] = $t['vendas'] >= $meta;
            $t[$tipo === 'semana' ? 'daily' : 'week_scores'] = $t['slots'];
            unset($t['slots'], $t['soma_base'], $t['soma_meta']);
            $equipes[] = $t;
        }
        usort($equipes, static fn($a, $b) => $cmp($a, $b, 'nome'));
        foreach ($equipes as $i => &$t) {
            $t['pos'] = $i + 1;
        }
        unset($t);
        $melhor = ($equipes && $equipes[0]['vendas'] > 0) ? $equipes[0] : null;

        return [
            'inicio' => $ini, 'fim' => $fim, 'total' => $total,
            'bateram' => count(array_filter($closers, static fn($c) => $c['bateu'])),
            'closers' => $closers, 'equipes' => $equipes, 'melhor_equipe' => $melhor,
            'vice' => ($melhor && isset($equipes[1])) ? ['nome' => $equipes[1]['nome'], 'score' => $equipes[1]['score'], 'vendas' => $equipes[1]['vendas']] : null,
        ];
    }
}
