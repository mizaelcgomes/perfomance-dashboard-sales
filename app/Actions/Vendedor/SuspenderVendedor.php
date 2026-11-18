<?php
declare(strict_types=1);

namespace App\Actions\Vendedor;

use App\Core\Resultado;
use App\Repositories\VendedorRepository;

/** Caso de uso: colocar um closer em férias agora (suspended_at). Ele sai do ranking, mas nada é apagado. */
final class SuspenderVendedor
{
    public function __construct(private VendedorRepository $vendedores)
    {
    }

    /** @param mixed $id */
    public function executar($id): Resultado
    {
        $id = (int) (is_scalar($id) ? $id : 0);
        if (!$id) {
            return Resultado::falha('ID inválido');
        }
        $this->vendedores->suspender($id);
        return Resultado::ok([], 'Closer em férias — removido do ranking');
    }
}
