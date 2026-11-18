<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * "Minhas Interações" do portal do closer: o que o líder registrou PARA o closer (feedbacks, cobranças de cadastramento e revisões de atendimento)
 * e o que é da EQUIPE dele (reuniões e treinamentos), mais as respostas do closer. Todo método recebe o closer (ou a equipe) da CONTA logada, nunca um
 * id vindo da requisição: ninguém lê nem responde o que é de outro closer. Só leitura, exceto "visto" e as respostas.
 */
final class InteracaoCloserRepository
{
    /** Tabelas dos registros do closer e o que cada uma tem (nome fixo do código, nunca da requisição). */
    private const TABELAS = ['feedback' => 'feedbacks', 'cobranca' => 'cobrancas_cadastro', 'revisao' => 'revisoes_atendimento'];

    public function __construct(private Database $banco)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function feedbacks(int $closerId): array
    {
        return $this->banco->consultar('SELECT * FROM feedbacks WHERE closer_vendedor_id = ? ORDER BY data DESC, id DESC LIMIT 100', [$closerId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function cobrancas(int $closerId): array
    {
        return $this->banco->consultar('SELECT * FROM cobrancas_cadastro WHERE closer_vendedor_id = ? ORDER BY data DESC, id DESC LIMIT 100', [$closerId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function revisoes(int $closerId): array
    {
        return $this->banco->consultar('SELECT * FROM revisoes_atendimento WHERE closer_vendedor_id = ? ORDER BY data DESC, id DESC LIMIT 100', [$closerId]);
    }

    /** Reuniões da equipe, a mais recente primeiro. @return array<int, array<string, mixed>> */
    public function reunioes(int $equipeId): array
    {
        return $this->banco->consultar('SELECT * FROM reunioes WHERE equipe_id = ? ORDER BY data_hora_marcada DESC, id DESC LIMIT 100', [$equipeId]);
    }

    /** Treinamentos da equipe (e os de todas as equipes), com se o closer esteve presente. @return array<int, array<string, mixed>> */
    public function treinamentos(int $equipeId, int $closerId): array
    {
        return $this->banco->consultar(
            'SELECT t.*, (SELECT COUNT(*) FROM treinamento_presencas p WHERE p.treinamento_id = t.id AND p.vendedor_id = ?) AS presente
               FROM treinamentos t WHERE t.equipe_id = ? OR t.equipe_id IS NULL ORDER BY t.data_hora DESC, t.id DESC LIMIT 100',
            [$closerId, $equipeId]
        );
    }

    /** Quantos registros do closer ainda não foram vistos, por tipo. @return array{feedback: int, cobranca: int, revisao: int} */
    public function naoVistos(int $closerId): array
    {
        $saida = [];
        foreach (self::TABELAS as $tipo => $tabela) {
            $saida[$tipo] = (int) $this->banco->valor("SELECT COUNT(*) FROM $tabela WHERE closer_vendedor_id = ? AND visto_em IS NULL", [$closerId]);
        }
        return $saida;
    }

    /** O registro existe e é DESTE closer? */
    public function pertence(string $tipo, int $id, int $closerId): bool
    {
        $tabela = self::TABELAS[$tipo] ?? null;
        return $tabela !== null && $this->banco->linha("SELECT 1 FROM $tabela WHERE id = ? AND closer_vendedor_id = ?", [$id, $closerId]) !== null;
    }

    /** Marca como visto (só se ainda não foi, e só se é do closer). Devolve quantos mudaram (0 ou 1). */
    public function marcarVisto(string $tipo, int $id, int $closerId, string $agora): int
    {
        $tabela = self::TABELAS[$tipo] ?? null;
        return $tabela === null ? 0 : $this->banco->executar("UPDATE $tabela SET visto_em = ? WHERE id = ? AND closer_vendedor_id = ? AND visto_em IS NULL", [$agora, $id, $closerId]);
    }

    /** As respostas do closer a esses registros: [tipo|id => lista (mais antiga primeiro)]. @param array<int, int> $ids @return array<int, array<int, array<string, mixed>>> */
    public function respostas(string $tipo, array $ids, int $closerId): array
    {
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $saida = [];
        foreach ($this->banco->consultar(
            "SELECT id, registro_id, texto, criado_em FROM interacao_respostas WHERE tipo = ? AND closer_vendedor_id = ? AND registro_id IN ($marcas) ORDER BY id",
            array_merge([$tipo, $closerId], array_map('intval', $ids))
        ) as $r) {
            $saida[(int) $r['registro_id']][] = ['id' => (int) $r['id'], 'texto' => (string) $r['texto'], 'criado_em' => (string) $r['criado_em']];
        }
        return $saida;
    }

    public function inserirResposta(string $tipo, int $registroId, int $closerId, string $texto, string $agora): int
    {
        $this->banco->executar(
            'INSERT INTO interacao_respostas (tipo, registro_id, closer_vendedor_id, texto, criado_em) VALUES (?, ?, ?, ?, ?)',
            [$tipo, $registroId, $closerId, $texto, $agora]
        );
        return (int) $this->banco->idInserido();
    }

    /** A equipe e o nome do líder do closer (para a página Configurações e para "de seu líder"). @return array<string, mixed>|null */
    public function equipeDoCloser(int $closerId): ?array
    {
        return $this->banco->linha(
            'SELECT v.equipe_id, e.nome AS equipe_nome, e.cor AS equipe_cor, l.nome AS lider_nome
               FROM vendedores v
               LEFT JOIN equipes e ON e.id = v.equipe_id
               LEFT JOIN vendedores l ON l.id = e.lider_id AND l.deleted_at IS NULL
              WHERE v.id = ?',
            [$closerId]
        );
    }
}
