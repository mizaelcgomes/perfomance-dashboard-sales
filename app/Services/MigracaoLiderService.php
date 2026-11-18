<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Cria as tabelas do módulo do líder (fase L1). É IDEMPOTENTE: CREATE TABLE IF NOT EXISTS, sem nenhum INSERT de dados.
 * Rodar duas vezes não duplica nada. Nenhuma tabela que já existe é alterada. Não usa chave estrangeira: a integridade entre
 * equipe, closer e líder é conferida pelos Services, como nas tabelas atuais do sistema.
 */
final class MigracaoLiderService
{
    /** @var array<string, string> nome da tabela => CREATE TABLE */
    public const TABELAS = [
        'reunioes' => "CREATE TABLE IF NOT EXISTS reunioes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            equipe_id INT NOT NULL,
            titulo VARCHAR(120) NOT NULL,
            data_hora_marcada DATETIME NOT NULL,
            data_hora_realizada DATETIME NULL,
            status ENUM('agendada','realizada','cancelada') NOT NULL DEFAULT 'agendada',
            criado_por INT NOT NULL,
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NULL,
            INDEX idx_reunioes_equipe (equipe_id, data_hora_marcada)
        ) DEFAULT CHARSET=utf8mb4",
        'feedbacks' => "CREATE TABLE IF NOT EXISTS feedbacks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            equipe_id INT NOT NULL,
            closer_vendedor_id INT NOT NULL,
            lider_vendedor_id INT NOT NULL,
            data DATE NOT NULL,
            assunto VARCHAR(120) NOT NULL,
            texto TEXT NOT NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_feedbacks_equipe (equipe_id, data),
            INDEX idx_feedbacks_closer (closer_vendedor_id, data)
        ) DEFAULT CHARSET=utf8mb4",
        'cobrancas_cadastro' => "CREATE TABLE IF NOT EXISTS cobrancas_cadastro (
            id INT AUTO_INCREMENT PRIMARY KEY,
            equipe_id INT NOT NULL,
            closer_vendedor_id INT NOT NULL,
            lider_vendedor_id INT NOT NULL,
            data DATE NOT NULL,
            observacao VARCHAR(255) NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_cobrancas_equipe (equipe_id, data),
            INDEX idx_cobrancas_closer (closer_vendedor_id, data)
        ) DEFAULT CHARSET=utf8mb4",
        'revisoes_atendimento' => "CREATE TABLE IF NOT EXISTS revisoes_atendimento (
            id INT AUTO_INCREMENT PRIMARY KEY,
            equipe_id INT NOT NULL,
            closer_vendedor_id INT NOT NULL,
            telefone_cliente VARCHAR(20) NOT NULL,
            observacao VARCHAR(255) NULL,
            lider_vendedor_id INT NOT NULL,
            data DATE NOT NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_revisoes_equipe (equipe_id, data)
        ) DEFAULT CHARSET=utf8mb4",
        'treinamentos' => "CREATE TABLE IF NOT EXISTS treinamentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            equipe_id INT NULL,
            data_hora DATETIME NOT NULL,
            assunto VARCHAR(120) NOT NULL,
            criado_por INT NOT NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_treinamentos_equipe (equipe_id, data_hora)
        ) DEFAULT CHARSET=utf8mb4",
        'treinamento_presencas' => "CREATE TABLE IF NOT EXISTS treinamento_presencas (
            treinamento_id INT NOT NULL,
            vendedor_id INT NOT NULL,
            PRIMARY KEY (treinamento_id, vendedor_id)
        ) DEFAULT CHARSET=utf8mb4",
        // ── fase L4 (portal do líder) ──
        // quem participou de cada reunião (a presença de treinamento já existe em treinamento_presencas)
        'reuniao_participantes' => "CREATE TABLE IF NOT EXISTS reuniao_participantes (
            reuniao_id INT NOT NULL,
            vendedor_id INT NOT NULL,
            PRIMARY KEY (reuniao_id, vendedor_id)
        ) DEFAULT CHARSET=utf8mb4",
        // notas que o líder acrescenta a um evento da agenda (reunião ou treinamento)
        'evento_notas' => "CREATE TABLE IF NOT EXISTS evento_notas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo ENUM('reuniao','treinamento') NOT NULL,
            evento_id INT NOT NULL,
            equipe_id INT NOT NULL,
            texto VARCHAR(500) NOT NULL,
            criado_por INT NOT NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_notas_evento (tipo, evento_id)
        ) DEFAULT CHARSET=utf8mb4",
        // respostas do closer a um feedback, cobrança ou revisão que o líder registrou para ele (Minhas Interações do portal do closer)
        'interacao_respostas' => "CREATE TABLE IF NOT EXISTS interacao_respostas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo ENUM('feedback','cobranca','revisao') NOT NULL,
            registro_id INT NOT NULL,
            closer_vendedor_id INT NOT NULL,
            texto VARCHAR(500) NOT NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_respostas_registro (tipo, registro_id),
            INDEX idx_respostas_closer (closer_vendedor_id)
        ) DEFAULT CHARSET=utf8mb4",
        // preferências de alerta de cada líder (Configurações do portal do líder)
        'lider_preferencias' => "CREATE TABLE IF NOT EXISTS lider_preferencias (
            vendedor_id INT NOT NULL PRIMARY KEY,
            limite_dias_sem_venda TINYINT UNSIGNED NOT NULL DEFAULT 3,
            dias_sem_feedback TINYINT UNSIGNED NOT NULL DEFAULT 14,
            alertas VARCHAR(255) NULL,
            atualizado_em DATETIME NULL
        ) DEFAULT CHARSET=utf8mb4",
    ];

    /**
     * Colunas acrescentadas na fase L3 às tabelas da L1 (atualizado_em: edição; visto_em: quando o closer viu o feedback ou a cobrança,
     * base do aviso "novo" no portal). Aplicadas por ALTER TABLE só se a coluna ainda não existir, então servem tanto para a base nova
     * quanto para quem já rodou a migração da L1.
     * @var array<string, array<string, string>> tabela => [coluna => definição]
     */
    public const COLUNAS = [
        'feedbacks' => ['atualizado_em' => 'DATETIME NULL', 'visto_em' => 'DATETIME NULL'],
        'cobrancas_cadastro' => ['atualizado_em' => 'DATETIME NULL', 'visto_em' => 'DATETIME NULL'],
        'revisoes_atendimento' => ['atualizado_em' => 'DATETIME NULL', 'visto_em' => 'DATETIME NULL'],
        'treinamentos' => ['atualizado_em' => 'DATETIME NULL', 'cancelado_em' => 'DATETIME NULL'],
    ];

    public function __construct(private Database $banco)
    {
    }

    /**
     * Cria o que falta e devolve o relatório: quais tabelas já existiam, quais foram criadas agora e quais colunas foram acrescentadas.
     * @return array{ja_existiam: array<int, string>, criadas: array<int, string>, colunas_adicionadas: array<int, string>}
     */
    public function executar(): array
    {
        $ja = [];
        $criadas = [];
        foreach (self::TABELAS as $nome => $sql) {
            if ($this->existe($nome)) {
                $ja[] = $nome;
                continue;
            }
            $this->banco->executar($sql);
            $criadas[] = $nome;
        }
        $colunas = [];
        foreach (self::COLUNAS as $tabela => $lista) {
            foreach ($lista as $coluna => $definicao) {
                if (!$this->temColuna($tabela, $coluna)) {
                    $this->banco->executar("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
                    $colunas[] = "$tabela.$coluna";
                }
            }
        }
        return ['ja_existiam' => $ja, 'criadas' => $criadas, 'colunas_adicionadas' => $colunas];
    }

    private function temColuna(string $tabela, string $coluna): bool
    {
        return (int) $this->banco->valor(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$tabela, $coluna]
        ) > 0;
    }

    private function existe(string $tabela): bool
    {
        return (int) $this->banco->valor(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$tabela]
        ) > 0;
    }
}
