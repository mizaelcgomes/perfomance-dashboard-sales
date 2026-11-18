<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Reuniões da liderança. Todo método exige a equipe: nenhuma consulta lê reunião de outra equipe. */
final class ReuniaoRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $equipeId): array
    {
        return $this->banco->consultar(
            'SELECT * FROM reunioes WHERE equipe_id = ? ORDER BY data_hora_marcada DESC, id DESC',
            [$equipeId]
        );
    }

    /** @return array<string, mixed>|null */
    public function buscar(int $id, int $equipeId): ?array
    {
        return $this->banco->linha('SELECT * FROM reunioes WHERE id = ? AND equipe_id = ?', [$id, $equipeId]);
    }

    public function inserir(int $equipeId, string $titulo, string $dataHoraMarcada, int $criadoPor, string $agora): int
    {
        $this->banco->executar(
            "INSERT INTO reunioes (equipe_id, titulo, data_hora_marcada, status, criado_por, criado_em) VALUES (?, ?, ?, 'agendada', ?, ?)",
            [$equipeId, $titulo, $dataHoraMarcada, $criadoPor, $agora]
        );
        return (int) $this->banco->idInserido();
    }

    /** Edita título e horário marcado. Devolve se achou a reunião NA EQUIPE. */
    public function atualizar(int $id, int $equipeId, string $titulo, string $dataHoraMarcada, string $agora): bool
    {
        $this->banco->executar(
            'UPDATE reunioes SET titulo = ?, data_hora_marcada = ?, atualizado_em = ? WHERE id = ? AND equipe_id = ?',
            [$titulo, $dataHoraMarcada, $agora, $id, $equipeId]
        );
        return $this->buscar($id, $equipeId) !== null;
    }

    /** Registra que a reunião foi realizada, e quando. */
    public function realizar(int $id, int $equipeId, string $dataHoraRealizada, string $agora): void
    {
        $this->banco->executar(
            "UPDATE reunioes SET status = 'realizada', data_hora_realizada = ?, atualizado_em = ? WHERE id = ? AND equipe_id = ?",
            [$dataHoraRealizada, $agora, $id, $equipeId]
        );
    }

    /** Corrige o horário em que a reunião foi realizada. */
    public function corrigirRealizada(int $id, int $equipeId, string $dataHoraRealizada, string $agora): void
    {
        $this->banco->executar(
            'UPDATE reunioes SET data_hora_realizada = ?, atualizado_em = ? WHERE id = ? AND equipe_id = ?',
            [$dataHoraRealizada, $agora, $id, $equipeId]
        );
    }

    public function cancelar(int $id, int $equipeId, string $agora): void
    {
        $this->banco->executar(
            "UPDATE reunioes SET status = 'cancelada', data_hora_realizada = NULL, atualizado_em = ? WHERE id = ? AND equipe_id = ?",
            [$agora, $id, $equipeId]
        );
    }

    /** Reuniões da equipe com horário MARCADO entre as duas datas e horas (inclusive), para a agenda. @return array<int, array<string, mixed>> */
    public function entre(int $equipeId, string $de, string $ate): array
    {
        return $this->banco->consultar(
            'SELECT * FROM reunioes WHERE equipe_id = ? AND data_hora_marcada BETWEEN ? AND ? ORDER BY data_hora_marcada, id',
            [$equipeId, $de, $ate]
        );
    }

    /** Reuniões ainda agendadas da equipe, para as pendências (atrasadas e próximas). @return array<int, array<string, mixed>> */
    public function agendadas(int $equipeId): array
    {
        return $this->banco->consultar("SELECT * FROM reunioes WHERE equipe_id = ? AND status = 'agendada' ORDER BY data_hora_marcada, id", [$equipeId]);
    }

    /** @return array<int, int> ids dos vendedores que participam da reunião */
    public function participantes(int $reuniaoId): array
    {
        return $this->participantesDe([$reuniaoId])[$reuniaoId] ?? [];
    }

    /**
     * Participantes de várias reuniões de uma vez.
     * @param array<int, int> $ids
     * @return array<int, array<int, int>> reuniao_id => [vendedor_id, ...]
     */
    public function participantesDe(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $saida = [];
        foreach ($this->banco->consultar("SELECT reuniao_id, vendedor_id FROM reuniao_participantes WHERE reuniao_id IN ($marcas) ORDER BY vendedor_id", $ids) as $r) {
            $saida[(int) $r['reuniao_id']][] = (int) $r['vendedor_id'];
        }
        return $saida;
    }

    /** Troca a lista de participantes. Os ids já passaram pela conferência de que são da equipe. @param array<int, int> $vendedorIds */
    public function definirParticipantes(int $reuniaoId, array $vendedorIds): void
    {
        $this->banco->executar('DELETE FROM reuniao_participantes WHERE reuniao_id = ?', [$reuniaoId]);
        foreach (array_values(array_unique($vendedorIds)) as $vendedorId) {
            $this->banco->executar('INSERT IGNORE INTO reuniao_participantes (reuniao_id, vendedor_id) VALUES (?, ?)', [$reuniaoId, $vendedorId]);
        }
    }
}
