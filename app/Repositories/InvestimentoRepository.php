<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Investimento em anúncios por mês (tabela investimento_mensal), digitado à mão na aba Investimento. É a base do CAC.
 * (O Meta Ads foi removido do sistema em 11/11/2026: o valor nunca mais vem de fora.)
 */
final class InvestimentoRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** Investimento do mês em reais (0 se não houver registro), com 2 casas. */
    public function doMes(int $ano, int $mes): float
    {
        $valor = $this->banco->valor('SELECT investimento FROM investimento_mensal WHERE ano=? AND mes=?', [$ano, $mes]);
        return round((float) ($valor ?: 0), 2);
    }

    /** @return array<string, array<string, mixed>> "AAAA-MM" => linha de investimento_mensal */
    public function todosPorMes(): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT * FROM investimento_mensal') as $r) {
            $saida[sprintf('%04d-%02d', (int) $r['ano'], (int) $r['mes'])] = $r;
        }
        return $saida;
    }

    /** Grava (ou troca) o mês inteiro: investimento, alcance, impressões, CPM, frequência e observação. */
    public function salvarMes(int $ano, int $mes, float $investimento, int $alcance, int $impressoes, float $cpm, float $frequencia, ?string $obs): void
    {
        $this->banco->executar(
            'INSERT INTO investimento_mensal (ano,mes,investimento,alcance,impressoes,cpm,frequencia,obs)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE investimento=VALUES(investimento), alcance=VALUES(alcance),
               impressoes=VALUES(impressoes), cpm=VALUES(cpm), frequencia=VALUES(frequencia), obs=VALUES(obs)',
            [$ano, $mes, $investimento, $alcance, $impressoes, $cpm, $frequencia, $obs]
        );
    }

    public function excluirMes(int $ano, int $mes): void
    {
        $this->banco->executar('DELETE FROM investimento_mensal WHERE ano=? AND mes=?', [$ano, $mes]);
    }
}
