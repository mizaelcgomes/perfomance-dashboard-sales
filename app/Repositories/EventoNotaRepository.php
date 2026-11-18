<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Notas que o líder acrescenta a um evento da agenda (reunião ou treinamento). Só inserção e leitura: não há edição nem exclusão. */
final class EventoNotaRepository
{
    public function __construct(private Database $banco)
    {
    }

    public function inserir(string $tipo, int $eventoId, int $equipeId, string $texto, int $criadoPor, string $agora): int
    {
        $this->banco->executar(
            'INSERT INTO evento_notas (tipo, evento_id, equipe_id, texto, criado_por, criado_em) VALUES (?, ?, ?, ?, ?, ?)',
            [$tipo, $eventoId, $equipeId, $texto, $criadoPor, $agora]
        );
        return (int) $this->banco->idInserido();
    }

    /**
     * As notas de vários eventos do mesmo tipo, do mais novo para o mais antigo, só as da equipe (um treinamento geral pode ter notas de
     * várias equipes: cada líder vê as da própria).
     * @param array<int, int> $eventoIds
     * @return array<int, array<int, array<string, mixed>>> evento_id => [nota, ...]
     */
    public function listarDe(string $tipo, array $eventoIds, int $equipeId): array
    {
        $eventoIds = array_values(array_unique(array_map('intval', $eventoIds)));
        if ($eventoIds === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($eventoIds), '?'));
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT * FROM evento_notas WHERE tipo = ? AND equipe_id = ? AND evento_id IN ($marcas) ORDER BY criado_em DESC, id DESC",
            array_merge([$tipo, $equipeId], $eventoIds)
        ) as $r) {
            $saida[(int) $r['evento_id']][] = $r;
        }
        return $saida;
    }
}
