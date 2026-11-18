<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\SemanaRepository;
use DateTime;

/**
 * Calendário dos relatórios: o mês pedido (com validação), a lista de datas de um intervalo e as semanas de um mês.
 * Reproduz relMesInfo, relDatas e painelMonthWeeks do api.php atual; tudo em America/Sao_Paulo.
 */
final class Calendario
{
    public function __construct(private SemanaRepository $semanas)
    {
    }

    /**
     * Mês-calendário pedido: ano/mês válidos (senão o corrente), limites, dias e quantos já decorreram
     * (0 = mês futuro, dias_mes = encerrado).
     * @return array<string, mixed>
     */
    public function mesInfo(int $ano, int $mes): array
    {
        $tz = Relogio::fuso();
        $agora = Relogio::agora();
        if ($ano < 2000 || $ano > 2100) {
            $ano = (int) $agora->format('Y');
        }
        if ($mes < 1 || $mes > 12) {
            $mes = (int) $agora->format('n');
        }
        $hoje = $agora->format('Y-m-d');
        $ini = sprintf('%04d-%02d-01', $ano, $mes);
        $fim = (new DateTime($ini, $tz))->format('Y-m-t');
        $dias = (int) substr($fim, 8, 2);

        if ($hoje < $ini) {
            $decorridos = 0;
            $estado = 'futuro';
        } elseif ($hoje > $fim) {
            $decorridos = $dias;
            $estado = 'encerrado';
        } else {
            $decorridos = (int) substr($hoje, 8, 2);
            $estado = 'atual';
        }
        return ['ano' => $ano, 'mes' => $mes, 'hoje' => $hoje, 'ini' => $ini, 'fim' => $fim, 'dias_mes' => $dias, 'dias_decorridos' => $decorridos, 'estado' => $estado];
    }

    /** Datas "Y-m-d" de $ini a $fim, inclusive. @return array<int, string> */
    public function datas(string $ini, string $fim): array
    {
        $saida = [];
        $d = new DateTime($ini, Relogio::fuso());
        $e = new DateTime($fim, Relogio::fuso());
        while ($d <= $e) {
            $saida[] = $d->format('Y-m-d');
            $d->modify('+1 day');
        }
        return $saida;
    }

    /**
     * A semana que contém hoje: a configurada na aba Calendário (semanas_mes) do mês atual ou do anterior (uma semana pode
     * atravessar o mês); sem configuração, a automática (quarta a terça). Reproduz getConfiguredCurrentWeek do config.php
     * (DUPLICAÇÃO TEMPORÁRIA até o calendário migrar, na fase 4).
     * @return array{start: string, end: string, configured: bool}
     */
    public function semanaAtual(): array
    {
        $agora = Relogio::agora();
        $hoje = $agora->format('Y-m-d');
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');
        for ($delta = 0; $delta <= 1; $delta++) {
            $m = $mes - $delta;
            $a = $ano;
            if ($m < 1) {
                $m += 12;
                $a--;
            }
            $linha = $this->semanas->queContem($a, $m, $hoje);
            if ($linha) {
                return ['start' => (string) $linha['data_inicio'], 'end' => (string) $linha['data_fim'], 'configured' => true];
            }
        }
        return $this->limitesAutomaticos() + ['configured' => false];
    }

    /**
     * A semana imediatamente ANTES da semana atual (termina no dia anterior ao início dela). semana_num, ano e mes só vêm
     * preenchidos quando ela é uma semana configurada. Reproduz getConfiguredPreviousWeek do config.php.
     * @return array{start: string, end: string, semana_num: ?int, ano: ?int, mes: ?int, configured: bool}
     */
    public function semanaAnterior(): array
    {
        return $this->semanasAnteriores(1)[0];
    }

    /**
     * As últimas $quantas semanas completas, da mais recente para a mais antiga (a primeira é a semana anterior). Cada uma é a configurada
     * pelo admin ou, sem configuração, a automática (quarta a terça), pela mesma regra de semanaAnterior.
     * @return array<int, array{start: string, end: string, semana_num: ?int, ano: ?int, mes: ?int, configured: bool}>
     */
    public function semanasAnteriores(int $quantas): array
    {
        $tz = Relogio::fuso();
        $fim = (new DateTime($this->semanaAtual()['start'], $tz))->modify('-1 day')->format('Y-m-d');
        $saida = [];
        for ($i = 0; $i < $quantas; $i++) {
            $linha = $this->semanas->queTerminaEm($fim);
            if ($linha) {
                $semana = [
                    'start' => (string) $linha['data_inicio'], 'end' => (string) $linha['data_fim'], 'semana_num' => (int) $linha['semana_num'],
                    'ano' => (int) $linha['ano'], 'mes' => (int) $linha['mes'], 'configured' => true,
                ];
            } else {
                $semana = ['start' => (new DateTime($fim, $tz))->modify('-6 days')->format('Y-m-d'), 'end' => $fim, 'semana_num' => null, 'ano' => null, 'mes' => null, 'configured' => false];
            }
            $saida[] = $semana;
            $fim = (new DateTime($semana['start'], $tz))->modify('-1 day')->format('Y-m-d');
        }
        return $saida;
    }

    /**
     * As datas da semana número $semana de (ano, mês): a configurada pelo admin; sem configuração, a automática (a n-ésima quarta a
     * terça). null se a semana não existir. Reproduz o resolveWeekBounds do get_rankings_filtered.
     * @return array{start: string, end: string}|null
     */
    public function limitesDaSemana(int $ano, int $mes, int $semana): ?array
    {
        $linha = $this->semanas->daSemana($ano, $mes, $semana);
        if ($linha) {
            return ['start' => (string) $linha['data_inicio'], 'end' => (string) $linha['data_fim']];
        }
        $tz = Relogio::fuso();
        $inicioMes = new DateTime(sprintf('%04d-%02d-01', $ano, $mes), $tz);
        $fimMes = new DateTime('last day of ' . $inicioMes->format('Y-m') . ' 23:59:59', $tz);
        $p = clone $inicioMes;
        while ((int) $p->format('N') !== 3) {
            $p->modify('+1 day');
        }
        $n = 1;
        while ($p <= $fimMes && $n < $semana) {
            $p->modify('+7 days');
            $n++;
        }
        if ($n !== $semana) {
            return null;
        }
        $terca = clone $p;
        $terca->modify('+6 days');
        return ['start' => $p->format('Y-m-d'), 'end' => $terca->format('Y-m-d')];
    }

    /** Quantas semanas o mês tem: as configuradas; sem configuração, quantas quartas-feiras caem nele (no mínimo 1). */
    public function quantidadeDeSemanas(int $ano, int $mes): int
    {
        $configuradas = $this->semanas->contarDoMes($ano, $mes);
        if ($configuradas > 0) {
            return $configuradas;
        }
        $tz = Relogio::fuso();
        $inicioMes = new DateTime(sprintf('%04d-%02d-01', $ano, $mes), $tz);
        $fimMes = new DateTime('last day of ' . $inicioMes->format('Y-m') . ' 23:59:59', $tz);
        $p = clone $inicioMes;
        while ((int) $p->format('N') !== 3) {
            $p->modify('+1 day');
        }
        $n = 0;
        while ($p <= $fimMes) {
            $n++;
            $p->modify('+7 days');
        }
        return max(1, $n);
    }

    /**
     * As semanas do mês como a TV as recebe: início, fim, rótulo "S1", número e se foram configuradas pelo admin.
     * @return array<int, array{start: string, end: string, label: string, semana_num: int, configured: bool}>
     */
    public function semanasDoMesParaTv(int $ano, int $mes): array
    {
        $configurada = $this->semanas->doMes($ano, $mes) !== [];
        return array_map(
            static fn(array $w): array => ['start' => $w['start'], 'end' => $w['end'], 'label' => 'S' . $w['semana_num'], 'semana_num' => $w['semana_num'], 'configured' => $configurada],
            $this->semanasDoMes($ano, $mes)
        );
    }

    /**
     * Semana automática que contém hoje: começa na quarta-feira e termina na terça (getWeekBounds do config.php).
     * @return array{start: string, end: string}
     */
    public function limitesAutomaticos(): array
    {
        $agora = Relogio::agora();
        $dow = (int) $agora->format('N');                       // segunda = 1 ... domingo = 7
        $desloc = ($dow >= 3) ? ($dow - 3) : ($dow + 4);        // quarta é o dia 0; segunda e terça ainda são do ciclo anterior
        $quarta = $agora->modify('-' . $desloc . ' days');      // Relogio::agora() é imutável: modify() devolve outro objeto
        $terca = $quarta->modify('+6 days');
        return ['start' => $quarta->format('Y-m-d'), 'end' => $terca->format('Y-m-d')];
    }

    /**
     * Semanas de um mês: as configuradas em semanas_mes; sem configuração, a auto-composição de sempre (semanas que começam
     * na quarta-feira e caem no mês).
     * @return array<int, array{semana_num: int, start: string, end: string}>
     */
    public function semanasDoMes(int $ano, int $mes): array
    {
        $linhas = $this->semanas->doMes($ano, $mes);
        if ($linhas) {
            return array_map(
                static fn(array $r): array => ['semana_num' => (int) $r['semana_num'], 'start' => (string) $r['data_inicio'], 'end' => (string) $r['data_fim']],
                $linhas
            );
        }

        $tz = Relogio::fuso();
        $inicioMes = new DateTime(sprintf('%04d-%02d-01', $ano, $mes), $tz);
        $fimMes = new DateTime($inicioMes->format('Y-m-t') . ' 23:59:59', $tz);
        $p = clone $inicioMes;
        while ((int) $p->format('N') !== 3) {
            $p->modify('+1 day');
        }
        $saida = [];
        $n = 0;
        while ($p <= $fimMes) {
            $n++;
            $saida[] = ['semana_num' => $n, 'start' => $p->format('Y-m-d'), 'end' => (clone $p)->modify('+6 days')->format('Y-m-d')];
            $p->modify('+7 days');
        }
        return $saida;
    }
}
