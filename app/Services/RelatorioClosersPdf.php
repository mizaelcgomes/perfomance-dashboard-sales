<?php
// Sem declare(strict_types): porte fiel do relatório que já rodava em produção (relatorioClosersPdf), com a mesma tipagem solta.

namespace App\Services;

use App\Core\Relogio;

/**
 * Layout do PDF "Performance de Closers": faixa de resumo, tabela completa do mês (quebra de página sem cortar linha, cabeçalho repetido),
 * contratos por benefício, legenda e "Página x de y". Recebe os dados prontos do RelatorioService e devolve os bytes do PDF.
 * Porte do relatorioClosersPdf() do antigo pdf-simples.php: o desenho de cada página é o mesmo.
 */
final class RelatorioClosersPdf
{
    /**
     * Semáforo único (a tela usa a mesma regra): meta batida ou ritmo em dia → 'ok'; ≥ 70% do ritmo → 'mid'; abaixo → 'low'.
     * $estado: futura/futuro → 'futura'. $frac = fração do período já decorrida (1 quando encerrado).
     */
    public static function semaforo(int $vendas, int $meta, string $estado, float $frac): string {
        if ($estado === 'futura' || $estado === 'futuro') return 'futura';
        if ($meta <= 0) return 'none';
        if ($vendas >= $meta) return 'ok';
        $esperado = $meta * $frac;
        $ritmo = $esperado > 0 ? $vendas / $esperado : 0;
        if ($ritmo >= 1) return 'ok';
        return $ritmo >= 0.7 ? 'mid' : 'low';
    }

    /** "#rrggbb" → [r,g,b] */
    public static function hex(string $hex, array $padrao = [148, 163, 184]): array {
        return preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m) ? [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])] : $padrao;
    }

    /** PDF (string binária) da Performance de Closers de um mês, com a tabela completa. $equipe: '' = todas, '__sem' = sem equipe, ou o nome. */
    public function gerar(array $d, string $equipe, string $marca): string {
        $meses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
        $mesLbl = $meses[$d['mes'] - 1] . ' de ' . $d['ano'];
        $dm = function (string $iso) { return substr($iso, 8, 2) . '/' . substr($iso, 5, 2); };

        // Cores (impressão em papel branco)
        $INK = [28, 26, 20]; $MUT = [110, 104, 90]; $LINE = [226, 218, 190]; $GOLD = [184, 150, 12]; $HEAD = [250, 245, 226]; $ZEBRA = [252, 250, 243];
        $SEM = [
            'ok'     => ['bg' => [46, 204, 113],  'fg' => [8, 52, 28],     'tx' => [20, 140, 78]],
            'mid'    => ['bg' => [245, 197, 66],  'fg' => [70, 50, 0],     'tx' => [160, 112, 0]],
            'low'    => ['bg' => [239, 83, 80],   'fg' => [255, 255, 255], 'tx' => [200, 40, 40]],
            'futura' => ['bg' => [232, 232, 228], 'fg' => [140, 140, 134], 'tx' => [140, 140, 134]],
            'none'   => ['bg' => [232, 232, 228], 'fg' => [140, 140, 134], 'tx' => [140, 140, 134]],
        ];

        // Dados filtrados e ordenados por desempenho no mês
        $rows = array_values(array_filter($d['data'], function ($v) use ($equipe) {
            if ($equipe === '') return true;
            if ($equipe === '__sem') return $v['equipe_nome'] === null || $v['equipe_nome'] === '';
            return $v['equipe_nome'] === $equipe;
        }));
        usort($rows, function ($a, $b) { return $a['posicao_mes'] <=> $b['posicao_mes']; });   // total, depois o desempate do admin (vem pronto do RelatorioService)
        $equipeLbl = $equipe === '' ? 'Todas as equipes' : ($equipe === '__sem' ? 'Sem equipe' : $equipe);
        $weeks = $d['weeks']; $nW = count($weeks);
        $tipoInfo = [];
        foreach ($d['tipos'] as $t) $tipoInfo[$t['k']] = $t;
        $fracMes = $d['estado'] === 'encerrado' ? 1.0 : ($d['estado'] === 'futuro' ? 0.0 : $d['dias_decorridos'] / max(1, $d['dias_mes']));

        $pdf = new PdfWriter('Relatório de Performance de Closers — ' . $mesLbl, $marca);
        $W = $pdf->W; $H = $pdf->H; $M = 28.0; $bottom = $H - 40;

        $cabecalho = function () use ($pdf, $M, $W, $marca, $mesLbl, $equipeLbl, $GOLD, $INK, $MUT) {
            $pdf->addPage();
            $pdf->text($M, 38, self::maiusculas($marca), 14, true, $GOLD);
            $pdf->text($M, 53, 'Relatório de Performance de Closers', 9.5, false, $INK);
            $pdf->text($W - $M, 38, $mesLbl, 12, true, $INK, 'R');
            $pdf->text($W - $M, 53, $equipeLbl, 8.5, false, $MUT, 'R');
            $pdf->line($M, 62, $W - $M, 62, $GOLD, 1.1);
            return 78.0;                                   // y onde o conteúdo começa
        };
        $y = $cabecalho();

        // ── Totais (do que está filtrado) ────────────────────────────────────
        $tot = ['mes' => 0, 'meta' => 0, 'bateu' => 0, 'sbat' => 0, 'stot' => 0, 'sem_tipo' => 0, 'sem' => array_fill(0, $nW, 0)];
        $totTipos = [];
        foreach ($rows as $v) {
            $tot['mes'] += $v['vendas_mes']; $tot['meta'] += $v['meta_mes']; $tot['sem_tipo'] += $v['sem_tipo'];
            if ($v['bateu_mes']) $tot['bateu']++;
            $tot['sbat'] += $v['metas_sem_batidas']; $tot['stot'] += $v['total_semanas'];
            foreach ($v['semanas'] as $i => $s) $tot['sem'][$i] += $s['vendas'];
            foreach ($v['tipos'] as $k => $q) $totTipos[$k] = ($totTipos[$k] ?? 0) + $q;
        }
        $score = $tot['meta'] > 0 ? (int)round($tot['mes'] / $tot['meta'] * 100) : 0;

        // ── Faixa de resumo ──────────────────────────────────────────────────
        $kp = [
            ['Total de contratos', (string)$tot['mes'], 'no mês'],
            ['Meta do mês', (string)$tot['meta'], 'soma das metas dos closers'],
            ['Score', $score . '%', 'contratos ÷ meta'],
            ['Metas do mês batidas', $tot['bateu'] . '/' . count($rows), 'closers'],
            ['Metas semanais batidas', $tot['sbat'] . '/' . $tot['stot'], 'closer × semana'],
        ];
        $kw = ($W - 2 * $M - 4 * 10) / 5;
        foreach ($kp as $i => $k) {
            $kx = $M + $i * ($kw + 10);
            $pdf->roundRect($kx, $y, $kw, 46, 5, $HEAD, $LINE, 0.6);
            $pdf->text($kx + 10, $y + 14, self::maiusculas($k[0]), 6.4, true, $MUT);
            $cor = ($i === 2) ? $SEM[self::semaforo($tot['mes'], $tot['meta'], $d['estado'], $fracMes)]['tx'] : $INK;
            $pdf->text($kx + 10, $y + 33, $k[1], 17, true, $cor);
            $pdf->text($kx + $kw - 10, $y + 33, $k[2], 6.4, false, $MUT, 'R');
        }
        $y += 46 + 14;

        // ── Colunas da tabela ────────────────────────────────────────────────
        $cw = ['rank' => 22.0, 'nome' => 150.0, 'mes' => 40.0, 'meta' => 34.0, 'pct' => 38.0, 'bat' => 44.0];
        $weekW = $nW > 0 ? min(54.0, floor(300 / $nW)) : 0.0;
        $mixW = ($W - 2 * $M) - ($cw['rank'] + $cw['nome'] + $nW * $weekW + $cw['mes'] + $cw['meta'] + $cw['pct'] + $cw['bat']);
        $xRank = $M; $xNome = $xRank + $cw['rank']; $xSem = $xNome + $cw['nome'];
        $xMes = $xSem + $nW * $weekW; $xMeta = $xMes + $cw['mes']; $xPct = $xMeta + $cw['meta']; $xBat = $xPct + $cw['pct']; $xMix = $xBat + $cw['bat'];
        $rowH = 27.0; $headH = 30.0;

        $cabTabela = function () use (&$y, $pdf, $M, $W, $HEAD, $LINE, $GOLD, $INK, $MUT, $weeks, $nW, $weekW, $cw, $xRank, $xNome, $xSem, $xMes, $xMeta, $xPct, $xBat, $xMix, $mixW, $dm) {
            $pdf->rect($M, $y, $W - 2 * $M, 30, $HEAD, null);
            $pdf->line($M, $y + 30, $W - $M, $y + 30, $GOLD, 0.9);
            $b = $y + 18;
            $pdf->text($xRank + $cw['rank'] / 2, $b, '#', 7.2, true, $INK, 'C');
            $pdf->text($xNome + 2, $b, 'Closer', 7.2, true, $INK);
            foreach ($weeks as $i => $w) {
                $cx = $xSem + $i * $weekW + $weekW / 2;
                $pdf->text($cx, $y + 13, 'S' . $w['semana_num'], 7.4, true, $INK, 'C');
                $pdf->text($cx, $y + 23, $dm($w['data_inicio']) . '-' . $dm($w['data_fim']), 5.2, false, $MUT, 'C');
            }
            $pdf->text($xMes + $cw['mes'] / 2, $y + 13, 'Contratos', 6.6, true, $INK, 'C'); $pdf->text($xMes + $cw['mes'] / 2, $y + 23, 'no mês', 5.4, false, $MUT, 'C');
            $pdf->text($xMeta + $cw['meta'] / 2, $y + 13, 'Meta', 6.6, true, $INK, 'C');     $pdf->text($xMeta + $cw['meta'] / 2, $y + 23, 'do mês', 5.4, false, $MUT, 'C');
            $pdf->text($xPct + $cw['pct'] / 2, $b, '%', 7.2, true, $INK, 'C');
            $pdf->text($xBat + $cw['bat'] / 2, $y + 13, 'Semanas', 6.6, true, $INK, 'C');   $pdf->text($xBat + $cw['bat'] / 2, $y + 23, 'batidas', 5.4, false, $MUT, 'C');
            $pdf->text($xMix + 4, $b, 'Mix de benefícios (mês)', 7.2, true, $INK);
            $y += 30;
        };

        $novaPagina = function () use (&$y, $cabecalho, $cabTabela) { $y = $cabecalho(); $cabTabela(); };

        if (!$rows) {
            $pdf->text($W / 2, $y + 60, 'Nenhum closer com dados neste filtro.', 11, false, $MUT, 'C');
        } else {
            $cabTabela();
            foreach ($rows as $pos => $v) {
                if ($y + $rowH > $bottom) $novaPagina();
                if ($pos % 2 === 1) $pdf->rect($M, $y, $W - 2 * $M, $rowH, $ZEBRA, null);
                $pdf->line($M, $y + $rowH, $W - $M, $y + $rowH, [238, 232, 212], 0.4);
                $mid = $y + $rowH / 2;
                $off = ($v['ex_closer'] || $v['suspenso']);
                $inkL = $off ? $MUT : $INK;

                $pdf->text($xRank + $cw['rank'] / 2, $mid + 3, (string)($pos + 1), 7.6, $pos < 3, $pos < 3 ? $GOLD : $MUT, 'C');
                $nome = $pdf->fit($v['nome'], $cw['nome'] - 6, 8.6, true);
                $pdf->text($xNome + 2, $y + 11.5, $nome, 8.6, true, $inkL);
                $sub = ($v['equipe_nome'] ?: 'sem equipe') . ($v['ex_closer'] ? ' · ex-closer' : ($v['suspenso'] ? ' · suspenso' : ''));
                $pdf->text($xNome + 2, $y + 21.5, $pdf->fit($sub, $cw['nome'] - 6, 6.4), 6.4, false, $MUT);

                foreach ($v['semanas'] as $i => $s) {
                    $w = $weeks[$i];
                    $frac = $w['estado'] === 'encerrada' ? 1.0 : ($w['estado'] === 'futura' ? 0.0 : $w['dias_decorridos'] / max(1, $w['dias']));
                    $sm = self::semaforo($s['vendas'], $s['meta'], $w['estado'], $frac);
                    $cwid = $weekW - 6;
                    $cx = $xSem + $i * $weekW + 3;
                    $pdf->roundRect($cx, $y + 5, $cwid, $rowH - 10, 3, $SEM[$sm]['bg'], $w['estado'] === 'atual' ? $INK : null, 0.8);
                    $txt = $sm === 'futura' ? '–' : $s['vendas'] . '/' . $s['meta'];
                    $pdf->text($cx + $cwid / 2, $mid + 2.8, $txt, 7.2, true, $SEM[$sm]['fg'], 'C');
                }

                $smMes = self::semaforo($v['vendas_mes'], $v['meta_mes'], $d['estado'], $fracMes);
                $pdf->text($xMes + $cw['mes'] / 2, $mid + 3.4, (string)$v['vendas_mes'], 9.4, true, $inkL, 'C');
                $pdf->text($xMeta + $cw['meta'] / 2, $mid + 3, (string)$v['meta_mes'], 8, false, $MUT, 'C');
                $pdf->text($xPct + $cw['pct'] / 2, $mid + 3, $v['score_mes'] . '%', 8.2, true, $SEM[$smMes]['tx'], 'C');
                $pdf->text($xBat + $cw['bat'] / 2, $mid + 3, $v['metas_sem_batidas'] . '/' . $v['total_semanas'], 8, false, $inkL, 'C');

                // Mix: os maiores benefícios do closer; o que não couber vira "+N"
                $tp = [];
                foreach ($v['tipos'] as $k => $q) if ($q > 0) $tp[$k] = $q;
                arsort($tp);
                $cx = $xMix + 4; $mostrados = 0; $total = count($tp);
                foreach ($tp as $k => $q) {
                    $lbl = $pdf->fit($tipoInfo[$k]['label'] ?? $k, 62, 6.6);
                    $txt = $lbl . ' ' . $q;
                    $largura = 8 + $pdf->width($txt, 6.6, false) + 7;
                    $resta = $total - $mostrados - 1;
                    if ($cx + $largura + ($resta > 0 ? 18 : 0) > $xMix + $mixW) break;
                    $pdf->rect($cx, $mid - 3, 5.5, 5.5, self::hex($tipoInfo[$k]['cor'] ?? ''), null);
                    $pdf->text($cx + 8, $mid + 2.4, $txt, 6.6, false, $inkL);
                    $cx += $largura; $mostrados++;
                }
                if ($total > $mostrados) $pdf->text($cx, $mid + 2.4, '+' . ($total - $mostrados), 6.6, true, $MUT);
                if ($v['sem_tipo'] > 0 && $total === 0) $pdf->text($cx, $mid + 2.4, 'sem detalhe de benefício', 6.4, false, $MUT);
                $y += $rowH;
            }

            // Linha de totais
            if ($y + 26 > $bottom) $novaPagina();
            $pdf->rect($M, $y, $W - 2 * $M, 26, $HEAD, null);
            $pdf->line($M, $y, $W - $M, $y, $GOLD, 0.9);
            $mid = $y + 13;
            $pdf->text($xNome + 2, $mid + 3, 'TOTAL — ' . count($rows) . ' closer' . (count($rows) === 1 ? '' : 's'), 8, true, $INK);
            foreach ($tot['sem'] as $i => $q) $pdf->text($xSem + $i * $weekW + $weekW / 2, $mid + 3, (string)$q, 8, true, $INK, 'C');
            $pdf->text($xMes + $cw['mes'] / 2, $mid + 3.4, (string)$tot['mes'], 9.4, true, $INK, 'C');
            $pdf->text($xMeta + $cw['meta'] / 2, $mid + 3, (string)$tot['meta'], 8, true, $MUT, 'C');
            $pdf->text($xPct + $cw['pct'] / 2, $mid + 3, $score . '%', 8.2, true, $SEM[self::semaforo($tot['mes'], $tot['meta'], $d['estado'], $fracMes)]['tx'], 'C');
            $pdf->text($xBat + $cw['bat'] / 2, $mid + 3, $tot['sbat'] . '/' . $tot['stot'], 8, true, $INK, 'C');
            $y += 26 + 16;

            // ── Contratos por benefício (bloco que não se parte entre páginas) ──
            arsort($totTipos);
            $totTipos = array_filter($totTipos, function ($q) { return $q > 0; });
            $linhas = count($totTipos) + ($tot['sem_tipo'] > 0 ? 1 : 0);
            if ($linhas > 0) {
                $altura = 24 + $linhas * 14 + 8;
                if ($y + $altura > $bottom) { $y = $cabecalho(); }
                $pdf->text($M, $y + 9, 'Contratos por benefício — ' . $mesLbl, 9.5, true, $INK);
                $y += 20;
                $somaT = array_sum($totTipos) + $tot['sem_tipo'];
                $maxT = max(1, max(array_merge(array_values($totTipos), [$tot['sem_tipo']])));
                $labW = 190.0; $barMax = 330.0;
                $itens = [];
                foreach ($totTipos as $k => $q) $itens[] = [$tipoInfo[$k]['label'] ?? $k, $q, self::hex($tipoInfo[$k]['cor'] ?? '')];
                if ($tot['sem_tipo'] > 0) $itens[] = ['Sem detalhe de benefício', $tot['sem_tipo'], [190, 190, 184]];
                foreach ($itens as $it) {
                    $pdf->rect($M, $y + 1, 6, 6, $it[2], null);
                    $pdf->text($M + 11, $y + 7, $pdf->fit($it[0], $labW - 12, 7.6), 7.6, false, $INK);
                    $bw = max(2.0, $barMax * $it[1] / $maxT);
                    $pdf->roundRect($M + $labW, $y, $bw, 8, 2, $it[2], null);
                    $pct = $somaT > 0 ? $it[1] / $somaT * 100 : 0;
                    $pdf->text($M + $labW + $bw + 6, $y + 7, $it[1] . '  (' . ($pct > 0 && $pct < 0.5 ? '<1' : (string)round($pct)) . '%)', 7.4, true, $INK);
                    $y += 14;
                }
                $y += 8;
            }

            // ── Legenda ──────────────────────────────────────────────────────
            if ($y + 40 > $bottom) { $y = $cabecalho(); }
            $lx = $M;
            foreach ([['ok', 'Meta batida ou no ritmo'], ['mid', '70% ou mais do ritmo'], ['low', 'Abaixo do ritmo'], ['futura', 'Semana que ainda não começou']] as $lg) {
                $pdf->roundRect($lx, $y, 9, 9, 2, $SEM[$lg[0]]['bg'], null);
                $pdf->text($lx + 13, $y + 7.4, $lg[1], 7, false, $INK);
                $lx += 13 + $pdf->width($lg[1], 7) + 16;
            }
            $pdf->text($M, $y + 22, 'Semanas conforme o Calendário (uma semana pode incluir dias do mês vizinho). Contagem do mês = mês-calendário; "ritmo" = meta proporcional aos dias já decorridos.', 6.6, false, $MUT);
            $pdf->text($M, $y + 32, 'Ex-closers e closers suspensos aparecem apenas quando têm contratos na janela. O PDF traz a tabela completa do mês, independentemente da semana ou do benefício escolhido na tela.', 6.6, false, $MUT);
        }

        // ── Rodapé em todas as páginas (agora que o total é conhecido) ───────
        $agora = Relogio::agora()->format('d/m/Y H:i');
        $n = $pdf->pageCount();
        for ($i = 0; $i < $n; $i++) {
            $pdf->gotoPage($i);
            $pdf->line($M, $H - 30, $W - $M, $H - 30, $LINE, 0.6);
            $pdf->text($M, $H - 18, 'Gerado em ' . $agora . ' · ' . $marca, 7, false, $MUT);
            $pdf->text($W - $M, $H - 18, 'Página ' . ($i + 1) . ' de ' . $n, 7, false, $MUT, 'R');
        }
        return $pdf->output();
    }

    /** MAIÚSCULAS sem mbstring (cobre as letras acentuadas do português) */
    public static function maiusculas(string $s): string {
        return strtr(strtoupper($s), ['á'=>'Á','à'=>'À','â'=>'Â','ã'=>'Ã','é'=>'É','è'=>'È','ê'=>'Ê','í'=>'Í','ó'=>'Ó','ô'=>'Ô','õ'=>'Õ','ú'=>'Ú','ü'=>'Ü','ç'=>'Ç']);
    }
}
