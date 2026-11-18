<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Resultado;
use App\Repositories\MetaRepository;
use App\Services\MetaDiaria;

/**
 * Caso de uso: gravar a meta diária de UM closer no mês (vira exceção quando difere do template). dias = {"1":6,"2":6,...}; dia ausente ou
 * vazio = sem meta naquele dia; dia fora do mês é ignorado. Troca a série inteira do closer no mês.
 */
final class SalvarMetaDiariaCloser
{
    public function __construct(private MetaRepository $metas)
    {
    }

    /** @param mixed $ano @param mixed $mes @param mixed $closerId @param mixed $diasJson */
    public function executar($ano, $mes, $closerId, $diasJson): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        $cid = (int) $closerId;
        $in = json_decode(is_string($diasJson) ? $diasJson : '{}', true);
        if ($ano < 2000 || $mes < 1 || $mes > 12 || !$cid || !is_array($in)) {
            return Resultado::falha('Dados inválidos');
        }
        if (!$this->metas->closerExiste($cid)) {
            return Resultado::falha('Closer não encontrado');
        }
        $dias = MetaDiaria::diasDoMes($ano, $mes);
        $serie = [];
        foreach ($in as $d => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            if ((int) $d < 1 || (int) $d > $dias) {
                continue;
            }
            if (!is_numeric($v) || (int) $v < 0 || (int) $v > 999) {
                return Resultado::falha('Use números de 0 a 999 nas metas diárias.');
            }
            $serie[(int) $d] = (int) $v;
        }
        $this->metas->substituirDiarias($cid, $ano, $mes, $serie);
        return Resultado::ok(['dias_com_meta' => count($serie)], 'Metas diárias salvas');
    }
}
