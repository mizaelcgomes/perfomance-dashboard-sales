<?php
declare(strict_types=1);

namespace App\Actions\Vendedor;

use App\Core\Resultado;
use App\Repositories\VendedorRepository;

/** Caso de uso: reativar um closer arquivado, mantendo a equipe dele ou passando para outra (equipe 0 = sem equipe). */
final class RestaurarVendedor
{
    public function __construct(private VendedorRepository $vendedores)
    {
    }

    /** @param mixed $id @param mixed $equipeId null ou '' = não mexe na equipe */
    public function executar($id, $equipeId): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        $equipe = $equipeId !== null && $equipeId !== '' ? (int) $equipeId : null;
        if (!$id) {
            return Resultado::falha('ID inválido');
        }
        $this->vendedores->restaurar($id, $equipe !== null, $equipe !== null ? ($equipe ?: null) : null);
        return Resultado::ok([], 'Closer reativado com sucesso');
    }
}
