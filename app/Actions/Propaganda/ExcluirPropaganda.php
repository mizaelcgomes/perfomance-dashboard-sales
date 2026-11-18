<?php
declare(strict_types=1);

namespace App\Actions\Propaganda;

use App\Core\Resultado;
use App\Repositories\PropagandaRepository;

/** Caso de uso: remover uma propaganda. */
final class ExcluirPropaganda
{
    public function __construct(private PropagandaRepository $propagandas)
    {
    }

    public function executar(int $id): Resultado
    {
        if (!$id) {
            return Resultado::falha('ID inválido');
        }
        $this->propagandas->excluir($id);
        return Resultado::ok([], 'Propaganda removida');
    }
}
