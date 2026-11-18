<?php
declare(strict_types=1);

namespace App\Actions\Atendimento;

use App\Core\Resultado;
use App\Repositories\AtendimentoRepository;

/** Caso de uso: lançar os atendimentos de um closer num dia (base da taxa de conversão). Quantidade 0 apaga o lançamento do dia. */
final class SalvarAtendimento
{
    public function __construct(private AtendimentoRepository $atendimentos)
    {
    }

    /** @param mixed $vendedorId @param mixed $data AAAA-MM-DD @param mixed $quantidade */
    public function executar($vendedorId, $data, $quantidade): Resultado
    {
        $vid = (int) (is_scalar($vendedorId) ? $vendedorId : 0);
        $data = trim(is_scalar($data) ? (string) $data : '');
        $qtd = max(0, (int) (is_scalar($quantidade) ? $quantidade : 0));
        if (!$vid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            return Resultado::falha('Dados inválidos');
        }
        $this->atendimentos->salvarDia($vid, $data, $qtd);
        return Resultado::ok([], 'Atendimento salvo');
    }
}
