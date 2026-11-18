<?php
declare(strict_types=1);

/**
 * Mini harness dos testes do núcleo (sem PHPUnit): t() registra um teste, igual()/verdade()/lanca() verificam, e as funções de apoio
 * montam requisições, pastas temporárias e cópias de pasta. Usado por unit.php e relatorios.php.
 */

use App\Core\Request;

// ── mini harness ────────────────────────────────────────────────────────────────
$passaram = 0;
$falharam = [];
function t(string $nome, callable $f): void
{
    global $passaram, $falharam;
    try {
        $f();
        $passaram++;
        echo "PASS $nome\n";
    } catch (Throwable $e) {
        $falharam[] = $nome;
        echo "FAIL $nome\n     → " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
function igual($esperado, $real, string $msg = ''): void
{
    if ($esperado !== $real) {
        throw new Exception(($msg !== '' ? $msg . ': ' : '') . 'esperado ' . var_export($esperado, true) . ', veio ' . var_export($real, true));
    }
}
function verdade($condicao, string $msg = 'condição falsa'): void
{
    if (!$condicao) {
        throw new Exception($msg);
    }
}
function lanca(string $classe, callable $f, string $msg = ''): void
{
    try {
        $f();
    } catch (Throwable $e) {
        if (!($e instanceof $classe)) {
            throw new Exception("esperava $classe, veio " . get_class($e) . ': ' . $e->getMessage());
        }
        return;
    }
    throw new Exception(($msg !== '' ? $msg . ': ' : '') . "esperava exceção $classe e nada foi lançado");
}
function req(string $metodo, string $uri, array $extra = [], array $get = [], array $post = [], string $bruto = ''): Request
{
    return Request::deServidor(array_merge(['REQUEST_METHOD' => $metodo, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '10.0.0.1'], $extra), $get, $post, $bruto);
}
function pastaTemp(string $prefixo): string
{
    $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefixo . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return str_replace('\\', '/', $d);
}
function apagar(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) ?: [] as $i) {
        if ($i === '.' || $i === '..') {
            continue;
        }
        $c = $d . '/' . $i;
        is_dir($c) ? apagar($c) : @unlink($c);
    }
    @rmdir($d);
}
function copiar(string $de, string $para): void
{
    if (!is_dir($para)) {
        mkdir($para, 0777, true);
    }
    foreach (scandir($de) ?: [] as $i) {
        if ($i === '.' || $i === '..') {
            continue;
        }
        is_dir("$de/$i") ? copiar("$de/$i", "$para/$i") : copy("$de/$i", "$para/$i");
    }
}
