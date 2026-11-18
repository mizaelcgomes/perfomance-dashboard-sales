<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\EquipeRepository;
use App\Repositories\LiderPortalRepository;
use App\Repositories\ReuniaoRepository;
use App\Repositories\VendedorRepository;
use DateTimeImmutable;

/**
 * O Dashboard do portal do líder: a performance da semana de cada closer da equipe (com status), os alertas proativos, as ações pendentes e a
 * comparação com o Comercial. Os números vêm dos mesmos serviços do admin (PainelService e ProdutividadeService): este serviço só os
 * filtra pela equipe do líder, classifica e transforma em avisos acionáveis.
 *
 * Status do closer na semana (meta semanal dele):
 *  - bateu: contratos da semana >= meta;
 *  - ritmo: ainda não bateu, mas tem pelo menos o esperado até ontem (meta × dias já completos ÷ dias da semana);
 *  - risco: abaixo do esperado até ontem; sem_meta: sem meta cadastrada.
 *
 * Permissão: o líder vê só a própria equipe. Das outras equipes sai apenas a posição e a média (agregados), nunca nome nem número individual.
 */
final class LiderDashboardService
{
    public function __construct(
        private PainelService $painel,
        private ProdutividadeService $produtividade,
        private EquipeRepository $equipes,
        private VendedorRepository $vendedores,
        private ReuniaoRepository $reunioes,
        private LiderPortalRepository $portal,
        private LiderPreferenciasService $preferencias
    ) {
    }

    /**
     * O pacote completo do Dashboard.
     * @return array<string, mixed>
     */
    public function dashboard(int $equipeId, int $liderVendedorId): array
    {
        return $this->base($equipeId, $liderVendedorId);
    }

    /**
     * Cálculo comum ao Dashboard e à aba Equipe.
     * @return array<string, mixed>
     */
    public function base(int $equipeId, int $liderVendedorId): array
    {
        $prefs = $this->preferencias->obter($liderVendedorId);
        $painel = $this->painel->painel(0, 0, 0);
        $semana = $painel['week'] ?? [];
        $datas = array_values($semana['dates'] ?? []);
        $hoje = (string) ($semana['today'] ?? Relogio::hoje());
        $total = max(1, count($datas));
        $decorridos = count(array_filter($datas, static fn(string $d): bool => $d <= $hoje));

        $doPainel = [];
        foreach ($painel['closers'] ?? [] as $c) {
            if ((int) $c['equipe_id'] === $equipeId && empty($c['ex_closer'])) {
                $doPainel[(int) $c['id']] = $c;
            }
        }
        $closers = [];
        foreach ($this->vendedores->doTime($equipeId) as $m) {
            $c = $doPainel[$m['id']] ?? null;
            $closers[] = $this->closer($m['id'], $m['nome'], $c, $datas, $decorridos, $total, $liderVendedorId);
        }
        usort($closers, static fn(array $a, array $b): int => ($b['vendas'] <=> $a['vendas']) ?: strcmp((string) $a['nome'], (string) $b['nome']));

        $agora = Relogio::agora();
        $alertas = $this->alertas($equipeId, $closers, $prefs);
        $pendencias = $this->pendencias($equipeId, $closers, $prefs, $agora);
        $atencao = count(array_filter($alertas, static fn(array $a): bool => $a['severidade'] === 'atencao'));

        return [
            'equipe' => $this->resumoEquipe($equipeId, $painel['teams'] ?? [], count($closers)),
            'comparacao' => $this->comparacao($equipeId, $painel['teams'] ?? []),
            'semana' => [
                'numero' => $semana['semana_num'] ?? null, 'inicio' => $semana['start'] ?? null, 'fim' => $semana['end'] ?? null,
                'hoje' => $hoje, 'dia_atual' => $decorridos, 'dias' => $total,
            ],
            'lider' => $this->dadosDoLider($liderVendedorId, $doPainel),
            'closers' => $closers,
            'alertas' => $alertas,
            'pendencias' => $pendencias,
            'contadores' => ['alertas' => $atencao, 'pendencias' => count($pendencias), 'total' => $atencao + count($pendencias)],
            'preferencias' => $prefs,
        ];
    }

    /**
     * @param array<string, mixed>|null $c linha do painel (null = closer sem dados ainda)
     * @param array<int, string> $datas
     * @return array<string, mixed>
     */
    private function closer(int $id, string $nome, ?array $c, array $datas, int $decorridos, int $total, int $liderId): array
    {
        $vendas = (int) ($c['vendas'] ?? 0);
        $meta = (int) ($c['meta'] ?? 0);
        [$status, $rotulo] = $this->status($vendas, $meta, $decorridos, $total);
        $bateuEm = null;
        if ($meta > 0 && $vendas >= $meta) {
            $acum = 0;
            foreach (array_values($c['daily'] ?? []) as $i => $v) {
                $acum += (int) $v;
                if ($acum >= $meta) {
                    $bateuEm = $i + 1;
                    break;
                }
            }
        }
        return [
            'id' => $id, 'nome' => $nome, 'cor' => (string) ($c['color'] ?? '#d4af37'), 'imageUrl' => $c['imageUrl'] ?? null,
            'vendas' => $vendas, 'meta' => $meta, 'score' => (int) ($c['score'] ?? 0),
            'vendas_mes' => (int) ($c['vendas_mes'] ?? 0), 'meta_mes' => (int) ($c['meta_mes'] ?? 0), 'score_mes' => (int) ($c['score_mes'] ?? 0),
            'dias_sem_venda' => isset($c['dias_sem_venda']) ? (int) $c['dias_sem_venda'] : null,
            'status' => $status, 'status_rotulo' => $rotulo, 'bateu_em_dias' => $bateuEm, 'lider' => $id === $liderId,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function status(int $vendas, int $meta, int $decorridos, int $total): array
    {
        if ($meta <= 0) {
            return ['sem_meta', 'Sem meta'];
        }
        if ($vendas >= $meta) {
            return ['bateu', 'Bateu a meta'];
        }
        $esperado = (int) floor($meta * max(0, $decorridos - 1) / $total);
        return $vendas >= $esperado ? ['ritmo', 'Em ritmo'] : ['risco', 'Em risco'];
    }

    /**
     * Os alertas proativos. Cada um traz uma ação (o botão do portal).
     * @param array<int, array<string, mixed>> $closers
     * @param array<string, mixed> $prefs
     * @return array<int, array<string, mixed>>
     */
    private function alertas(int $equipeId, array $closers, array $prefs): array
    {
        $a = $prefs['alertas'];
        $limite = (int) $prefs['limite_dias_sem_venda'];
        $saida = [];
        $porId = [];
        foreach ($closers as $c) {
            if ($c['lider']) {
                continue;     // o líder não dá feedback a si mesmo: ele aparece na performance, mas não nos alertas
            }
            $porId[$c['id']] = $c;
            if ($a['sem_venda'] && $c['dias_sem_venda'] !== null && $c['dias_sem_venda'] >= $limite) {
                $d = $c['dias_sem_venda'];
                $saida[] = [
                    'id' => 'sem_venda:' . $c['id'], 'tipo' => 'sem_venda', 'severidade' => 'atencao', 'closer_id' => $c['id'],
                    'titulo' => $c['nome'] . ': sem fechar contrato há ' . $d . ' dia' . ($d === 1 ? '' : 's'),
                    'detalhe' => 'Última venda há ' . $d . ' dia' . ($d === 1 ? '' : 's') . '.',
                    'acao' => ['k' => 'feedback', 'rotulo' => 'Dar feedback', 'closer_id' => $c['id']],
                ];
            }
            if ($a['meta_batida'] && $c['status'] === 'bateu') {
                $n = (int) ($c['bateu_em_dias'] ?? 0);
                $saida[] = [
                    'id' => 'meta_batida:' . $c['id'], 'tipo' => 'meta_batida', 'severidade' => 'positivo', 'closer_id' => $c['id'],
                    'titulo' => $c['nome'] . ' bateu a meta' . ($n > 0 ? ' em ' . $n . ' dia' . ($n === 1 ? '' : 's') : '') . '!',
                    'detalhe' => $c['vendas'] . ' de ' . $c['meta'] . ' contratos na semana.',
                    'acao' => ['k' => 'feedback', 'rotulo' => 'Parabenizar', 'closer_id' => $c['id'], 'assunto' => 'Parabéns pela meta!'],
                ];
            }
        }
        if ($a['atividade']) {
            $prod = $this->produtividade->dados('semana', '', '', $equipeId, 0);
            foreach ($prod['ranking'] as $r) {
                $c = $porId[(int) $r['id']] ?? null;
                if ($c === null || !empty($r['ferias'])) {
                    continue;
                }
                foreach ($r['alertas'] ?? [] as $al) {
                    $saida[] = [
                        'id' => $al['k'] . ':' . $c['id'], 'tipo' => (string) $al['k'], 'severidade' => 'atencao', 'closer_id' => $c['id'],
                        'titulo' => $c['nome'] . ': ' . $al['t'], 'detalhe' => (string) $al['d'],
                        'acao' => ['k' => 'feedback', 'rotulo' => 'Dar feedback', 'closer_id' => $c['id']],
                    ];
                }
            }
        }
        usort($saida, static fn(array $x, array $y): int => ($x['severidade'] === 'atencao' ? 0 : 1) <=> ($y['severidade'] === 'atencao' ? 0 : 1));
        return array_slice($saida, 0, 40);
    }

    /**
     * As ações pendentes do líder: feedbacks atrasados, reuniões para concluir ou próximas e treinamentos para agendar ou com presença em aberto.
     * @param array<int, array<string, mixed>> $closers
     * @param array<string, mixed> $prefs
     * @return array<int, array<string, mixed>>
     */
    private function pendencias(int $equipeId, array $closers, array $prefs, DateTimeImmutable $agora): array
    {
        $saida = [];
        $hoje = $agora->format('Y-m-d');
        $a = $prefs['alertas'];
        if ($a['agenda']) {
            $limite = $agora->modify('+7 days')->format('Y-m-d H:i:s');
            $atuais = $agora->format('Y-m-d H:i:s');
            foreach ($this->reunioes->agendadas($equipeId) as $r) {
                $marcada = (string) $r['data_hora_marcada'];
                if ($marcada < $atuais) {
                    $saida[] = [
                        'id' => 'reuniao_concluir:' . $r['id'], 'tipo' => 'reuniao', 'ordem' => 0, 'closer_id' => null, 'quando' => $marcada,
                        'titulo' => 'Reunião para concluir: ' . $r['titulo'], 'detalhe' => 'Estava marcada para ' . $this->dataHoraCurta($marcada) . '. Registre como realizada ou cancele.',
                        'acao' => ['k' => 'reuniao_concluir', 'rotulo' => 'Concluir', 'id' => (int) $r['id']],
                    ];
                } elseif ($marcada <= $limite) {
                    $saida[] = [
                        'id' => 'reuniao_proxima:' . $r['id'], 'tipo' => 'reuniao', 'ordem' => 3, 'closer_id' => null, 'quando' => $marcada,
                        'titulo' => 'Reunião: ' . $r['titulo'], 'detalhe' => 'Marcada para ' . $this->dataHoraCurta($marcada) . '.',
                        'acao' => ['k' => 'agenda', 'rotulo' => 'Ver na agenda'],
                    ];
                }
            }
            if ($this->portal->treinamentosMarcados($equipeId, $atuais, $agora->modify('+30 days')->format('Y-m-d H:i:s')) === 0) {
                $saida[] = [
                    'id' => 'treinamento_agendar', 'tipo' => 'treinamento', 'ordem' => 2, 'closer_id' => null, 'quando' => null,
                    'titulo' => 'Treinamentos para agendar', 'detalhe' => 'Nenhum treinamento marcado para os próximos 30 dias.',
                    'acao' => ['k' => 'novo_treinamento', 'rotulo' => 'Agendar'],
                ];
            }
            foreach ($this->portal->treinamentosSemPresenca($equipeId, $agora->modify('-14 days')->format('Y-m-d H:i:s'), $atuais) as $t) {
                $saida[] = [
                    'id' => 'treinamento_presenca:' . $t['id'], 'tipo' => 'treinamento', 'ordem' => 1, 'closer_id' => null, 'quando' => (string) $t['data_hora'],
                    'titulo' => 'Registrar presença: ' . $t['assunto'], 'detalhe' => 'Aconteceu em ' . $this->dataHoraCurta((string) $t['data_hora']) . ' e ainda não tem presença.',
                    'acao' => ['k' => 'presenca', 'rotulo' => 'Marcar presença', 'id' => (int) $t['id']],
                ];
            }
        }
        if ($a['sem_feedback']) {
            $ultimo = $this->portal->ultimoFeedbackPorCloser($equipeId);
            $limite = (int) $prefs['dias_sem_feedback'];
            foreach ($closers as $c) {
                if ($c['lider']) {
                    continue;
                }
                $dias = isset($ultimo[$c['id']]) ? $this->dias($ultimo[$c['id']], $hoje) : null;
                if ($dias === null || $dias >= $limite) {
                    $saida[] = [
                        'id' => 'feedback:' . $c['id'], 'tipo' => 'feedback', 'ordem' => 1, 'closer_id' => $c['id'], 'quando' => null,
                        'titulo' => 'Feedback pendente: ' . $c['nome'],
                        'detalhe' => $dias === null ? 'Ainda não recebeu nenhum feedback.' : 'Sem feedback há ' . $dias . ' dias.',
                        'acao' => ['k' => 'feedback', 'rotulo' => 'Novo feedback', 'closer_id' => $c['id']],
                    ];
                }
            }
        }
        usort($saida, static fn(array $x, array $y): int => ($x['ordem'] <=> $y['ordem']) ?: strcmp((string) ($x['quando'] ?? ''), (string) ($y['quando'] ?? '')));
        return array_slice($saida, 0, 40);
    }

    /**
     * A equipe do líder: pontuação, metas e a posição entre as equipes do Comercial.
     * @param array<int, array<string, mixed>> $teams
     * @return array<string, mixed>
     */
    private function resumoEquipe(int $equipeId, array $teams, int $nClosers): array
    {
        $e = $this->equipes->comLider($equipeId);
        $minha = null;
        foreach ($teams as $t) {
            if ((int) $t['id'] === $equipeId) {
                $minha = $t;
            }
        }
        $ativas = array_values(array_filter($teams, static fn(array $t): bool => (int) ($t['closers'] ?? 0) > 0));
        $posicao = static function (string $campo) use ($ativas, $minha): ?int {
            if ($minha === null) {
                return null;
            }
            return 1 + count(array_filter($ativas, static fn(array $t): bool => (int) $t[$campo] > (int) $minha[$campo]));
        };
        return [
            'id' => $equipeId, 'nome' => (string) ($e['nome'] ?? ''), 'cor' => (string) ($e['cor'] ?? '#d4af37'), 'closers' => $nClosers,
            'vendas' => (int) ($minha['vendas'] ?? 0), 'meta' => (int) ($minha['meta'] ?? 0), 'score' => (int) ($minha['score'] ?? 0),
            'vendas_mes' => (int) ($minha['vendas_mes'] ?? 0), 'meta_mes' => (int) ($minha['meta_mes'] ?? 0), 'score_mes' => (int) ($minha['score_mes'] ?? 0),
            'posicao_semana' => $posicao('score'), 'posicao_mes' => $posicao('score_mes'), 'total_equipes' => count($ativas),
        ];
    }

    /**
     * Equipe × média do Comercial. Só agregados: nenhuma outra equipe aparece pelo nome.
     * @param array<int, array<string, mixed>> $teams
     * @return array<string, mixed>
     */
    private function comparacao(int $equipeId, array $teams): array
    {
        $ativas = array_values(array_filter($teams, static fn(array $t): bool => (int) ($t['closers'] ?? 0) > 0));
        $media = static function (callable $f) use ($ativas): float {
            return $ativas === [] ? 0.0 : round(array_sum(array_map($f, $ativas)) / count($ativas), 1);
        };
        $minha = null;
        foreach ($ativas as $t) {
            if ((int) $t['id'] === $equipeId) {
                $minha = $t;
            }
        }
        $porCloser = static fn(array $t): float => (int) $t['closers'] > 0 ? (int) $t['vendas'] / (int) $t['closers'] : 0.0;
        return [
            'equipes' => count($ativas),
            'metricas' => [
                ['k' => 'score_semana', 'rotulo' => 'Pontuação da semana (%)', 'equipe' => $minha === null ? 0.0 : (float) $minha['score'], 'media' => $media(static fn(array $t): float => (float) $t['score'])],
                ['k' => 'score_mes', 'rotulo' => 'Pontuação do mês (%)', 'equipe' => $minha === null ? 0.0 : (float) $minha['score_mes'], 'media' => $media(static fn(array $t): float => (float) $t['score_mes'])],
                ['k' => 'contratos_closer', 'rotulo' => 'Contratos por closer (semana)', 'equipe' => $minha === null ? 0.0 : round($porCloser($minha), 1), 'media' => $media($porCloser)],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $doPainel
     * @return array<string, mixed>|null
     */
    private function dadosDoLider(int $liderId, array $doPainel): ?array
    {
        $c = $doPainel[$liderId] ?? null;
        $nomes = $this->vendedores->nomes([$liderId]);
        return ['id' => $liderId, 'nome' => $nomes[$liderId] ?? '', 'cor' => (string) ($c['color'] ?? '#d4af37'), 'imageUrl' => $c['imageUrl'] ?? null];
    }

    private function dias(string $de, string $ate): int
    {
        return max(0, (int) (new DateTimeImmutable($de, Relogio::fuso()))->diff(new DateTimeImmutable($ate, Relogio::fuso()))->format('%r%a'));
    }

    private function dataHoraCurta(string $s): string
    {
        return substr($s, 8, 2) . '/' . substr($s, 5, 2) . ' às ' . substr($s, 11, 5);
    }
}
