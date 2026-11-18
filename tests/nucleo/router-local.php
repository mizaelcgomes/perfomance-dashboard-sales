<?php
/**
 * Roteador do servidor embutido do PHP (php -S), SÓ para os testes locais: faz o que o .htaccess faz no Apache.
 *   - arquivo estático existente em public/ (css, js, imagem) => serve o arquivo (return false)
 *   - qualquer outra URL => o front controller (public/index.php), e só ele executa PHP
 * Com a variável de ambiente NUCLEO_BASE=/nova simula o núcleo montado em public_html/nova (HostGator):
 * as URLs chegam com o prefixo /nova e o SCRIPT_NAME é /nova/index.php, como no Apache.
 */
$base = rtrim((string) getenv('NUCLEO_BASE'), '/');
$publico = realpath(__DIR__ . '/../../public');
$caminho = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

$relativo = $caminho;
if ($base !== '' && ($caminho === $base || strncmp($caminho, $base . '/', strlen($base) + 1) === 0)) {
    $relativo = (string) substr($caminho, strlen($base));
}
$arquivo = realpath($publico . $relativo);
if ($relativo !== '/' && $relativo !== '' && $arquivo !== false && strncmp($arquivo, $publico, strlen($publico)) === 0
    && is_file($arquivo) && substr($arquivo, -4) !== '.php') {
    // static: o php -S serve pelo caminho da URL, então com prefixo é preciso entregar o arquivo à mão
    $tipos = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml'];
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
    readfile($arquivo);
    return true;
}

$_SERVER['SCRIPT_NAME'] = $base . '/index.php';
require $publico . '/index.php';
