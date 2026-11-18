<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ContratoRepository;
use App\Repositories\InvestimentoRepository;
use DateTimeImmutable;

/**
 * Investimento em anúncios que alimenta o CAC da TV e do Painel: o valor digitado à mão na aba Investimento (o Meta Ads foi removido do
 * sistema em 11/11/2026). A TV lê o valor do mês corrente (investimento_mes); o Painel lê o mesmo valor no mês corrente e o histórico mensal
 * nos meses passados. As respostas mantêm o campo "fonte" (sempre "manual") para não quebrar quem já o lê. Também monta a série histórica
 * da aba Investimento (get_investimento_historico), em que o CAC e os contratos por dia são SEMPRE derivados aqui, para não existirem dois
 * números diferentes circulando.
 */
final class InvestimentoService
{
    public function __construct(
        private ConfiguracaoRepository $config,
        private InvestimentoRepository $investimentos,
        private ContratoRepository $contratos
    ) {
    }

    /**
     * Investimento da TV (mês corrente): o valor manual.
     * @return array{investimento: float, fonte: string}
     */
    public function paraTv(int $ano, int $mes): array
    {
        return ['investimento' => (float) $this->config->valor('investimento_mes', '0'), 'fonte' => 'manual'];
    }

    /**
     * Investimento do Painel: no mês corrente, o valor manual; nos meses passados, o histórico mensal.
     * @return array{investimento: float, fonte: string}
     */
    public function paraPainel(int $ano, int $mes, bool $mesCorrente): array
    {
        if (!$mesCorrente) {
            return ['investimento' => $this->investimentos->doMes($ano, $mes), 'fonte' => 'manual'];
        }
        return ['investimento' => (float) $this->config->valor('investimento_mes', '0'), 'fonte' => 'manual'];
    }

    /**
     * A série histórica: todo mês que tem contrato OU investimento registrado (os 24 mais recentes), com CAC e contratos por dia. O mês
     * corrente conta só os dias já decorridos (dividir 10 contratos por 31 dias no dia 3 daria uma velocidade falsamente péssima).
     * @return array{data: array<int, array<string, mixed>>, meta_cac: float}
     */
    public function historico(): array
    {
        $agora = Relogio::agora();
        $ymAtual = $agora->format('Y-m');
        $contratos = $this->contratos->totaisPorMes();
        $investimentos = $this->investimentos->todosPorMes();

        $chaves = array_unique(array_merge(array_keys($contratos), array_keys($investimentos)));
        sort($chaves);
        if (count($chaves) > 24) {
            $chaves = array_slice($chaves, -24);
        }

        $serie = [];
        foreach ($chaves as $ym) {
            [$ano, $mes] = array_map('intval', explode('-', (string) $ym));
            $linha = $investimentos[$ym] ?? null;
            $inv = $linha ? (float) $linha['investimento'] : 0.0;
            $ctr = $contratos[$ym] ?? 0;
            $parcial = ($ym === $ymAtual);
            $diasDoMes = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes), Relogio::fuso()))->format('t');
            $dias = $parcial ? max(1, (int) $agora->format('j')) : $diasDoMes;
            $serie[] = [
                'ym' => $ym, 'ano' => $ano, 'mes' => $mes, 'parcial' => $parcial, 'dias' => $dias, 'contratos' => $ctr,
                'investimento' => round($inv, 2),
                'cac' => ($inv > 0 && $ctr > 0) ? round($inv / $ctr, 2) : null,
                'contratos_dia' => round($ctr / max(1, $dias), 2),
                'cpm' => $linha ? (float) $linha['cpm'] : 0.0,
                'frequencia' => $linha ? (float) $linha['frequencia'] : 0.0,
                'alcance' => $linha ? (int) $linha['alcance'] : 0,
                'impressoes' => $linha ? (int) $linha['impressoes'] : 0,
                'obs' => $linha ? $linha['obs'] : null,
            ];
        }
        return ['data' => $serie, 'meta_cac' => (float) $this->config->valor('meta_cac', '85')];
    }
}
