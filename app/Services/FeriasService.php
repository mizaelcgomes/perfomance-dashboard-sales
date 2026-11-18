<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\VendedorRepository;
use Throwable;

/**
 * Mantém a suspensão (suspended_at) dos closers em dia com as férias agendadas: férias cujo último dia já passou reativam o closer
 * e limpam as datas; férias que já começaram (e não acabaram) suspendem (o closer some do ranking). Férias SEM datas, marcadas à
 * mão, não são tocadas. Roda antes de toda leitura de ranking (são 2 UPDATEs numa tabela pequena), sem depender de cron.
 * Reproduz o syncFerias do config.php. Uma falha só é registrada no log: nunca derruba a resposta.
 */
final class FeriasService
{
    public function __construct(private VendedorRepository $vendedores)
    {
    }

    public function sincronizar(): void
    {
        try {
            $this->vendedores->sincronizarFerias(Relogio::hoje());
        } catch (Throwable $erro) {
            error_log('syncFerias: ' . $erro->getMessage());
        }
    }
}
