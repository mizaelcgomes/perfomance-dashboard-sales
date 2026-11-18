<?php
/**
 * Cria/atualiza as tabelas e colunas do sistema no banco configurado (config.secrets.php). Só roda contra 127.0.0.1 (banco de teste).
 * Uso: php tests/nucleo/preparar-banco.php
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
App\Core\Autoloader::registrar(dirname(__DIR__, 2) . '/app');
require dirname(__DIR__, 2) . '/config.secrets.php';

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: só roda contra o banco local (DB_HOST = 127.0.0.1).\n");
    exit(2);
}
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
App\Services\EsquemaDoBanco::garantir($pdo);
echo "esquema ok\n";
