<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\MetaRepository;

/**
 * A aba "Metas & Regras" do admin: o estado das metas de um mês, com o PADRÃO de cada célula e as EXCEÇÕES. Não existe uma tabela de
 * "meta padrão": cada closer tem a meta base (vendedores.metas / meta_mensal) e pode ter metas por período (metas_periodo). O PADRÃO de cada
 * célula (S1…Sn e Mês) é o valor que a MAIORIA dos closers tem nela (empate: o maior). EXCEÇÃO = closer com algum valor diferente do padrão.
 * Assim as telas que já leem a meta efetiva (Painel, Ranking, TV, Relatórios) continuam iguais: a regra de cálculo não muda.
 * Equipes e Comercial (geral) têm a mesma visão por exceção, com o padrão sendo a soma automática das metas dos closers ativos.
 * Porte fiel de mxEstado, mgEstado e das leituras de meta diária do api.php.
 */
final class MetasService
{
    public function __construct(
        private MetaRepository $metas,
        private Calendario $calendario,
        private ConfiguracaoRepository $config
    ) {
    }

    /** Valor mais comum (empate: o maior); null se a lista é vazia. @param array<int, int|string> $valores */
    public static function moda(array $valores): ?int
    {
        if (!$valores) {
            return null;
        }
        $c = array_count_values(array_map('intval', $valores));
        $melhor = null;
        $n = 0;
        foreach ($c as $v => $q) {
            if ($q > $n || ($q === $n && (int) $v > (int) $melhor)) {
                $melhor = (int) $v;
                $n = $q;
            }
        }
        return $melhor;
    }

    /**
     * Meta que o closer tem numa célula quando NÃO há meta por período: semana = metas; mês = meta_mensal ou metas × nº de semanas.
     * @param array<string, mixed> $v
     * @return array{sem: int, mes: int}
     */
    public static function base(array $v, int $nSem): array
    {
        $sem = max(1, (int) $v['metas']);
        return ['sem' => $sem, 'mes' => ((int) $v['meta_mensal'] > 0) ? (int) $v['meta_mensal'] : $sem * max(1, $nSem)];
    }

    /**
     * Estado das metas dos closers num mês: semanas, padrão por célula, e cada closer com suas metas efetivas e diferenças.
     * $excluir = closer que NÃO conta na hora de achar o padrão (usado ao remover a exceção dele). A chave "_bases" é interna (as Actions a usam
     * para gravar; a resposta ao admin vai sem ela, ver estadoPublico).
     * @return array<string, mixed>
     */
    public function estado(int $ano, int $mes, ?int $excluir = null): array
    {
        $semanas = array_map(
            static fn(array $w): array => ['num' => (int) $w['semana_num'], 'start' => $w['start'], 'end' => $w['end']],
            $this->calendario->semanasDoMes($ano, $mes)
        );
        $nums = array_column($semanas, 'num');
        $nSem = max(1, count($nums));
        $cl = $this->metas->closersParaMetas();
        $pm = $this->metas->doTipoNoMes('closer', $ano, $mes);

        $celulas = array_merge($nums, [0]);
        $efet = [];
        $bases = [];
        foreach ($cl as $v) {
            $id = (int) $v['id'];
            $b = self::base($v, $nSem);
            $bases[$id] = $b;
            foreach ($celulas as $c) {
                $ov = $pm[$id][$c] ?? 0;
                $efet[$id][$c] = $ov > 0 ? $ov : ($c === 0 ? $b['mes'] : $b['sem']);
            }
        }
        $padrao = [];
        foreach ($celulas as $c) {
            $vals = [];
            foreach ($cl as $v) {
                if ((int) $v['id'] !== $excluir) {
                    $vals[] = $efet[(int) $v['id']][$c];
                }
            }
            $padrao[$c] = self::moda($vals);
        }
        $closers = [];
        $exc = 0;
        foreach ($cl as $v) {
            $id = (int) $v['id'];
            $diffs = [];
            foreach ($celulas as $c) {
                if ($padrao[$c] !== null && $efet[$id][$c] !== $padrao[$c]) {
                    $diffs[] = ['cel' => $c, 'valor' => $efet[$id][$c], 'padrao' => $padrao[$c]];
                }
            }
            if ($diffs) {
                $exc++;
            }
            $closers[] = [
                'id' => $id, 'nome' => $v['nome'], 'equipe_id' => $v['equipe_id'] ? (int) $v['equipe_id'] : null, 'equipe_nome' => $v['equipe_nome'],
                'ferias' => $v['suspended_at'] !== null, 'efetivas' => $efet[$id], 'diffs' => $diffs, 'excecao' => (bool) $diffs,
            ];
        }
        $sems = array_values(array_unique(array_map(static fn($c) => $padrao[$c], $nums)));
        return [
            'ano' => $ano, 'mes' => $mes, 'semanas' => $semanas, 'padrao' => $padrao,
            'padrao_semanal' => (count($sems) === 1) ? $sems[0] : null,
            'total_closers' => count($cl), 'excecoes_n' => $exc, 'seguem' => count($cl) - $exc,
            'closers' => $closers, '_bases' => $bases,
        ];
    }

    /** O estado sem a chave interna "_bases". @return array<string, mixed> */
    public function estadoPublico(int $ano, int $mes): array
    {
        $e = $this->estado($ano, $mes);
        unset($e['_bases']);
        return $e;
    }

    /**
     * Grava (ou limpa) a meta de uma célula de um closer: valor igual ao que ele já teria sem exceção apaga a linha.
     * @param array{sem: int, mes: int} $base
     */
    public function gravarCelula(int $closerId, int $ano, int $mes, int $celula, int $valor, array $base): void
    {
        $padraoDoCloser = $celula === 0 ? $base['mes'] : $base['sem'];
        if ($valor === $padraoDoCloser) {
            $this->metas->apagarPeriodo('closer', $closerId, $ano, $mes, $celula);
            return;
        }
        $this->metas->salvarPeriodo('closer', $closerId, $ano, $mes, $celula, $valor);
    }

    /**
     * Metas de Equipes e do Comercial (geral): cada item com a soma automática dos closers ativos (auto), a meta fixa definida (fixas) e as
     * diferenças. Na equipe conta também a meta fixa antiga do cadastro (equipes.meta_semanal e meta_mensal), que o formulário da equipe não
     * edita mais. Férias não somam na meta (como o Painel).
     * @return array<string, mixed>
     */
    public function estadoGrupo(string $escopo, int $ano, int $mes): array
    {
        $est = $this->estadoPublico($ano, $mes);
        $cels = array_keys($est['padrao']);
        $ativos = array_values(array_filter($est['closers'], static fn($c) => !$c['ferias']));
        $soma = static function (array $lista) use ($cels): array {
            $o = [];
            foreach ($cels as $c) {
                $o[$c] = 0;
                foreach ($lista as $x) {
                    $o[$c] += (int) $x['efetivas'][$c];
                }
            }
            return $o;
        };
        $linhas = $this->metas->fixasDoTipoNoMes($escopo, $ano, $mes);

        $defs = [];
        if ($escopo === 'geral') {
            $defs[] = ['id' => 0, 'nome' => 'Comercial (Geral)', 'cor' => null, 'membros' => $ativos, 'leg' => null];
        } else {
            foreach ($this->metas->equipesParaMetas() as $e) {
                $eid = (int) $e['id'];
                $defs[] = [
                    'id' => $eid, 'nome' => $e['nome'], 'cor' => $e['cor'],
                    'membros' => array_values(array_filter($ativos, static fn($c) => $c['equipe_id'] === $eid)),
                    'leg' => ((int) $e['meta_semanal'] > 0 || (int) $e['meta_mensal'] > 0) ? ['sem' => (int) $e['meta_semanal'], 'mes' => (int) $e['meta_mensal']] : null,
                ];
            }
        }
        $itens = [];
        $exc = 0;
        foreach ($defs as $d) {
            $auto = $soma($d['membros']);
            $fixas = [];
            $diffs = [];
            foreach ($cels as $c) {
                $v = $linhas[$d['id']][$c] ?? 0;
                if ($v <= 0 && $d['leg']) {
                    $v = $c === 0 ? $d['leg']['mes'] : $d['leg']['sem'];
                }
                if ($v > 0) {
                    $fixas[$c] = $v;
                    if ($v !== $auto[$c]) {
                        $diffs[] = ['cel' => $c, 'valor' => $v, 'padrao' => $auto[$c]];   // só é exceção o que difere da soma
                    }
                }
            }
            if ($diffs) {
                $exc++;
            }
            $itens[] = [
                'id' => $d['id'], 'nome' => $d['nome'], 'cor' => $d['cor'], 'closers' => count($d['membros']), 'auto' => (object) $auto,
                'fixas' => (object) $fixas, 'diffs' => $diffs, 'excecao' => (bool) $diffs, 'legado' => $d['leg'],
            ];
        }
        return [
            'escopo' => $escopo, 'ano' => $ano, 'mes' => $mes, 'semanas' => $est['semanas'], 'total_closers' => count($ativos),
            'total' => count($itens), 'excecoes_n' => $exc, 'seguem' => count($itens) - $exc, 'auto_total' => (object) $soma($ativos), 'itens' => $itens,
        ];
    }

    /**
     * O template de meta diária aplicado no mês (tipo, segs, base e aplicado_em), ou null se nenhum foi aplicado.
     * @return array<string, mixed>|null
     */
    public function diariaAplicada(int $ano, int $mes): ?array
    {
        $j = json_decode((string) $this->config->valor(MetaDiaria::chave($ano, $mes), ''), true);
        return (is_array($j) && isset($j['tipo'])) ? $j : null;
    }

    /**
     * Estado das metas diárias do mês: cada closer com a série gravada, a esperada (pelo template aplicado ou pela distribuição mais comum) e se
     * é exceção.
     * @return array<string, mixed> o corpo da resposta, sem o "success"
     */
    public function estadoDiario(int $ano, int $mes): array
    {
        $dias = MetaDiaria::diasDoMes($ano, $mes);
        $est = $this->estado($ano, $mes);
        $apl = $this->diariaAplicada($ano, $mes);
        $grav = $this->metas->diariasPorCloserNoMes($ano, $mes);
        $closers = [];
        $exc = 0;
        [$comum, $nComum] = $apl ? [null, 0] : MetaDiaria::comum($grav, array_column($est['closers'], 'id'));
        foreach ($est['closers'] as $c) {
            $id = $c['id'];
            $serie = $grav[$id] ?? [];
            $esp = MetaDiaria::esperada($apl, $id, (int) $c['efetivas'][0], $dias);
            if ($apl) {
                $isExc = !MetaDiaria::iguais($serie, $esp ?? []);
                $motivo = $isExc ? ($serie ? 'diferente' : 'sem_meta') : null;
            } else {
                $isExc = (bool) $serie && ($comum === null || !MetaDiaria::iguais($serie, $comum));
                $motivo = $isExc ? 'sem_template' : null;
                $esp = $comum;
            }
            if ($isExc) {
                $exc++;
            }
            $closers[] = [
                'id' => $id, 'nome' => $c['nome'], 'equipe_id' => $c['equipe_id'], 'equipe_nome' => $c['equipe_nome'], 'ferias' => $c['ferias'],
                'meta_mes' => (int) $c['efetivas'][0], 'tem_serie' => (bool) $serie, 'serie' => (object) $serie, 'esperada' => $esp === null ? null : (object) $esp,
                'excecao' => $isExc, 'motivo' => $motivo,
            ];
        }
        return [
            'ano' => $ano, 'mes' => $mes, 'dias' => $dias, 'aplicada' => $apl, 'padrao_mes' => $est['padrao'][0],
            'comum' => $comum === null ? null : ['serie' => (object) $comum, 'n' => $nComum],
            'total_closers' => count($closers), 'excecoes_n' => $exc, 'seguem' => count($closers) - $exc, 'closers' => $closers,
        ];
    }

    // ── Fase 5: closer novo nasce no padrão (o cadastro não pede mais meta) ─────────────────────────

    /**
     * Metas iniciais de um closer novo: a meta base é a que a maioria dos closers já tem, e nos meses que têm metas por período (o atual e os
     * próximos) ele recebe o padrão daquele mês. Assim não nasce como "exceção" na aba Metas & Regras. Chamar ANTES de criar o closer (o
     * padrão não pode contar com ele). Porte fiel de mxHeranca.
     * @return array{metas: int, mensal: int, meses: array<int, array<string, mixed>>}
     */
    public function heranca(): array
    {
        $b = $this->metas->metasBaseDosAtivos();
        $h = [
            'metas' => $b ? max(1, (int) self::moda(array_column($b, 'metas'))) : 20,
            'mensal' => $b ? (int) self::moda(array_column($b, 'meta_mensal')) : 0,
            'meses' => [],
        ];
        $agora = Relogio::agora();
        $y = (int) $agora->format('Y');
        $m = (int) $agora->format('n');
        $meses = array_merge([[$y, $m]], $this->metas->mesesComMetaDeCloserDepoisDe($y, $m));
        foreach ($meses as $am) {
            $e = $this->estado($am[0], $am[1]);
            if ($e['total_closers']) {
                $h['meses'][] = ['ano' => $am[0], 'mes' => $am[1], 'padrao' => $e['padrao']];
            }
        }
        return $h;
    }

    /**
     * Grava, para o closer recém-criado, as metas por período que o igualam ao padrão de cada mês calculado em heranca(). Porte fiel de
     * mxAplicarHeranca.
     * @param array{metas: int, mensal: int, meses: array<int, array<string, mixed>>} $h
     */
    public function aplicarHeranca(int $closerId, array $h): void
    {
        foreach ($h['meses'] as $p) {
            $base = self::base(['metas' => $h['metas'], 'meta_mensal' => $h['mensal']], count($p['padrao']) - 1);
            foreach ($p['padrao'] as $c => $v) {
                if ($v !== null) {
                    $this->gravarCelula($closerId, $p['ano'], $p['mes'], (int) $c, (int) $v, $base);
                }
            }
        }
    }
}
