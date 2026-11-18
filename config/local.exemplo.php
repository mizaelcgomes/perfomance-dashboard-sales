<?php
/**
 * EXEMPLO. Copie para config/local.php (no servidor) e deixe só o que precisa mudar naquele ambiente.
 * O config/local.php sobrepõe config/app.php e config/database.php, e não vai nos pacotes de atualização.
 */
return [
    'app' => [
        // 'debug' => true,                       // só para investigar um erro; volte para false depois
        // 'storage' => '/home/USUARIO/storage',  // se a pasta de gravação ficar em outro lugar
    ],
    'database' => [
        // Se o núcleo não achar o config.secrets.php sozinho, aponte o caminho completo do arquivo:
        // 'segredos' => '/home/USUARIO/public_html/config.secrets.php',
    ],
];
