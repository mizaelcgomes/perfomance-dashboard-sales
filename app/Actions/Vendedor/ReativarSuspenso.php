<?php
declare(strict_types=1);

namespace App\Actions\Vendedor;

use App\Core\Resultado;
use App\Repositories\VendedorRepository;

/** Caso de uso: o closer volta das férias. Também cancela férias agendadas (que ainda não tinham começado). */
final class ReativarSuspenso
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
        $this->vendedores->reativarSuspenso($id);
        return Resultado::ok([], 'Closer voltou das férias');
    }
}
