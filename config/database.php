<?php
/**
 * Banco de dados do núcleo. As credenciais NÃO ficam aqui: vêm do config.secrets.php do sistema atual
 * (constantes DB_HOST, DB_NAME, DB_USER, DB_PASS), para a senha continuar em um lugar só.
 *
 * O núcleo procura o config.secrets.php nesta ordem:
 *   1. 'segredos' (caminho fixo, se você definir em config/local.php)
 *   2. a raiz do código (layout do ambiente local: tudo numa pasta)
 *   3. a pasta ACIMA da pasta pública do núcleo (na HostGator, com o núcleo em <site>/nova, é a raiz do site,
 *      onde já está o login.php e o config.secrets.php)
 * Variáveis disponíveis: $raiz e $publico (ver config/app.php).
 */
return [
    'segredos' => null,
    'segredos_candidatos' => [
        $raiz . '/config.secrets.php',
        dirname($publico) . '/config.secrets.php',
    ],
    'charset' => 'utf8mb4',
];
