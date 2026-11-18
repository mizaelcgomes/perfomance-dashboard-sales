<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Treinamentos e presença. Um treinamento é da equipe (equipe_id preenchido) ou geral (equipe_id NULL, criado pelo admin):
 * o líder vê os da equipe dele e os gerais. Nunca os de outra equipe.
 */
final class TreinamentoRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $equipeId): array
    {
        return $this->banco->consultar(
            'SELECT * FROM treinamentos WHERE equipe_id = ? OR equipe_id IS NULL ORDER BY data_hora DESC, id DESC',
            [$equipeId]
        );
    }

    /** @return array<string, mixed>|null */
    public function buscar(int $id, int $equipeId): ?array
    {
        return $this->banco->linha('SELECT * FROM treinamentos WHERE id = ? AND (equipe_id = ? OR equipe_id IS NULL)', [$id, $equipeId]);
    }

    /** $equipeId null = treinamento geral. */
    public function inserir(?int $equipeId, string $dataHora, string $assunto, int $criadoPor, string $agora): int
    {
        $this->banco->executar(
            'INSERT INTO treinamentos (equipe_id, data_hora, assunto, criado_por, criado_em) VALUES (?, ?, ?, ?, ?)',
            [$equipeId, $dataHora, $assunto, $criadoPor, $agora]
        );
        return (int) $this->banco->idInserido();
    }

    /** Edita data/hora e assunto de um treinamento DA EQUIPE (o geral não é editável pelo líder). Devolve se achou o treinamento da equipe. */
    public function atualizar(int $id, int $equipeId, string $dataHora, string $assunto, string $agora): bool
    {
        return $this->banco->executar(
            'UPDATE treinamentos SET data_hora = ?, assunto = ?, atualizado_em = ? WHERE id = ? AND equipe_id = ?',
            [$dataHora, $assunto, $agora, $id, $equipeId]
        ) > 0 || $this->banco->linha('SELECT id FROM treinamentos WHERE id = ? AND equipe_id = ?', [$id, $equipeId]) !== null;
    }

    /**
     * Define a presença dos closers DE UMA EQUIPE: apaga as presenças dos membros dessa equipe e grava as novas. Presença de gente de outra
     * equipe (num treinamento geral, que várias equipes dividem) não é tocada. $membros e $presentes são ids de vendedores já validados.
     * @param array<int, int> $membros todos os vendedores da equipe (escopo da troca)
     * @param array<int, int> $presentes subconjunto de $membros que esteve presente
     */
    public function definirPresencas(int $treinamentoId, array $membros, array $presentes): void
    {
        if ($membros === []) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($membros), '?'));
        $this->banco->executar("DELETE FROM treinamento_presencas WHERE treinamento_id = ? AND vendedor_id IN ($marcas)", array_merge([$treinamentoId], $membros));
        foreach ($presentes as $vendedorId) {
            $this->marcarPresenca($treinamentoId, $vendedorId);
        }
    }

    /** Marca presença. O id do treinamento precisa já ter passado pelo buscar() com o escopo do líder. */
    public function marcarPresenca(int $treinamentoId, int $vendedorId): void
    {
        $this->banco->executar(
            'INSERT IGNORE INTO treinamento_presencas (treinamento_id, vendedor_id) VALUES (?, ?)',
            [$treinamentoId, $vendedorId]
        );
    }

    /** Cancela um treinamento DA EQUIPE (o geral não). Devolve se cancelou agora (false se não achou ou se já estava cancelado). */
    public function cancelar(int $id, int $equipeId, string $agora): bool
    {
        return $this->banco->executar(
            'UPDATE treinamentos SET cancelado_em = ?, atualizado_em = ? WHERE id = ? AND equipe_id = ? AND cancelado_em IS NULL',
            [$agora, $agora, $id, $equipeId]
        ) > 0;
    }

    /** Treinamentos da equipe e os gerais com data entre as duas datas e horas (inclusive), para a agenda. @return array<int, array<string, mixed>> */
    public function entre(int $equipeId, string $de, string $ate): array
    {
        return $this->banco->consultar(
            'SELECT * FROM treinamentos WHERE (equipe_id = ? OR equipe_id IS NULL) AND data_hora BETWEEN ? AND ? ORDER BY data_hora, id',
            [$equipeId, $de, $ate]
        );
    }

    /** @return array<int, int> ids dos vendedores presentes */
    public function presentes(int $treinamentoId): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT vendedor_id FROM treinamento_presencas WHERE treinamento_id = ?', [$treinamentoId]) as $r) {
            $saida[] = (int) $r['vendedor_id'];
        }
        return $saida;
    }
}
