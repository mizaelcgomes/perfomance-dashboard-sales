<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Consultas agregadas do portal do líder (fase L4): métricas por closer dos últimos dias, pendências, linha do tempo de um closer, evolução
 * diária e busca. Só leitura, e TODA consulta pede a equipe (ou um closer que o serviço já provou ser da equipe): nada lê dado de outra equipe.
 */
final class LiderPortalRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** Data (AAAA-MM-DD) do feedback mais recente de cada closer da equipe. @return array<int, string> closer_vendedor_id => data */
    public function ultimoFeedbackPorCloser(int $equipeId): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT closer_vendedor_id, MAX(data) AS d FROM feedbacks WHERE equipe_id = ? GROUP BY closer_vendedor_id', [$equipeId]) as $r) {
            $saida[(int) $r['closer_vendedor_id']] = (string) $r['d'];
        }
        return $saida;
    }

    /**
     * O que cada closer recebeu ou fez desde uma data: feedbacks, cobranças e revisões recebidos, reuniões realizadas em que participou e
     * treinamentos já realizados em que esteve presente.
     * @return array<int, array{feedbacks: int, cobrancas: int, revisoes: int, reunioes: int, treinamentos: int}> por vendedor_id
     */
    public function contagens(int $equipeId, string $desde, string $agora): array
    {
        $saida = [];
        $soma = function (string $chave, array $linhas) use (&$saida): void {
            foreach ($linhas as $r) {
                $id = (int) $r['v'];
                $saida[$id] ??= ['feedbacks' => 0, 'cobrancas' => 0, 'revisoes' => 0, 'reunioes' => 0, 'treinamentos' => 0];
                $saida[$id][$chave] = (int) $r['n'];
            }
        };
        $soma('feedbacks', $this->banco->consultar('SELECT closer_vendedor_id AS v, COUNT(*) AS n FROM feedbacks WHERE equipe_id = ? AND data >= ? GROUP BY closer_vendedor_id', [$equipeId, $desde]));
        $soma('cobrancas', $this->banco->consultar('SELECT closer_vendedor_id AS v, COUNT(*) AS n FROM cobrancas_cadastro WHERE equipe_id = ? AND data >= ? GROUP BY closer_vendedor_id', [$equipeId, $desde]));
        $soma('revisoes', $this->banco->consultar('SELECT closer_vendedor_id AS v, COUNT(*) AS n FROM revisoes_atendimento WHERE equipe_id = ? AND data >= ? GROUP BY closer_vendedor_id', [$equipeId, $desde]));
        $soma('reunioes', $this->banco->consultar(
            "SELECT p.vendedor_id AS v, COUNT(*) AS n FROM reuniao_participantes p JOIN reunioes r ON r.id = p.reuniao_id
              WHERE r.equipe_id = ? AND r.status = 'realizada' AND r.data_hora_realizada >= ? GROUP BY p.vendedor_id",
            [$equipeId, $desde . ' 00:00:00']
        ));
        $soma('treinamentos', $this->banco->consultar(
            'SELECT p.vendedor_id AS v, COUNT(*) AS n FROM treinamento_presencas p JOIN treinamentos t ON t.id = p.treinamento_id
              WHERE (t.equipe_id = ? OR t.equipe_id IS NULL) AND t.cancelado_em IS NULL AND t.data_hora BETWEEN ? AND ? GROUP BY p.vendedor_id',
            [$equipeId, $desde . ' 00:00:00', $agora]
        ));
        return $saida;
    }

    /** Quantos treinamentos não cancelados (da equipe ou gerais) estão marcados entre as duas datas e horas. */
    public function treinamentosMarcados(int $equipeId, string $de, string $ate): int
    {
        return (int) $this->banco->valor(
            'SELECT COUNT(*) FROM treinamentos WHERE (equipe_id = ? OR equipe_id IS NULL) AND cancelado_em IS NULL AND data_hora BETWEEN ? AND ?',
            [$equipeId, $de, $ate]
        );
    }

    /** Treinamentos da equipe já passados (entre as datas) e sem nenhuma presença de closer da equipe registrada. @return array<int, array<string, mixed>> */
    public function treinamentosSemPresenca(int $equipeId, string $de, string $ate): array
    {
        return $this->banco->consultar(
            'SELECT t.* FROM treinamentos t
              WHERE t.equipe_id = ? AND t.cancelado_em IS NULL AND t.data_hora BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM treinamento_presencas p JOIN vendedores v ON v.id = p.vendedor_id WHERE p.treinamento_id = t.id AND v.equipe_id = ?)
              ORDER BY t.data_hora DESC',
            [$equipeId, $de, $ate, $equipeId]
        );
    }

    /**
     * A linha do tempo de um closer da equipe, do mais novo para o mais antigo: feedbacks, cobranças e revisões recebidos, treinamentos em que
     * esteve presente e reuniões de que participou.
     * @return array<int, array<string, mixed>> tipo, id, quando, titulo, texto, extra
     */
    public function linhaDoTempo(int $equipeId, int $closerId, string $desde, string $agora, int $limite): array
    {
        $limite = max(1, min(200, $limite));
        return $this->banco->consultar(
            "SELECT * FROM (
                SELECT 'feedback' AS tipo, id, criado_em AS quando, assunto AS titulo, texto, NULL AS extra FROM feedbacks WHERE equipe_id = ? AND closer_vendedor_id = ?
                UNION ALL SELECT 'cobranca', id, criado_em, 'Cobrança de cadastramento', observacao, NULL FROM cobrancas_cadastro WHERE equipe_id = ? AND closer_vendedor_id = ?
                UNION ALL SELECT 'revisao', id, criado_em, 'Revisão de atendimento', observacao, telefone_cliente FROM revisoes_atendimento WHERE equipe_id = ? AND closer_vendedor_id = ?
                UNION ALL SELECT 'treinamento', t.id, t.data_hora, t.assunto, NULL, NULL FROM treinamento_presencas p JOIN treinamentos t ON t.id = p.treinamento_id
                           WHERE p.vendedor_id = ? AND (t.equipe_id = ? OR t.equipe_id IS NULL) AND t.cancelado_em IS NULL AND t.data_hora <= ?
                UNION ALL SELECT 'reuniao', r.id, COALESCE(r.data_hora_realizada, r.data_hora_marcada), r.titulo, NULL, r.status FROM reuniao_participantes p JOIN reunioes r ON r.id = p.reuniao_id
                           WHERE p.vendedor_id = ? AND r.equipe_id = ? AND r.status = 'realizada'
             ) t WHERE quando >= ? ORDER BY quando DESC, id DESC LIMIT $limite",
            [$equipeId, $closerId, $equipeId, $closerId, $equipeId, $closerId, $closerId, $equipeId, $agora, $closerId, $equipeId, $desde . ' 00:00:00']
        );
    }

    /**
     * Contratos e ações de um closer por dia, entre duas datas (inclusive). O closer já foi conferido como da equipe pelo serviço.
     * @return array{contratos: array<string, int>, acoes: array<string, int>} data => quantidade
     */
    public function evolucaoDiaria(int $closerId, string $de, string $ate): array
    {
        $contratos = [];
        foreach ($this->banco->consultar('SELECT data_registro AS d, SUM(quantidade) AS n FROM contratos_historico WHERE vendedor_id = ? AND data_registro BETWEEN ? AND ? AND ' . VendedorRepository::sqlSemOcultos('vendedor_id') . ' GROUP BY data_registro', [$closerId, $de, $ate]) as $r) {
            $contratos[(string) $r['d']] = (int) $r['n'];
        }
        $acoes = [];
        foreach ($this->banco->consultar('SELECT DATE(registrado_em) AS d, COUNT(*) AS n FROM acoes_closer WHERE vendedor_id = ? AND registrado_em BETWEEN ? AND ? GROUP BY DATE(registrado_em)', [$closerId, $de . ' 00:00:00', $ate . ' 23:59:59']) as $r) {
            $acoes[(string) $r['d']] = (int) $r['n'];
        }
        return ['contratos' => $contratos, 'acoes' => $acoes];
    }

    /**
     * Busca registros da equipe por texto. $like já vem com os curingas escapados (%texto%); $digitos é o texto só com números (para telefone).
     * @return array<int, array<string, mixed>> tipo, id, titulo, detalhe, quando, closer_vendedor_id
     */
    public function buscarRegistros(int $equipeId, string $like, string $digitos, int $porTipo): array
    {
        $porTipo = max(1, min(10, $porTipo));
        $tel = strlen($digitos) >= 3 ? ' OR telefone_cliente LIKE ?' : '';
        $parRev = strlen($digitos) >= 3 ? [$equipeId, $like, '%' . $digitos . '%'] : [$equipeId, $like];
        return $this->banco->consultar(
            "(SELECT 'reuniao' AS tipo, id, titulo, status AS detalhe, data_hora_marcada AS quando, NULL AS closer_vendedor_id FROM reunioes WHERE equipe_id = ? AND titulo LIKE ? ORDER BY data_hora_marcada DESC LIMIT $porTipo)
             UNION ALL (SELECT 'feedback', id, assunto, texto, criado_em, closer_vendedor_id FROM feedbacks WHERE equipe_id = ? AND (assunto LIKE ? OR texto LIKE ?) ORDER BY criado_em DESC LIMIT $porTipo)
             UNION ALL (SELECT 'cobranca', id, 'Cobrança de cadastramento', observacao, criado_em, closer_vendedor_id FROM cobrancas_cadastro WHERE equipe_id = ? AND observacao LIKE ? ORDER BY criado_em DESC LIMIT $porTipo)
             UNION ALL (SELECT 'revisao', id, 'Revisão de atendimento', observacao, criado_em, closer_vendedor_id FROM revisoes_atendimento WHERE equipe_id = ? AND (observacao LIKE ?$tel) ORDER BY criado_em DESC LIMIT $porTipo)
             UNION ALL (SELECT 'treinamento', id, assunto, NULL, data_hora, NULL FROM treinamentos WHERE (equipe_id = ? OR equipe_id IS NULL) AND assunto LIKE ? ORDER BY data_hora DESC LIMIT $porTipo)",
            array_merge([$equipeId, $like, $equipeId, $like, $like, $equipeId, $like], $parRev, [$equipeId, $like])
        );
    }
}
