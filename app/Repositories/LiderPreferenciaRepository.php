<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Preferências de alerta do líder (Configurações do portal do líder): uma linha por líder (vendedor). */
final class LiderPreferenciaRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<string, mixed>|null */
    public function buscar(int $vendedorId): ?array
    {
        return $this->banco->linha('SELECT * FROM lider_preferencias WHERE vendedor_id = ?', [$vendedorId]);
    }

    public function salvar(int $vendedorId, int $limiteDiasSemVenda, int $diasSemFeedback, string $alertasJson, string $agora): void
    {
        $this->banco->executar(
            'INSERT INTO lider_preferencias (vendedor_id, limite_dias_sem_venda, dias_sem_feedback, alertas, atualizado_em) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE limite_dias_sem_venda = VALUES(limite_dias_sem_venda), dias_sem_feedback = VALUES(dias_sem_feedback), alertas = VALUES(alertas), atualizado_em = VALUES(atualizado_em)',
            [$vendedorId, $limiteDiasSemVenda, $diasSemFeedback, $alertasJson, $agora]
        );
    }
}
