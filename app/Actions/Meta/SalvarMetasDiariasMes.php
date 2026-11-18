<?php
declare(strict_types=1);

namespace App\Actions\Meta;

use App\Core\Resultado;
use App\Repositories\MetaRepository;
use App\Services\MetaDiaria;

/**
 * Caso de uso: gravar metas diárias (closer, dia do mês, meta). A lista vem em JSON num único campo, não como metas[i][k], porque com muitos
 * closers e dias o PHP passava do max_input_vars (1000) e perdia entradas sem avisar. Entrada sem closer ou com dia fora do mês é ignorada.
 */
final class SalvarMetasDiariasMes
{
    public function __construct(private MetaRepository $metas)
    {
    }

    /** @param mixed $ano @param mixed $mes @param mixed $metasJson */
    public function executar($ano, $mes, $metasJson): Resultado
    {
        $ano = (int) $ano;
        $mes = (int) $mes;
        $lista = json_decode(is_string($metasJson) ? $metasJson : '[]', true);
        if (!$ano || $mes < 1 || $mes > 12 || !is_array($lista)) {
            return Resultado::falha('Dados inválidos');
        }
        $ultimoDia = MetaDiaria::diasDoMes($ano, $mes);
        foreach ($lista as $m) {
            $closerId = (int) ($m['closer_id'] ?? 0);
            $dia = (int) ($m['dia'] ?? 0);
            $meta = max(0, (int) ($m['meta'] ?? 0));
            if (!$closerId || $dia < 1 || $dia > $ultimoDia) {
                continue;
            }
            $this->metas->salvarDiaria($closerId, $ano, $mes, $dia, $meta);
        }
        return Resultado::ok([], 'Metas diárias salvas');
    }
}
