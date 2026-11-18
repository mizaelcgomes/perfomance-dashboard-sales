<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Cobranças de cadastramento feitas pelo líder (quem cobra é o líder; não há disparo automático). */
final class CobrancaCadastroRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $equipeId): array
    {
        return $this->banco->consultar('SELECT * FROM cobrancas_cadastro WHERE equipe_id = ? ORDER BY data DESC, id DESC', [$equipeId]);
    }

    /** @return array<string, mixed>|null */
    public function buscar(int $id, int $equipeId): ?array
    {
        return $this->banco->linha('SELECT * FROM cobrancas_cadastro WHERE id = ? AND equipe_id = ?', [$id, $equipeId]);
    }

    public function inserir(int $equipeId, int $closerVendedorId, int $liderVendedorId, string $data, ?string $observacao, string $agora): int
    {
        $this->banco->executar(
            'INSERT INTO cobrancas_cadastro (equipe_id, closer_vendedor_id, lider_vendedor_id, data, observacao, criado_em) VALUES (?, ?, ?, ?, ?, ?)',
            [$equipeId, $closerVendedorId, $liderVendedorId, $data, $observacao, $agora]
        );
        return (int) $this->banco->idInserido();
    }

    /** Cobranças que um closer recebeu (o id é o do vendedor da sessão, nunca vindo da requisição). @return array<int, array<string, mixed>> */
    public function listarDoCloser(int $closerVendedorId): array
    {
        return $this->banco->consultar('SELECT * FROM cobrancas_cadastro WHERE closer_vendedor_id = ? ORDER BY data DESC, id DESC', [$closerVendedorId]);
    }

    /** Edita a observação (closer, data e equipe não mudam) e limpa o "visto". Devolve se achou a cobrança NA EQUIPE. */
    public function atualizar(int $id, int $equipeId, ?string $observacao, string $agora): bool
    {
        $this->banco->executar(
            'UPDATE cobrancas_cadastro SET observacao = ?, atualizado_em = ?, visto_em = NULL WHERE id = ? AND equipe_id = ?',
            [$observacao, $agora, $id, $equipeId]
        );
        return $this->buscar($id, $equipeId) !== null;
    }

    public function contarNaoVistosDoCloser(int $closerVendedorId): int
    {
        return (int) $this->banco->valor('SELECT COUNT(*) FROM cobrancas_cadastro WHERE closer_vendedor_id = ? AND visto_em IS NULL', [$closerVendedorId]);
    }

    public function marcarVistosDoCloser(int $closerVendedorId, string $agora): int
    {
        return $this->banco->executar('UPDATE cobrancas_cadastro SET visto_em = ? WHERE closer_vendedor_id = ? AND visto_em IS NULL', [$agora, $closerVendedorId]);
    }
}
