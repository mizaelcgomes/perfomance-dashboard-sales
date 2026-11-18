<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Revisões de atendimento: o líder revisa um atendimento de um closer da equipe, pelo telefone do cliente. */
final class RevisaoAtendimentoRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $equipeId): array
    {
        return $this->banco->consultar('SELECT * FROM revisoes_atendimento WHERE equipe_id = ? ORDER BY data DESC, id DESC', [$equipeId]);
    }

    /** @return array<string, mixed>|null */
    public function buscar(int $id, int $equipeId): ?array
    {
        return $this->banco->linha('SELECT * FROM revisoes_atendimento WHERE id = ? AND equipe_id = ?', [$id, $equipeId]);
    }

    public function inserir(int $equipeId, int $closerVendedorId, string $telefoneCliente, ?string $observacao, int $liderVendedorId, string $data, string $agora): int
    {
        $this->banco->executar(
            'INSERT INTO revisoes_atendimento (equipe_id, closer_vendedor_id, telefone_cliente, observacao, lider_vendedor_id, data, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$equipeId, $closerVendedorId, $telefoneCliente, $observacao, $liderVendedorId, $data, $agora]
        );
        return (int) $this->banco->idInserido();
    }

    /** Edita telefone e observação (closer, data e equipe não mudam). Devolve se achou a revisão NA EQUIPE. */
    public function atualizar(int $id, int $equipeId, string $telefoneCliente, ?string $observacao, string $agora): bool
    {
        $this->banco->executar(
            'UPDATE revisoes_atendimento SET telefone_cliente = ?, observacao = ?, atualizado_em = ? WHERE id = ? AND equipe_id = ?',
            [$telefoneCliente, $observacao, $agora, $id, $equipeId]
        );
        return $this->buscar($id, $equipeId) !== null;
    }
}
