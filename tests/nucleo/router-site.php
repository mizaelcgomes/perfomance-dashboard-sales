<?php
/**
 * Roteador do site LOCAL de teste (php -S 127.0.0.1:8787 -t public tests/nucleo/router-site.php), como o .htaccess faz no Apache:
 *   - arquivo estático existente em public/ => serve (return false);
 *   - qualquer outra URL => o front controller (public/index.php).
 * Os nomes ANTIGOS (api.php, closer-api.php, login.php, admin.php...) são traduzidos para as rotas novas, só aqui, para as suítes de teste
 * antigas continuarem válidas. Em produção esses nomes não existem (sem redirecionamento).
 */
$publico = dirname(__DIR__, 2) . '/public';
$caminho = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$consulta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY);

if ($caminho !== '/' && is_file($publico . $caminho) && substr($caminho, -4) !== '.php') {
    return false;
}
$antigos = [
    '/api.php' => '/api', '/closer-api.php' => '/api/closer', '/index.php' => '/', '/login.php' => '/login', '/logout.php' => '/logout',
    '/admin.php' => '/admin', '/closer.php' => '/closer', '/lider.php' => '/lider', '/closer-login.php' => '/closer/login',
    '/closer-register.php' => '/closer/cadastro', '/closer-recuperar.php' => '/closer/recuperar',
];
$novo = $antigos[$caminho] ?? $caminho;
$_SERVER['REQUEST_URI'] = $novo . ($consulta !== '' ? '?' . $consulta : '');
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $publico . '/index.php';
