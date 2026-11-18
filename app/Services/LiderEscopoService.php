<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Quem é líder de qual equipe, decidido a CADA requisição (nada disso fica guardado na sessão).
 *
 * Uma conta (closer_usuarios.id) é líder da equipe X quando:
 *  - a conta está ativa, vinculada a um vendedor, e esse vendedor não foi arquivado;
 *  - o vendedor da conta pertence à equipe X (vendedores.equipe_id = equipes.id);
 *  - o vendedor é o líder cadastrado dessa equipe (equipes.lider_id = vendedores.id).
 * Tirar a liderança, arquivar o closer, suspender a conta ou trocar a equipe do closer derruba o acesso na hora.
 */
final class LiderEscopoService
{
    public function __construct(private Database $banco)
    {
    }

    /** Id da equipe que a conta lidera agora, ou null se a conta não é líder de nenhuma equipe. */
    public function equipeDoLider(int $contaId): ?int
    {
        if ($contaId <= 0) {
            return null;
        }
        $linha = $this->banco->linha(
            "SELECT e.id AS equipe_id
               FROM closer_usuarios u
               JOIN vendedores v ON v.id = u.vendedor_id AND v.deleted_at IS NULL
               JOIN equipes e ON e.id = v.equipe_id AND e.lider_id = v.id AND e.oculta_tv = 0
              WHERE u.id = ? AND u.status = 'ativo' AND u.vendedor_id IS NOT NULL
              LIMIT 1",
            [$contaId]
        );
        return $linha === null ? null : (int) $linha['equipe_id'];
    }
}
