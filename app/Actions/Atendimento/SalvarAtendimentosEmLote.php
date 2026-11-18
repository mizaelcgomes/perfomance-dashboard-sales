<?php
declare(strict_types=1);

namespace App\Actions\Atendimento;

use App\Core\Resultado;
use App\Repositories\AtendimentoRepository;

/** Caso de uso: lançar vários dias de atendimentos de um closer de uma vez ({"AAAA-MM-DD": quantidade}). Datas inválidas são ignoradas. */
final class SalvarAtendimentosEmLote
{
    public function __construct(private AtendimentoRepository $atendimentos)
    {
    }

    /** @param mixed $vendedorId @param mixed $dias JSON {"AAAA-MM-DD": quantidade} */
    public function executar($vendedorId, $dias): Resultado
    {
        $vid = (int) (is_scalar($vendedorId) ? $vendedorId : 0);
        $mapa = json_decode(is_string($dias) ? $dias : '{}', true);
        if (!$vid || !is_array($mapa)) {
            return Resultado::falha('Dados inválidos');
        }
        foreach ($mapa as $data => $quantidade) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data)) {
                continue;
            }
            $this->atendimentos->salvarDia($vid, (string) $data, max(0, (int) (is_scalar($quantidade) ? $quantidade : 0)));
        }
        return Resultado::ok([], 'Atendimentos salvos');
    }
}
