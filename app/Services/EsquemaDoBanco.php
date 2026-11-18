<?php
declare(strict_types=1);

namespace App\Services;

use DateTime;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * Cria e atualiza as tabelas e colunas do sistema (idempotente: pode rodar quantas vezes quiser). Era o bloco "Schema auto-create / migrate" do
 * config.php, que rodava a cada requisição; desde a fase 9 NÃO roda mais sozinho (o servidor já está atualizado). Para um banco novo ou de teste:
 * php tests/nucleo/preparar-banco.php. Tabelas do módulo do líder: MigracaoLiderService.
 */
final class EsquemaDoBanco
{
    public static function garantir(PDO $pdo): void
    {
    // Settings
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key   VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");
    $pdo->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('week_days', '7')");
    $pdo->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('tiebreaker_order', '[\"aux_acidente\",\"bpc_loas\",\"sal_maternidade\",\"aux_doenca\",\"outros\"]')");
    $pdo->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('investimento_mes', '0')");
    $pdo->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('meta_cac', '85')");

    // Historico mensal de midia. O investimento_mes acima continua existindo e
    // espelha o mes corrente — e dele que o painel/TV tira o CAC ao vivo. Esta
    // tabela e o que permite serie historica e grafico de evolucao.
    $pdo->exec("CREATE TABLE IF NOT EXISTS investimento_mensal (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        ano          INT NOT NULL,
        mes          TINYINT NOT NULL,
        investimento DECIMAL(12,2) NOT NULL DEFAULT 0,
        alcance      INT NOT NULL DEFAULT 0,
        impressoes   INT NOT NULL DEFAULT 0,
        cpm          DECIMAL(10,2) NOT NULL DEFAULT 0,
        frequencia   DECIMAL(6,2)  NOT NULL DEFAULT 0,
        obs          VARCHAR(255) NULL,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ano_mes (ano, mes)
    )");

    // Semeia o mes corrente com o valor avulso que ja existia, pra nao perder o
    // numero que o usuario tinha digitado no painel.
    try {
        $seedInv = (float)($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='investimento_mes'")->fetchColumn() ?: 0);
        if ($seedInv > 0) {
            $seedTz  = new DateTimeZone('America/Sao_Paulo');
            $seedNow = new DateTime('now', $seedTz);
            $pdo->prepare("INSERT IGNORE INTO investimento_mensal (ano, mes, investimento) VALUES (?,?,?)")
                ->execute([(int)$seedNow->format('Y'), (int)$seedNow->format('n'), $seedInv]);
        }
    } catch (PDOException $e) { error_log('seed investimento_mensal: '.$e->getMessage()); }

    // Teams
    $pdo->exec("CREATE TABLE IF NOT EXISTS equipes (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        nome         VARCHAR(100) NOT NULL UNIQUE,
        cor          VARCHAR(7)   NOT NULL DEFAULT '#d4af37',
        img_url      VARCHAR(255),
        meta_semanal INT NOT NULL DEFAULT 0,
        meta_mensal  INT NOT NULL DEFAULT 0,
        lider_id     INT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Additive: add meta columns if missing
    // lider_id aponta pra vendedores.id sem FK rigida: o vinculo e revalidado
    // na leitura (tem que ser closer ativo DA equipe), entao closer excluido ou
    // movido de equipe simplesmente deixa de ser lider, sem quebrar nada.
    foreach (['meta_semanal'=>'INT NOT NULL DEFAULT 0','meta_mensal'=>'INT NOT NULL DEFAULT 0','lider_id'=>'INT NULL','oculta_tv'=>'TINYINT(1) NOT NULL DEFAULT 0'] as $mc=>$mdef) {
        if (!$pdo->query("SHOW COLUMNS FROM equipes LIKE '$mc'")->fetch())
            $pdo->exec("ALTER TABLE equipes ADD COLUMN $mc $mdef");
    }

    // Propagandas
    $pdo->exec("CREATE TABLE IF NOT EXISTS propagandas (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        titulo        VARCHAR(100) NOT NULL DEFAULT '',
        conteudo_html TEXT NOT NULL,
        ativo         TINYINT(1) NOT NULL DEFAULT 1,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Custom month/week calendar configuration
    $pdo->exec("CREATE TABLE IF NOT EXISTS semanas_mes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        ano         SMALLINT NOT NULL,
        mes         TINYINT  NOT NULL,
        semana_num  TINYINT  NOT NULL,
        data_inicio DATE     NOT NULL,
        data_fim    DATE     NOT NULL,
        UNIQUE KEY uk_ams (ano, mes, semana_num)
    )");

    // Additive migrations for vendedores
    // ferias_inicio/ferias_fim: período de férias (suspended_at continua sendo o que tira o
    // closer do ranking; syncFerias() abaixo liga/desliga ele conforme as datas).
    // desligamento_motivo: preenchido ao arquivar um closer (demissao/transferencia/ferias_prolongadas).
    $vendCols = ['equipe_id'=>'INT NULL AFTER id', 'meta_mensal'=>'INT NOT NULL DEFAULT 0', 'meta_diaria'=>'INT NOT NULL DEFAULT 0', 'deleted_at'=>'DATETIME NULL DEFAULT NULL', 'suspended_at'=>'DATETIME NULL DEFAULT NULL', 'ferias_inicio'=>'DATE NULL DEFAULT NULL', 'ferias_fim'=>'DATE NULL DEFAULT NULL', 'desligamento_motivo'=>'VARCHAR(40) NULL DEFAULT NULL'];
    foreach ($vendCols as $vc => $vdef) {
        if (!$pdo->query("SHOW COLUMNS FROM vendedores LIKE '$vc'")->fetch())
            $pdo->exec("ALTER TABLE vendedores ADD COLUMN $vc $vdef");
    }

    // Per-period goals (closer, team, or whole commercial dept — week or month)
    // semana_num: 0 = meta mensal, 1+ = meta da semana N
    // tipo='geral' uses the fixed ref_id=0 and overrides the auto-summed company-wide goal
    $pdo->exec("CREATE TABLE IF NOT EXISTS metas_periodo (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        tipo        ENUM('closer','equipe','geral') NOT NULL,
        ref_id      INT NOT NULL,
        ano         SMALLINT NOT NULL,
        mes         TINYINT NOT NULL,
        semana_num  TINYINT NOT NULL DEFAULT 0 COMMENT '0 = meta mensal; 1+ = semana N',
        meta        INT NOT NULL DEFAULT 0,
        UNIQUE KEY uk_periodo (tipo, ref_id, ano, mes, semana_num)
    )");
    $mpTipoCol = $pdo->query("SHOW COLUMNS FROM metas_periodo LIKE 'tipo'")->fetch();
    if ($mpTipoCol && strpos($mpTipoCol['Type'], "'geral'") === false) {
        $pdo->exec("ALTER TABLE metas_periodo MODIFY COLUMN tipo ENUM('closer','equipe','geral') NOT NULL");
    }

    // Meta diaria por DIA especifico do mes, por closer — distinta do
    // vendedores.meta_diaria (um numero fixo usado como fallback/placeholder
    // aqui). Ex: ritmo crescente ao longo do mes, ou dias com meta reduzida.
    $pdo->exec("CREATE TABLE IF NOT EXISTS metas_diarias_periodo (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        closer_id INT NOT NULL,
        ano       SMALLINT NOT NULL,
        mes       TINYINT NOT NULL,
        dia       TINYINT NOT NULL COMMENT '1-31',
        meta      INT NOT NULL DEFAULT 0,
        UNIQUE KEY uk_dia (closer_id, ano, mes, dia)
    )");

    // Daily totals (week view, by day-index)
    $pdo->exec("CREATE TABLE IF NOT EXISTS contratos (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id INT NOT NULL,
        dia_semana  TINYINT NOT NULL,
        quantidade  INT NOT NULL DEFAULT 0,
        UNIQUE KEY uk_vd (vendedor_id, dia_semana)
    )");

    // Historical daily totals (by actual date)
    $pdo->exec("CREATE TABLE IF NOT EXISTS contratos_historico (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id   INT  NOT NULL,
        data_registro DATE NOT NULL,
        quantidade    INT  NOT NULL DEFAULT 0,
        UNIQUE KEY uk_vendor_date (vendedor_id, data_registro)
    )");

    // Per-benefit breakdown (by actual date)
    $pdo->exec("CREATE TABLE IF NOT EXISTS contratos_tipos (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id      INT          NOT NULL,
        data_registro    DATE         NOT NULL,
        tipo             VARCHAR(50)  NOT NULL,
        tipo_descricao   VARCHAR(100) NOT NULL DEFAULT '',
        quantidade       INT          NOT NULL DEFAULT 0,
        KEY idx_vd  (vendedor_id, data_registro),
        KEY idx_tipo (tipo)
    )");

    // Custom (admin-defined) fixed benefit types
    $pdo->exec("CREATE TABLE IF NOT EXISTS tipos_beneficio_custom (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        chave      VARCHAR(64)  NOT NULL UNIQUE,
        label      VARCHAR(100) NOT NULL,
        ativo      TINYINT(1)   NOT NULL DEFAULT 1,
        sort_order INT          NOT NULL DEFAULT 0
    )");
    foreach (['cor' => 'VARCHAR(9) NULL DEFAULT NULL', 'icone' => 'VARCHAR(24) NULL DEFAULT NULL'] as $tcCol => $tcDef) {
        if (!$pdo->query("SHOW COLUMNS FROM tipos_beneficio_custom LIKE '$tcCol'")->fetch()) $pdo->exec("ALTER TABLE tipos_beneficio_custom ADD COLUMN $tcCol $tcDef");
    }

    // Daily attendances per closer (for conversion rate)
    $pdo->exec("CREATE TABLE IF NOT EXISTS atendimentos (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id   INT  NOT NULL,
        data_registro DATE NOT NULL,
        quantidade    INT  NOT NULL DEFAULT 0,
        UNIQUE KEY uk_vendor_date (vendedor_id, data_registro)
    )");

    // Feed de vendas (timeline da aba Contratos): um registro por lançamento, com hora e autor.
    // Os totais continuam em contratos_tipos/contratos_historico (fonte única do ranking e do painel);
    // o feed é reconciliado com eles. origem: rapido (modal) | matriz (ajuste na matriz) | legado (sem hora).
    $pdo->exec("CREATE TABLE IF NOT EXISTS contratos_feed (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id    INT          NOT NULL,
        data_registro  DATE         NOT NULL,
        tipo           VARCHAR(50)  NOT NULL,
        tipo_descricao VARCHAR(100) NOT NULL DEFAULT '',
        quantidade     INT          NOT NULL,
        origem         VARCHAR(10)  NOT NULL DEFAULT 'rapido',
        criado_em      DATETIME     NOT NULL,
        KEY idx_data  (data_registro),
        KEY idx_chave (vendedor_id, data_registro, tipo)
    )");

    // Custom admin-triggered popup (single row, manually fired from the admin panel)
    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_popup (
        id           INT PRIMARY KEY DEFAULT 1,
        titulo       VARCHAR(150) NOT NULL DEFAULT '',
        foto1        VARCHAR(255),
        foto2        VARCHAR(255),
        triggered_at DATETIME NULL DEFAULT NULL
    )");
    $pdo->exec("INSERT IGNORE INTO custom_popup (id) VALUES (1)");

    // Goal ceremony (weekly podium honor roll, manually fired from the admin panel)
    $pdo->exec("CREATE TABLE IF NOT EXISTS goal_ceremony (
        id           INT PRIMARY KEY DEFAULT 1,
        payload      LONGTEXT,
        triggered_at DATETIME NULL DEFAULT NULL
    )");
    $pdo->exec("INSERT IGNORE INTO goal_ceremony (id) VALUES (1)");
    foreach (['musica'=>'VARCHAR(255) NULL','final_duration'=>'INT NOT NULL DEFAULT 15'] as $gcCol=>$gcDef) {
        if (!$pdo->query("SHOW COLUMNS FROM goal_ceremony LIKE '$gcCol'")->fetch())
            $pdo->exec("ALTER TABLE goal_ceremony ADD COLUMN $gcCol $gcDef");
    }

    // ── Portal do Closer ──────────────────────────────────────────────────────
    // closer_usuarios: conta de acesso ao portal. vendedor_id liga a conta ao closer do ranking
    // (NULL = pedido ainda sem vínculo; o admin vincula/cria ao aprovar). "SEM ACESSO" não é um
    // status guardado: é o closer que não tem nenhuma linha aqui. Revogar = status 'rejeitado'
    // com status_motivo; a senha só existe como hash e o token de redefinição só como sha256.
    $pdo->exec("CREATE TABLE IF NOT EXISTS closer_usuarios (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id         INT NULL,
        equipe_id           INT NULL,
        nome                VARCHAR(100) NOT NULL,
        email               VARCHAR(150) NOT NULL,
        senha_hash          VARCHAR(255) NOT NULL,
        status              ENUM('pendente','ativo','rejeitado') NOT NULL DEFAULT 'pendente',
        status_motivo       VARCHAR(255) NULL,
        status_em           DATETIME NULL,
        reset_solicitado_em DATETIME NULL,
        reset_token_hash    CHAR(64) NULL,
        reset_expira_em     DATETIME NULL,
        ultimo_login        DATETIME NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_email (email),
        UNIQUE KEY uk_vendedor (vendedor_id),
        KEY idx_status (status)
    )");
    // "Lembrar de mim": cookie selector.validator — só o hash do validator fica no banco
    $pdo->exec("CREATE TABLE IF NOT EXISTS closer_remember (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id     INT NOT NULL,
        selector       CHAR(24) NOT NULL,
        validator_hash CHAR(64) NOT NULL,
        expira_em      DATETIME NOT NULL,
        UNIQUE KEY uk_selector (selector),
        KEY idx_usuario (usuario_id)
    )");
    // Convite de cadastro gerado pelo admin para um closer já existente (link de uso único)
    $pdo->exec("CREATE TABLE IF NOT EXISTS closer_convites (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id INT NOT NULL,
        token_hash  CHAR(64) NOT NULL,
        expira_em   DATETIME NOT NULL,
        usado_em    DATETIME NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_token (token_hash),
        KEY idx_vendedor (vendedor_id)
    )");
    // Ações de prospecção registradas pelo closer (base do funil de Produtividade).
    // telefone = só dígitos (10/11); a UI mostra mascarado, exceto no formulário de edição.
    $pdo->exec("CREATE TABLE IF NOT EXISTS acoes_closer (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id   INT NOT NULL,
        tipo          ENUM('ligacao','followup','lead_quente') NOT NULL,
        telefone      VARCHAR(15) NOT NULL,
        observacao    VARCHAR(255) NULL,
        registrado_em DATETIME NOT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_vend_data (vendedor_id, registrado_em),
        KEY idx_data (registrado_em)
    )");

    // Migração única: o total do dia (contratos_historico) nunca pode ficar abaixo da soma dos
    // benefícios lançados (contratos_tipos). Dados antigos ou de teste podiam ter os dois fora de sintonia.
    if (!$pdo->query("SELECT 1 FROM app_settings WHERE setting_key='contratos_total_v1'")->fetchColumn()) {
        $pdo->exec("INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade)
                    SELECT vendedor_id, data_registro, SUM(quantidade) FROM contratos_tipos
                    GROUP BY vendedor_id, data_registro
                    ON DUPLICATE KEY UPDATE quantidade = GREATEST(quantidade, VALUES(quantidade))");
        $pdo->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('contratos_total_v1', '1')");
    }
    }
}
