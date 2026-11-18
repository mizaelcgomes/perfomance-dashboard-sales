<?php
declare(strict_types=1);

/**
 * Roda SÓ a migração do módulo do líder (tabelas e colunas) no banco LOCAL de teste. É o que o reset-local-db.ps1 chama depois de recriar o
 * banco, para o banco local espelhar o servidor depois de rodada a migração (/nova/_migracao/lider). Só roda contra 127.0.0.1.
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\lider\migrar-local.php
 */

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Database;
use App\Services\MigracaoLiderService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');

$db = new Database();
$db->testar();   // carrega o config.secrets.php (define DB_HOST)
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este script só roda contra o MySQL local de teste.\n");
    exit(2);
}
echo json_encode((new MigracaoLiderService($db))->executar(), JSON_UNESCAPED_UNICODE) . "\n";
