<?php
declare(strict_types=1);

namespace App\Actions\Propaganda;

use App\Core\Resultado;
use App\Repositories\PropagandaRepository;

/** Caso de uso: ligar/desligar uma propaganda na rotação (inverte a situação). */
final class AlternarPropaganda
{
    public function __construct(private PropagandaRepository $propagandas)
    {
    }

    public function executar(int $id): Resultado
    {
        if (!$id) {
            return Resultado::falha('ID inválido');
        }
        $ativo = $this->propagandas->alternar($id);
        return Resultado::ok(['ativo' => $ativo], $ativo ? 'Ativada' : 'Desativada');
    }
}
