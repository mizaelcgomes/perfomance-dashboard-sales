<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\ContratoRepository;
use App\Repositories\SemanaRepository;
use App\Repositories\VendedorRepository;
use DateTime;

/**
 * Dados agregados para os gráficos (Chart.js) da aba Painel do admin (get_chart_data). A granularidade vem de $periodo:
 * 'week' soma por dia da semana, 'month' por dia do mês, 'year' por mês e 'day' por closer num dia. Com $vendedorId, só aquele closer.
 * Closers desligados só entram quando têm contratos no período (e levam " (Ex)" no nome). Reproduz o get_chart_data do api.php.
 */
final class GraficoService
{
    public function __construct(
        private Calendario $calendario,
        private SemanaRepository $semanas,
        private VendedorRepository $vendedores,
        private ContratoRepository $contratos
    ) {
    }

    /**
     * @param array<string, mixed> $p periodo, vendedor_id, ano, mes, semana_num, date (todos como vieram da URL; ausentes = null)
     * @return array<string, mixed>|null null quando o período não é conhecido (a resposta fica vazia, como sempre foi)
     */
    public function dados(string $periodo, ?int $vendedorId, ?int $ano, ?int $mes, ?int $semana, ?string $dia): ?array
    {
        $agora = Relogio::agora();
        if ($periodo === 'week') {
            return $this->semana($vendedorId, $ano, $mes, $semana);
        }
        if ($periodo === 'month') {
            return $this->mes($vendedorId, $ano ?? (int) $agora->format('Y'), $mes ?? (int) $agora->format('m'));
        }
        if ($periodo === 'year') {
            return $this->ano($vendedorId, $ano ?? (int) $agora->format('Y'));
        }
        if ($periodo === 'day') {
            $data = ($dia !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) ? $dia : $agora->format('Y-m-d');
            return $this->dia($vendedorId, $data);
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function semana(?int $vendedorId, ?int $ano, ?int $mes, ?int $semana): array
    {
        // Semana pedida (ano + mês + número); senão, a semana atual configurada
        $limites = $this->calendario->semanaAtual();
        if ($ano && $mes && $semana) {
            $linha = $this->semanas->daSemana($ano, $mes, $semana);
            if ($linha) {
                $limites = ['start' => (string) $linha['data_inicio'], 'end' => (string) $linha['data_fim']];
            }
        }
        $tz = Relogio::fuso();
        $p = new DateTime($limites['start'], $tz);
        $fim = new DateTime($limites['end'], $tz);
        $datas = [];
        $rotulos = [];
        while ($p <= $fim) {
            $datas[] = $p->format('Y-m-d');
            $rotulos[] = $p->format('d');
            $p->modify('+1 day');
        }
        $linhas = $this->contratos->linhasPorVendedorEDia($limites['start'], $limites['end'], $vendedorId);
        // um conjunto de dados por closer (inclui ex-closers que tiveram contratos na semana)
        $mapa = [];
        $nomes = [];
        $comDados = array_unique(array_map(fn($r) => (int) $r['vendedor_id'], $linhas));
        foreach ($this->vendedores->todosParaGrafico() as $v) {
            $vid = (int) $v['id'];
            $desligado = $v['deleted_at'] !== null;
            if ($desligado && !in_array($vid, $comDados)) {
                continue;
            }
            $nomes[$vid] = ['name' => $v['nome'] . ($desligado ? ' (Ex)' : ''), 'color' => $v['cor'], 'deleted' => $desligado];
            $mapa[$vid] = array_fill(0, count($datas), 0);
        }
        foreach ($linhas as $r) {
            $vid = $r['vendedor_id'];
            $idx = array_search($r['data_registro'], $datas);
            if ($idx !== false && isset($mapa[(int) $vid])) {
                $mapa[(int) $vid][$idx] = (int) $r['total'];
            }
        }
        $tipos = MixDeBeneficios::expandir($this->contratos->tiposComDescricao($limites['start'], $limites['end'], $vendedorId));
        return ['success' => true, 'labels' => $rotulos, 'vendors' => $mapa, 'vendorNames' => $nomes, 'tipos' => $tipos, 'period' => 'week', 'weekBounds' => $limites];
    }

    /** @return array<string, mixed> */
    private function mes(?int $vendedorId, int $ano, int $mes): array
    {
        $dias = (int) date('t', mktime(0, 0, 0, $mes, 1, $ano));
        $inicio = sprintf('%04d-%02d-01', $ano, $mes);
        $fim = sprintf('%04d-%02d-%02d', $ano, $mes, $dias);
        $rotulos = array_map(fn($d) => str_pad((string) $d, 2, '0', STR_PAD_LEFT), range(1, $dias));
        $porDia = array_fill(1, $dias, 0);
        foreach ($this->contratos->totaisPorDia($inicio, $fim, $vendedorId) as $data => $total) {
            $porDia[(int) date('j', (int) strtotime($data))] = $total;
        }
        [$mapa, $nomes] = $this->mapaPorCloser($this->contratos->totaisPorVendedor($inicio, $fim, $vendedorId));
        $tipos = MixDeBeneficios::expandir($this->contratos->tiposComDescricao($inicio, $fim, $vendedorId));
        return ['success' => true, 'labels' => $rotulos, 'data' => array_values($porDia), 'vendors' => $mapa, 'vendorNames' => $nomes, 'tipos' => $tipos, 'period' => 'month', 'year' => $ano, 'month' => $mes];
    }

    /** @return array<string, mixed> */
    private function ano(?int $vendedorId, int $ano): array
    {
        $meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        $porMes = array_fill(1, 12, 0);
        foreach ($this->contratos->totaisPorMesDoAno($ano, $vendedorId) as $m => $total) {
            $porMes[$m] = $total;
        }
        $tipos = MixDeBeneficios::expandir($this->contratos->tiposComDescricaoDoAno($ano, $vendedorId));
        return ['success' => true, 'labels' => $meses, 'data' => array_values($porMes), 'tipos' => $tipos, 'period' => 'year'];
    }

    /** @return array<string, mixed> */
    private function dia(?int $vendedorId, string $data): array
    {
        [$mapa, $nomes] = $this->mapaPorCloser($this->contratos->totaisPorVendedor($data, $data, $vendedorId));
        $tipos = MixDeBeneficios::expandir($this->contratos->tiposComDescricao($data, $data, $vendedorId));
        return ['success' => true, 'period' => 'day', 'date' => $data, 'labels' => [$data], 'vendors' => $mapa, 'vendorNames' => $nomes, 'tipos' => $tipos];
    }

    /**
     * Um valor por closer que tem total no período: [id] => [total], e os nomes (com " (Ex)" para desligados), na ordem alfabética.
     * @param array<int, int> $totais [vendedor_id] => total
     * @return array{0: array<int, array<int, int>>, 1: array<int, array<string, mixed>>}
     */
    private function mapaPorCloser(array $totais): array
    {
        $mapa = [];
        $nomes = [];
        foreach ($this->vendedores->todosParaGrafico() as $v) {
            $vid = (int) $v['id'];
            if (!array_key_exists($vid, $totais)) {
                continue;
            }
            $desligado = $v['deleted_at'] !== null;
            $nomes[$vid] = ['name' => $v['nome'] . ($desligado ? ' (Ex)' : ''), 'color' => $v['cor'], 'deleted' => $desligado];
            $mapa[$vid] = [$totais[$vid]];
        }
        return [$mapa, $nomes];
    }
}
