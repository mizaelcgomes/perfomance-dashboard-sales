<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Leituras da "atividade de liderança" (seção do admin). Atividade = registro CRIADO pelo líder (a edição não conta) dentro da equipe dele.
 * Todo método pede a equipe E o líder: conta só o que o líder da equipe criou nela. Só leitura. Os limites de período são datas e horas
 * (AAAA-MM-DD HH:MM:SS), os dois inclusivos.
 */
final class AtividadeLiderRepository
{
    public function __construct(private Database $banco)
    {
    }

    /**
     * Reuniões do líder: marcadas (criadas no período), realizadas (horário realizado no período) e canceladas (canceladas no período).
     * @return array{marcadas: int, realizadas: int, canceladas: int}
     */
    public function reunioes(int $equipeId, int $liderId, string $de, string $ate): array
    {
        $conta = fn(string $filtro, array $extra): int => (int) $this->banco->valor(
            "SELECT COUNT(*) FROM reunioes WHERE equipe_id = ? AND criado_por = ? AND $filtro",
            array_merge([$equipeId, $liderId], $extra)
        );
        return [
            'marcadas' => $conta('criado_em BETWEEN ? AND ?', [$de, $ate]),
            'realizadas' => $conta("status = 'realizada' AND data_hora_realizada BETWEEN ? AND ?", [$de, $ate]),
            'canceladas' => $conta("status = 'cancelada' AND atualizado_em BETWEEN ? AND ?", [$de, $ate]),
        ];
    }

    /** Feedbacks, cobranças ou revisões criados pelo líder no período. @param 'feedbacks'|'cobrancas_cadastro'|'revisoes_atendimento' $tabela */
    public function registrosDoLider(string $tabela, int $equipeId, int $liderId, string $de, string $ate): int
    {
        if (!in_array($tabela, ['feedbacks', 'cobrancas_cadastro', 'revisoes_atendimento'], true)) {
            return 0;
        }
        return (int) $this->banco->valor(
            "SELECT COUNT(*) FROM $tabela WHERE equipe_id = ? AND lider_vendedor_id = ? AND criado_em BETWEEN ? AND ?",
            [$equipeId, $liderId, $de, $ate]
        );
    }

    /**
     * Treinamentos da equipe criados pelo líder cuja data cai no período, e as presenças dos closers DA EQUIPE neles.
     * @return array{dados: int, presencas: int}
     */
    public function treinamentos(int $equipeId, int $liderId, string $de, string $ate): array
    {
        $dados = (int) $this->banco->valor(
            'SELECT COUNT(*) FROM treinamentos WHERE equipe_id = ? AND criado_por = ? AND data_hora BETWEEN ? AND ?',
            [$equipeId, $liderId, $de, $ate]
        );
        $presencas = (int) $this->banco->valor(
            'SELECT COUNT(*) FROM treinamento_presencas p
               JOIN treinamentos t ON t.id = p.treinamento_id
              WHERE t.equipe_id = ? AND t.criado_por = ? AND t.data_hora BETWEEN ? AND ?
                AND p.vendedor_id IN (SELECT id FROM vendedores WHERE equipe_id = ?)',
            [$equipeId, $liderId, $de, $ate, $equipeId]
        );
        return ['dados' => $dados, 'presencas' => $presencas];
    }

    /** Data e hora do último registro que o líder criou na equipe (em qualquer época), ou null. */
    public function ultimaAtividade(int $equipeId, int $liderId): ?string
    {
        $v = $this->banco->valor(
            'SELECT MAX(q) FROM (
                SELECT MAX(criado_em) AS q FROM reunioes WHERE equipe_id = ? AND criado_por = ?
                UNION ALL SELECT MAX(criado_em) FROM feedbacks WHERE equipe_id = ? AND lider_vendedor_id = ?
                UNION ALL SELECT MAX(criado_em) FROM cobrancas_cadastro WHERE equipe_id = ? AND lider_vendedor_id = ?
                UNION ALL SELECT MAX(criado_em) FROM revisoes_atendimento WHERE equipe_id = ? AND lider_vendedor_id = ?
                UNION ALL SELECT MAX(criado_em) FROM treinamentos WHERE equipe_id = ? AND criado_por = ?
             ) t',
            [$equipeId, $liderId, $equipeId, $liderId, $equipeId, $liderId, $equipeId, $liderId, $equipeId, $liderId]
        );
        return $v === null || $v === false ? null : (string) $v;
    }

    /**
     * Os últimos registros que o líder criou na equipe, de todos os tipos, do mais novo para o mais antigo.
     * Cada linha: tipo, id, quando (criação), closer_vendedor_id (ou null), descricao e status (ou null).
     * @return array<int, array<string, mixed>>
     */
    public function ultimos(int $equipeId, int $liderId, int $limite): array
    {
        $limite = max(1, min(100, $limite));
        return $this->banco->consultar(
            "SELECT * FROM (
                SELECT 'reuniao' AS tipo, id, criado_em AS quando, NULL AS closer_vendedor_id, titulo AS descricao, status FROM reunioes WHERE equipe_id = ? AND criado_por = ?
                UNION ALL SELECT 'feedback', id, criado_em, closer_vendedor_id, assunto, NULL FROM feedbacks WHERE equipe_id = ? AND lider_vendedor_id = ?
                UNION ALL SELECT 'cobranca', id, criado_em, closer_vendedor_id, COALESCE(observacao, 'Cobrança de cadastramento'), NULL FROM cobrancas_cadastro WHERE equipe_id = ? AND lider_vendedor_id = ?
                UNION ALL SELECT 'revisao', id, criado_em, closer_vendedor_id, COALESCE(observacao, 'Revisão de atendimento'), NULL FROM revisoes_atendimento WHERE equipe_id = ? AND lider_vendedor_id = ?
                UNION ALL SELECT 'treinamento', id, criado_em, NULL, assunto, NULL FROM treinamentos WHERE equipe_id = ? AND criado_por = ?
             ) t ORDER BY quando DESC, id DESC LIMIT $limite",
            [$equipeId, $liderId, $equipeId, $liderId, $equipeId, $liderId, $equipeId, $liderId, $equipeId, $liderId]
        );
    }
}
