<?php
declare(strict_types=1);

namespace App\Actions\Investimento;

use App\Core\Relogio;
use App\Core\Resultado;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\InvestimentoRepository;

/**
 * Caso de uso: gravar o investimento de um mês (aba Investimento e painel rápido): investimento, alcance, impressões, CPM, frequência e
 * observação. CPM e frequência, se não vierem, são calculados (investimento ÷ impressões × 1000 e impressões ÷ alcance), para a tabela
 * nunca ficar meio vazia. No mês corrente o valor também vira o "investimento_mes", que a TV e o Painel leem para mostrar o CAC ao vivo.
 */
final class SalvarInvestimentoMensal
{
    public function __construct(private InvestimentoRepository $investimentos, private ConfiguracaoRepository $config)
    {
    }

    /** @param mixed $ano @param mixed $mes @param mixed $investimento @param mixed $alcance @param mixed $impressoes @param mixed $cpm @param mixed $frequencia @param mixed $obs */
    public function executar($ano, $mes, $investimento, $alcance, $impressoes, $cpm, $frequencia, $obs): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        if (!$ano || $mes < 1 || $mes > 12) {
            return Resultado::falha('Mês inválido');
        }
        $decimal = static fn($v): float => max(0, (float) str_replace(',', '.', is_scalar($v) ? (string) $v : '0'));
        $inteiro = static fn($v): int => max(0, (int) (is_scalar($v) ? $v : 0));
        $inv = $decimal($investimento);
        $alc = $inteiro($alcance);
        $imp = $inteiro($impressoes);
        $cpm = $decimal($cpm);
        $freq = $decimal($frequencia);
        $obs = trim(is_scalar($obs) ? (string) $obs : '') ?: null;

        if ($cpm <= 0 && $imp > 0 && $inv > 0) {
            $cpm = round($inv / ($imp / 1000), 2);
        }
        if ($freq <= 0 && $alc > 0 && $imp > 0) {
            $freq = round($imp / $alc, 2);
        }
        $this->investimentos->salvarMes($ano, $mes, $inv, $alc, $imp, $cpm, $freq, $obs);

        $agora = Relogio::agora();
        if ((int) $agora->format('Y') === $ano && (int) $agora->format('n') === $mes) {
            $this->config->definir('investimento_mes', (string) $inv);
        }
        return Resultado::ok(['cpm' => $cpm, 'frequencia' => $freq], 'Investimento salvo');
    }
}
