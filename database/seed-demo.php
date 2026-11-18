<?php
/**
 * Cria o esquema e popula dados FICTÍCIOS para demonstração (closers, equipes, contratos da semana e do mês).
 * Só roda contra banco local.   Uso:  php database/seed-demo.php
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
require $raiz . '/app/Core/Autoloader.php';
App\Core\Autoloader::registrar($raiz . '/app');
require $raiz . '/config.secrets.php';

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: o seed só roda contra banco local.\n");
    exit(2);
}
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Tabela base de closers (as demais, e as colunas extras, vêm do EsquemaDoBanco)
$pdo->exec("CREATE TABLE IF NOT EXISTS vendedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipe_id INT NULL,
    nome VARCHAR(100) NOT NULL,
    metas INT NOT NULL DEFAULT 20,
    vendas INT NOT NULL DEFAULT 0,
    faltam INT NOT NULL DEFAULT 20,
    score INT NOT NULL DEFAULT 0,
    cor VARCHAR(7) NOT NULL DEFAULT '#d4af37',
    img_url VARCHAR(255) NULL,
    img_pravatar INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
App\Services\EsquemaDoBanco::garantir($pdo);
(new App\Services\MigracaoLiderService(App\Core\Database::daPdo($pdo)))->executar();

if ((int) $pdo->query('SELECT COUNT(*) FROM vendedores')->fetchColumn() > 0) {
    fwrite(STDERR, "O banco já tem closers; seed cancelado para não duplicar. Use um banco vazio.\n");
    exit(1);
}

mt_srand(42);   // dados reprodutíveis
$equipes = [['Falcões', '#e0b13c'], ['Lobos', '#3b82f6'], ['Águias', '#10b981']];
$nomes = ['Ana Souza', 'Bruno Lima', 'Carla Dias', 'Diego Rocha', 'Elisa Nunes', 'Fábio Costa', 'Gabi Prado', 'Hugo Matos',
          'Ícaro Reis', 'Julia Alves', 'Kleber Dantas', 'Lívia Pires'];
$tipos = ['aux_acidente', 'bpc_loas', 'sal_maternidade', 'aux_doenca', 'outros'];

$insEq = $pdo->prepare('INSERT INTO equipes (nome, cor, meta_semanal, meta_mensal) VALUES (?,?,?,?)');
foreach ($equipes as [$n, $c]) { $insEq->execute([$n, $c, 40, 160]); }

$insV = $pdo->prepare('INSERT INTO vendedores (equipe_id, nome, metas, meta_mensal, cor) VALUES (?,?,?,?,?)');
$insH = $pdo->prepare('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?,?,?)');
$insT = $pdo->prepare('INSERT INTO contratos_tipos (vendedor_id, data_registro, tipo, quantidade) VALUES (?,?,?,?)');
$insA = $pdo->prepare('INSERT INTO atendimentos (vendedor_id, data_registro, quantidade) VALUES (?,?,?)');

$hoje = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
foreach ($nomes as $i => $nome) {
    $insV->execute([($i % 3) + 1, $nome, 10, 40, $equipes[$i % 3][1]]);
    $id = (int) $pdo->lastInsertId();
    for ($d = 0; $d < 30; $d++) {                       // últimos 30 dias
        $dia = $hoje->modify("-$d day");
        if ((int) $dia->format('N') === 7) { continue; }
        $total = 0;
        foreach ($tipos as $tp) {
            $q = mt_rand(0, 9) < 2 ? mt_rand(1, 2) : 0;
            if ($q > 0) { $insT->execute([$id, $dia->format('Y-m-d'), $tp, $q]); $total += $q; }
        }
        if ($total > 0) { $insH->execute([$id, $dia->format('Y-m-d'), $total]); }
        $insA->execute([$id, $dia->format('Y-m-d'), mt_rand(3, 12)]);
    }
}
$pdo->exec('UPDATE equipes SET lider_id = (SELECT MIN(id) FROM vendedores v WHERE v.equipe_id = equipes.id)');
echo "Seed ok: 3 equipes, " . count($nomes) . " closers e 30 dias de contratos fictícios.\n";
