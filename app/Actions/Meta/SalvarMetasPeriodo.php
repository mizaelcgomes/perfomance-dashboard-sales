<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Resultado;
use App\Repositories\MetaRepository;

/**
 * Caso de uso: gravar metas por período (closer, equipe ou geral; semana ou mês). semana_num 0 = meta do mês; 1 em diante = meta daquela
 * semana. tipo 'geral' tem ref_id sempre 0. Linha sem closer/equipe (e que não é geral) é ignorada; meta negativa vira 0.
 */
final class SalvarMetasPeriodo
{
    public function __construct(private MetaRepository $metas)
    {
    }

    /** @param mixed $ano @param mixed $mes @param mixed $metas lista de [tipo, ref_id, semana_num, meta] */
    public function executar($ano, $mes, $metas): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        if (!$ano || $mes < 1 || $mes > 12 || !is_array($metas)) {
            return Resultado::falha('Dados inválidos');
        }
        foreach ($metas as $m) {
            if (!is_array($m)) {
                continue;
            }
            $tipoIn = $m['tipo'] ?? '';
            $tipo = in_array($tipoIn, ['equipe', 'geral'], true) ? $tipoIn : 'closer';
            $refId = $tipo === 'geral' ? 0 : (int) ($m['ref_id'] ?? 0);
            $semNum = isset($m['semana_num']) && $m['semana_num'] !== '' ? (int) $m['semana_num'] : 0;
            $meta = max(0, (int) ($m['meta'] ?? 0));
            if ($tipo !== 'geral' && !$refId) {
                continue;
            }
            $this->metas->salvarPeriodo($tipo, $refId, $ano, $mes, $semNum, $meta);
        }
        return Resultado::ok([], 'Metas salvas');
    }
}
