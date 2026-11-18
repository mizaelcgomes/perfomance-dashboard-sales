<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Feedbacks individuais. A leitura do líder é pela equipe; a do closer é pelo próprio vendedor (ele só vê o que recebeu). */
final class FeedbackRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listarDaEquipe(int $equipeId): array
    {
        return $this->banco->consultar('SELECT * FROM feedbacks WHERE equipe_id = ? ORDER BY data DESC, id DESC', [$equipeId]);
    }

    /** Feedbacks que um closer recebeu (o id é o do vendedor da sessão, nunca vindo da requisição). @return array<int, array<string, mixed>> */
    public function listarDoCloser(int $closerVendedorId): array
    {
        return $this->banco->consultar('SELECT * FROM feedbacks WHERE closer_vendedor_id = ? ORDER BY data DESC, id DESC', [$closerVendedorId]);
    }

    /** @return array<string, mixed>|null */
    public function buscar(int $id, int $equipeId): ?array
    {
        return $this->banco->linha('SELECT * FROM feedbacks WHERE id = ? AND equipe_id = ?', [$id, $equipeId]);
    }

    public function inserir(int $equipeId, int $closerVendedorId, int $liderVendedorId, string $data, string $assunto, string $texto, string $agora): int
    {
        $this->banco->executar(
            'INSERT INTO feedbacks (equipe_id, closer_vendedor_id, lider_vendedor_id, data, assunto, texto, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$equipeId, $closerVendedorId, $liderVendedorId, $data, $assunto, $texto, $agora]
        );
        return (int) $this->banco->idInserido();
    }

    /**
     * Edita assunto e texto (o closer, a data e a equipe não mudam). A edição limpa o "visto": o closer vê de novo como atualizado.
     * Devolve se achou o feedback NA EQUIPE.
     */
    public function atualizar(int $id, int $equipeId, string $assunto, string $texto, string $agora): bool
    {
        $this->banco->executar(
            'UPDATE feedbacks SET assunto = ?, texto = ?, atualizado_em = ?, visto_em = NULL WHERE id = ? AND equipe_id = ?',
            [$assunto, $texto, $agora, $id, $equipeId]
        );
        return $this->buscar($id, $equipeId) !== null;
    }

    /** Quantos o closer ainda não viu (o id é o do vendedor da sessão). */
    public function contarNaoVistosDoCloser(int $closerVendedorId): int
    {
        return (int) $this->banco->valor('SELECT COUNT(*) FROM feedbacks WHERE closer_vendedor_id = ? AND visto_em IS NULL', [$closerVendedorId]);
    }

    /** Marca como vistos os feedbacks do closer. Devolve quantos mudaram. */
    public function marcarVistosDoCloser(int $closerVendedorId, string $agora): int
    {
        return $this->banco->executar('UPDATE feedbacks SET visto_em = ? WHERE closer_vendedor_id = ? AND visto_em IS NULL', [$agora, $closerVendedorId]);
    }
}
