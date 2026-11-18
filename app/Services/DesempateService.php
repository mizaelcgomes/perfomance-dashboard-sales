<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfiguracaoRepository;

/**
 * Critérios de desempate do ranking (aba Rankings do admin): a ordem escolhida e a comparação entre dois closers por um critério.
 * Desde a fase 3 é a ÚNICA implementação da comparação (o tbCompareEntry saiu do api.php). Só a ordem PADRÃO ainda existe também em
 * getDefaultTbOrder() do api.php, usada pela aba de critérios (get_tiebreaker/save_tiebreaker), que migra na fase 4.
 */
final class DesempateService
{
    public const ORDEM_PADRAO = ['aux_acidente', 'bpc_loas', 'sal_maternidade', 'aux_doenca', 'outros'];

    public function __construct(private ConfiguracaoRepository $config)
    {
    }

    /** Ordem de desempate salva pelo admin (ou a padrão). @return array<int, mixed> */
    public function ordem(): array
    {
        $bruto = $this->config->valor('tiebreaker_order', (string) json_encode(self::ORDEM_PADRAO));
        $ordem = json_decode((string) $bruto, true);
        return $ordem ?: self::ORDEM_PADRAO;
    }

    /**
     * Posição de cada closer num período: maior total primeiro; empate pelos critérios do admin, na ordem salva; por último o nome.
     * Cada linha: ['id' => int, 'nome' => string, 'total' => int, 'tipos' => [benefício => qtd]]. Devolve [id => posição (1, 2, …)].
     * O relatório de Performance de Closers usa isto para o mês e para cada semana (a tela e o PDF leem a mesma posição).
     * @param array<int, array<string, mixed>> $linhas
     * @return array<int, int>
     */
    public function posicoes(array $linhas): array
    {
        $ordem = $this->ordem();
        usort($linhas, function (array $a, array $b) use ($ordem): int {
            if ($a['total'] !== $b['total']) {
                return $b['total'] <=> $a['total'];
            }
            foreach ($ordem as $criterio) {
                $c = $this->comparar($a, $b, $criterio, 'tipos', 'conv');   // o relatório não tem taxa de conversão: o critério 'conv_rate' não separa ninguém
                if ($c !== 0) {
                    return $c;
                }
            }
            return strcasecmp($a['nome'], $b['nome']);
        });
        $saida = [];
        foreach ($linhas as $i => $l) {
            $saida[(int) $l['id']] = $i + 1;
        }
        return $saida;
    }

    /**
     * Compara dois closers por UM critério: menor que 0 = $a na frente, maior que 0 = $b na frente.
     * O critério é uma chave de benefício, 'conv_rate' (taxa de conversão) ou ['grupo' => [chaves...]] (soma de benefícios).
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param mixed $criterio
     */
    public function comparar(array $a, array $b, $criterio, string $chaveTipos, string $chaveConversao): int
    {
        if (is_array($criterio) && isset($criterio['grupo'])) {
            $va = array_sum(array_map(static fn($t) => $a[$chaveTipos][$t] ?? 0, (array) $criterio['grupo']));
            $vb = array_sum(array_map(static fn($t) => $b[$chaveTipos][$t] ?? 0, (array) $criterio['grupo']));
            return $vb - $va;
        }
        if ($criterio === 'conv_rate') {
            $dif = ($b[$chaveConversao] ?? 0) - ($a[$chaveConversao] ?? 0);
            return abs($dif) > 0.001 ? ($dif > 0 ? 1 : -1) : 0;
        }
        return ($b[$chaveTipos][$criterio] ?? 0) - ($a[$chaveTipos][$criterio] ?? 0);
    }
}
