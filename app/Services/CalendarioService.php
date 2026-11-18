<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SemanaRepository;

/**
 * A aba Calendário do admin: as semanas de um mês (as configuradas ou as automáticas, de quarta a terça) com as datas de cada dia, o histórico
 * dos meses configurados e a quantidade de dias da semana. As regras de qual é a semana atual e das semanas automáticas ficam em Calendario.
 */
final class CalendarioService
{
    public function __construct(private SemanaRepository $semanas, private Calendario $calendario)
    {
    }

    /** As semanas configuradas do mês, sem cálculo automático. @return array<int, array<string, mixed>> */
    public function configuradas(int $ano, int $mes): array
    {
        return $this->semanas->doMes($ano, $mes);
    }

    /**
     * As semanas do mês com as datas de cada dia: as configuradas; sem configuração, as que começam na quarta-feira e caem no mês.
     * @return array{data: array<int, array<string, mixed>>, configured: bool}
     */
    public function doMes(int $ano, int $mes): array
    {
        $cfg = $this->semanas->doMes($ano, $mes);
        $semanas = [];
        if ($cfg) {
            foreach ($cfg as $r) {
                $semanas[] = [
                    'semana_num' => (int) $r['semana_num'], 'data_inicio' => $r['data_inicio'], 'data_fim' => $r['data_fim'],
                    'dates' => $this->calendario->datas((string) $r['data_inicio'], (string) $r['data_fim']), 'configured' => true,
                ];
            }
        } else {
            foreach ($this->calendario->semanasDoMes($ano, $mes) as $w) {
                $semanas[] = [
                    'semana_num' => $w['semana_num'], 'data_inicio' => $w['start'], 'data_fim' => $w['end'],
                    'dates' => $this->calendario->datas($w['start'], $w['end']), 'configured' => false,
                ];
            }
        }
        return ['data' => $semanas, 'configured' => (bool) $cfg];
    }

    /** Os meses que têm semanas configuradas (os 36 mais recentes). @return array<int, array<string, mixed>> */
    public function historico(): array
    {
        return $this->semanas->historico();
    }

    /**
     * Rótulos e dias do mês dos $quantos primeiros dias do ciclo (quarta, quinta, ...), a partir da quarta-feira da semana automática atual.
     * @return array{labels: array<int, string>, datas: array<int, string>}
     */
    public function diasDoCiclo(int $quantos): array
    {
        $quantos = max(1, min(14, $quantos));
        $rotulos = ['Qua', 'Qui', 'Sex', 'Sáb', 'Dom', 'Seg', 'Ter'];
        $quarta = new \DateTimeImmutable($this->calendario->limitesAutomaticos()['start'], \App\Core\Relogio::fuso());
        $labels = [];
        $datas = [];
        for ($i = 0; $i < $quantos; $i++) {
            $labels[] = $rotulos[$i % 7];
            $datas[] = $quarta->modify('+' . $i . ' days')->format('d');
        }
        return ['labels' => $labels, 'datas' => $datas];
    }
}
