<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\AcaoRepository;
use App\Repositories\ContaCloserRepository;
use App\Repositories\ContratoRepository;
use App\Repositories\InteracaoCloserRepository;
use App\Repositories\VendedorRepository;
use DateTime;

/**
 * O que o portal do closer mostra além do histórico: a meta da semana e do mês, a posição no ranking (Comercial e equipe) e a evolução com a
 * média da equipe. O ranking é o MESMO da TV e do admin (RankingFiltradoService: meta efetiva por período e desempate do admin), então o closer vê
 * exatamente a posição que o admin vê. O closer vem da conta da sessão; só os nomes e os números do ranking saem daqui (nenhum id de outro closer).
 */
final class PortalCloserService
{
    private const TOP_COMERCIAL = 10;

    public function __construct(
        private ContaCloserRepository $contas,
        private RankingFiltradoService $ranking,
        private Calendario $calendario,
        private ContratoRepository $contratos,
        private DesempateService $desempate,
        private TiposBeneficioService $tipos,
        private InteracaoCloserRepository $interacoes,
        private AcaoRepository $acoes,
        private VendedorRepository $vendedores
    ) {
    }

    /**
     * Tudo do "Meu dia" numa chamada: a meta da semana e do mês (com "bateu a meta em N dias"), a posição no ranking com a variação contra a semana ou o
     * mês anterior e a evolução de 7 dias com a média da equipe. null se a conta não é de um closer ativo.
     * @return array<string, mixed>|null
     */
    public function meuDia(int $contaId): ?array
    {
        $closerId = $this->contas->vendedorDaConta($contaId);
        if ($closerId === null) {
            return null;
        }
        $info = $this->interacoes->equipeDoCloser($closerId);
        $equipeId = (int) ($info['equipe_id'] ?? 0);
        $hoje = Relogio::hoje();
        $semana = $this->semanaAtual();
        $mesAtual = substr($hoje, 0, 7);

        $linhasSemana = $semana ? $this->linhas('semana', $semana) : [];
        $linhasMes = $this->linhas('mes', ['periodo' => $mesAtual]);
        $anterior = $this->calendario->semanaAnterior();
        $linhasSemanaAnt = ($anterior['semana_num'] !== null && $anterior['ano'] !== null) ? $this->linhas('semana', ['ano' => $anterior['ano'], 'mes' => $anterior['mes'], 'num' => $anterior['semana_num']]) : null;
        $mesAnterior = (new DateTime($mesAtual . '-01', Relogio::fuso()))->modify('-1 month')->format('Y-m');
        $linhasMesAnt = $this->linhas('mes', ['periodo' => $mesAnterior]);

        $minhaSemana = $this->minha($linhasSemana, $closerId, 'vendas', 'meta_eff', 'score');
        $minhaMes = $this->minha($linhasMes, $closerId, 'vendas_mes', 'meta_mes', 'score_mensal');
        $metaSemana = $minhaSemana + ['bateu_em_dias' => $semana ? $this->diasAteBater($closerId, $semana['start'], $semana['end'], (int) ($minhaSemana['meta'] ?? 0), $hoje) : null];
        $metaMes = $minhaMes + ['bateu_em_dias' => $this->diasAteBater($closerId, $mesAtual . '-01', (new DateTime($mesAtual . '-01', Relogio::fuso()))->format('Y-m-t'), (int) ($minhaMes['meta'] ?? 0), $hoje)];

        return [
            'hoje' => $hoje,
            'semana' => $semana ? ['num' => $semana['num'], 'start' => $semana['start'], 'end' => $semana['end']] : null,
            'meta' => ['semana' => $metaSemana, 'mes' => $metaMes],
            'ranking' => [
                'semana' => $this->posicao($linhasSemana, $linhasSemanaAnt, $closerId, $equipeId),
                'mes' => $this->posicao($linhasMes, $linhasMesAnt, $closerId, $equipeId),
            ],
            'evolucao' => $this->evolucao($closerId, $equipeId, $hoje),
        ];
    }

    /**
     * A página Ranking: minha posição, o ranking da minha equipe e o do Comercial (os 10 primeiros e, se estiver fora deles, o próprio closer).
     * @return array<string, mixed>|null null se a conta não é de um closer ativo; com 'erro' se o período é inválido
     */
    public function ranking(int $contaId, string $periodo): ?array
    {
        $closerId = $this->contas->vendedorDaConta($contaId);
        if ($closerId === null) {
            return null;
        }
        if (!in_array($periodo, ['semana', 'mes'], true)) {
            return ['erro' => 'Período inválido.'];
        }
        $info = $this->interacoes->equipeDoCloser($closerId);
        $equipeId = (int) ($info['equipe_id'] ?? 0);
        $hoje = Relogio::hoje();
        if ($periodo === 'semana') {
            $semana = $this->semanaAtual();
            $linhas = $semana ? $this->linhas('semana', $semana) : [];
            $anterior = $this->calendario->semanaAnterior();
            $linhasAnt = ($anterior['semana_num'] !== null && $anterior['ano'] !== null) ? $this->linhas('semana', ['ano' => $anterior['ano'], 'mes' => $anterior['mes'], 'num' => $anterior['semana_num']]) : null;
            $rotulo = $semana ? 'Semana ' . $semana['num'] . ' · ' . substr($semana['start'], 8, 2) . '/' . substr($semana['start'], 5, 2) . ' a ' . substr($semana['end'], 8, 2) . '/' . substr($semana['end'], 5, 2) : 'Semana atual';
            $chaves = ['vendas', 'meta_eff', 'score', 'tipo_totals', 'conv_rate'];
        } else {
            $mesAtual = substr($hoje, 0, 7);
            $linhas = $this->linhas('mes', ['periodo' => $mesAtual]);
            $linhasAnt = $this->linhas('mes', ['periodo' => (new DateTime($mesAtual . '-01', Relogio::fuso()))->modify('-1 month')->format('Y-m')]);
            $rotulo = ucfirst($this->nomeDoMes((int) substr($mesAtual, 5, 2))) . ' de ' . substr($mesAtual, 0, 4);
            $chaves = ['vendas_mes', 'meta_mes', 'score_mensal', 'mes_tipo_totals', 'conv_rate_mes'];
        }
        [$vendas, $meta, $pontos, $tipos, $conv] = $chaves;
        $ordem = $this->desempate->ordem();

        $comercial = $this->formatar($linhas, $closerId, $vendas, $meta, $pontos, $tipos, $conv, $ordem);
        $daEquipe = $equipeId ? array_values(array_filter($linhas, static fn(array $l): bool => !$l['ex_closer'] && (int) $l['equipe_id'] === $equipeId)) : [];
        $equipe = $this->formatar($daEquipe, $closerId, $vendas, $meta, $pontos, $tipos, $conv, $ordem);
        $top = array_slice($comercial, 0, self::TOP_COMERCIAL);
        $eu = null;
        foreach ($comercial as $l) {
            if ($l['voce']) {
                $eu = $l;
            }
        }
        if ($eu !== null && $eu['pos'] > self::TOP_COMERCIAL) {
            $top[] = $eu;
        }
        return [
            'periodo' => $periodo, 'rotulo' => $rotulo,
            'minha' => $this->posicao($linhas, $linhasAnt, $closerId, $equipeId) + $this->minha($linhas, $closerId, $vendas, $meta, $pontos),
            'equipe' => $equipe, 'comercial' => $top, 'total_comercial' => count($comercial),
        ];
    }

    // ── peças ───────────────────────────────────────────────────────────────────────────────

    /**
     * A semana que contém hoje: a configurada (do mês atual ou do anterior) ou a automática, com o número dela dentro do mês a que pertence.
     * @return array{ano: int, mes: int, num: int, start: string, end: string}|null
     */
    private function semanaAtual(): ?array
    {
        $agora = Relogio::agora();
        $hoje = $agora->format('Y-m-d');
        for ($delta = 0; $delta <= 1; $delta++) {
            $d = (new DateTime($agora->format('Y-m-01'), Relogio::fuso()))->modify("-$delta month");
            $ano = (int) $d->format('Y');
            $mes = (int) $d->format('n');
            foreach ($this->calendario->semanasDoMes($ano, $mes) as $w) {
                if ($w['start'] <= $hoje && $w['end'] >= $hoje) {
                    return ['ano' => $ano, 'mes' => $mes, 'num' => (int) $w['semana_num'], 'start' => $w['start'], 'end' => $w['end']];
                }
            }
        }
        return null;
    }

    /** As linhas do ranking de closers (já na ordem do ranking), sem os ex-closers. @param array<string, mixed> $p @return array<int, array<string, mixed>> */
    private function linhas(string $tipo, array $p): array
    {
        $r = $tipo === 'semana'
            ? $this->ranking->consultar('semana', (string) $p['num'], 'closer', (int) $p['ano'], (int) $p['mes'])
            : $this->ranking->consultar('mes', (string) $p['periodo'], 'closer');
        if (!($r['success'] ?? false)) {
            return [];
        }
        return array_values(array_filter($r['data'], static fn(array $l): bool => !$l['ex_closer']));
    }

    /** O closer nas linhas: vendas, meta e % (cor da faixa na tela). @param array<int, array<string, mixed>> $linhas @return array<string, mixed> */
    private function minha(array $linhas, int $closerId, string $vendas, string $meta, string $pontos): array
    {
        foreach ($linhas as $l) {
            if ((int) $l['id'] === $closerId) {
                $v = (int) $l[$vendas];
                $m = (int) $l[$meta];
                return ['vendas' => $v, 'meta' => $m, 'pct' => (int) $l[$pontos], 'faltam' => max(0, $m - $v)];
            }
        }
        return ['vendas' => 0, 'meta' => 0, 'pct' => 0, 'faltam' => 0];
    }

    /**
     * A posição do closer no Comercial e na equipe e a variação contra o período anterior (positiva = subiu). Closer em férias não entra no
     * ranking (o ranking da TV não o mostra): sem posição.
     * @param array<int, array<string, mixed>> $linhas
     * @param array<int, array<string, mixed>>|null $anterior
     * @return array<string, mixed>
     */
    private function posicao(array $linhas, ?array $anterior, int $closerId, int $equipeId): array
    {
        [$pc, $tc, $pe, $te] = $this->posicoes($linhas, $closerId, $equipeId);
        $varC = $varE = null;
        if ($anterior !== null && $pc !== null) {
            [$pcA, , $peA] = $this->posicoes($anterior, $closerId, $equipeId);
            $varC = $pcA !== null ? $pcA - $pc : null;
            $varE = ($peA !== null && $pe !== null) ? $peA - $pe : null;
        }
        return ['pos_comercial' => $pc, 'total_comercial' => $tc, 'pos_equipe' => $pe, 'total_equipe' => $te, 'variacao_comercial' => $varC, 'variacao_equipe' => $varE];
    }

    /** @param array<int, array<string, mixed>> $linhas @return array{0: ?int, 1: int, 2: ?int, 3: int} */
    private function posicoes(array $linhas, int $closerId, int $equipeId): array
    {
        $pc = $pe = null;
        $ne = 0;
        foreach ($linhas as $i => $l) {
            $daEquipe = $equipeId > 0 && (int) $l['equipe_id'] === $equipeId;
            if ($daEquipe) {
                $ne++;
            }
            if ((int) $l['id'] === $closerId) {
                $pc = $i + 1;
                $pe = $daEquipe ? $ne : null;
            }
        }
        return [$pc, count($linhas), $pe, $ne];
    }

    /**
     * As linhas do ranking prontas para a tela: posição, nome, vendas, meta, % e, quando o closer empatou em pontos com o vizinho, o critério que
     * desempatou (o primeiro critério do admin que separa os dois).
     * @param array<int, array<string, mixed>> $linhas
     * @param array<int, mixed> $ordem
     * @return array<int, array<string, mixed>>
     */
    private function formatar(array $linhas, int $closerId, string $vendas, string $meta, string $pontos, string $tipos, string $conv, array $ordem): array
    {
        $rotulos = $this->tipos->ativos();
        $saida = [];
        foreach ($linhas as $i => $l) {
            $item = ['pos' => $i + 1, 'nome' => (string) $l['name'], 'vendas' => (int) $l[$vendas], 'meta' => (int) $l[$meta], 'pct' => (int) $l[$pontos], 'voce' => (int) $l['id'] === $closerId];
            $vizinho = null;
            if (isset($linhas[$i - 1]) && (int) $linhas[$i - 1][$pontos] === (int) $l[$pontos]) {
                $vizinho = $linhas[$i - 1];
                $item['desempate_perdeu'] = true;
            } elseif (isset($linhas[$i + 1]) && (int) $linhas[$i + 1][$pontos] === (int) $l[$pontos]) {
                $vizinho = $linhas[$i + 1];
                $item['desempate_perdeu'] = false;
            }
            if ($vizinho !== null) {
                $item['desempate'] = $this->criterio($l, $vizinho, $ordem, $tipos, $conv, $rotulos);
            }
            $saida[] = $item;
        }
        return $saida;
    }

    /**
     * O primeiro critério de desempate que separa dois closers empatados, como "3× BPC/LOAS" (a quantidade é a do closer da linha), ou null se
     * nada os separa (a ordem fica por nome).
     * @param array<string, mixed> $a @param array<string, mixed> $b @param array<int, mixed> $ordem @param array<string, string> $rotulos
     */
    private function criterio(array $a, array $b, array $ordem, string $tipos, string $conv, array $rotulos): ?string
    {
        foreach ($ordem as $criterio) {
            if ($this->desempate->comparar($a, $b, $criterio, $tipos, $conv) === 0) {
                continue;
            }
            $mine = $a[$tipos] ?? [];
            if ($criterio === 'conv_rate') {
                return 'Taxa de conversão ' . number_format((float) $a[$conv], 1, ',', '.') . '%';
            }
            if (is_array($criterio)) {
                $chaves = (array) ($criterio['grupo'] ?? []);
                $qtd = 0;
                $nomes = [];
                foreach ($chaves as $k) {
                    $qtd += (int) ($mine[$k] ?? 0);
                    $nomes[] = $rotulos[$k] ?? TiposBeneficioService::rotuloDeInativo((string) $k);
                }
                return $qtd . '× ' . implode(' + ', $nomes);
            }
            $k = (string) $criterio;
            return (int) ($mine[$k] ?? 0) . '× ' . ($rotulos[$k] ?? TiposBeneficioService::rotuloDeInativo($k));
        }
        return null;
    }

    /** Em quantos dias do período o closer chegou à meta (1 = no primeiro dia), ou null se não chegou ou não há meta. */
    private function diasAteBater(int $closerId, string $ini, string $fim, int $meta, string $hoje): ?int
    {
        if ($meta <= 0) {
            return null;
        }
        $porDia = $this->contratos->totaisPorDia($ini, min($fim, $hoje), $closerId);
        $soma = 0;
        $n = 0;
        foreach ($this->calendario->datas($ini, min($fim, $hoje)) as $d) {
            $n++;
            $soma += $porDia[$d] ?? 0;
            if ($soma >= $meta) {
                return $n;
            }
        }
        return null;
    }

    /**
     * Os últimos 7 dias de ações do closer e a média da equipe (por closer ativo da equipe, por dia), com os totais da semana e da anterior.
     * @return array<string, mixed>
     */
    private function evolucao(int $closerId, int $equipeId, string $hoje): array
    {
        $tz = Relogio::fuso();
        $ini = (new DateTime($hoje, $tz))->modify('-13 days')->format('Y-m-d');
        $meus = $this->acoes->totaisPorDia([$closerId], $ini, $hoje);
        $membros = $equipeId ? array_map(static fn(array $m): int => $m['id'], $this->vendedores->doTime($equipeId)) : [];
        $time = $this->acoes->totaisPorDia($membros, $ini, $hoje);
        $n = max(1, count($membros));
        $dias = [];
        $total = $anterior = 0;
        $mediaTotal = 0.0;
        for ($i = 13; $i >= 0; $i--) {
            $d = (new DateTime($hoje, $tz))->modify("-$i days")->format('Y-m-d');
            $q = $meus[$d] ?? 0;
            if ($i <= 6) {
                $media = round(($time[$d] ?? 0) / $n, 1);
                $dias[] = ['data' => $d, 'total' => $q, 'media_equipe' => $media];
                $total += $q;
                $mediaTotal += $media;
            } else {
                $anterior += $q;
            }
        }
        return ['dias' => $dias, 'total' => $total, 'anterior' => $anterior, 'media_equipe_total' => (int) round($mediaTotal), 'equipe_n' => count($membros)];
    }

    private function nomeDoMes(int $mes): string
    {
        return ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][$mes - 1] ?? '';
    }
}
