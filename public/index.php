<?php
declare(strict_types=1);

/**
 * Front Controller do núcleo MVC: o ÚNICO ponto de entrada. O .htaccess manda para cá toda URL que não seja um
 * arquivo existente; daqui a requisição vai para o Router (app/routes.php).
 *
 * Onde ficam app/, config/ e storage/: o código procura a pasta que contém app/Core/Autoloader.php na própria pasta
 * desta index.php, depois subindo até 3 níveis; em cada nível olha o próprio nível e uma subpasta "nucleo/". Isso cobre:
 *   - ambiente local: public/ ao lado de app/;
 *   - HostGator (recomendado): nucleo/ DENTRO da pasta do site, ao lado deste index.php, protegida pelo .htaccess da pasta;
 *   - nucleo/ acima da pasta do site (pasta pessoal), fora do alcance do navegador.
 */

$raiz = null;
foreach ([__DIR__, dirname(__DIR__), dirname(__DIR__, 2), dirname(__DIR__, 3)] as $acima) {
    foreach ([$acima, $acima . '/nucleo'] as $candidata) {
        if (is_file($candidata . '/app/Core/Autoloader.php')) {
            $raiz = $candidata;
            break 2;
        }
    }
}

if ($raiz === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Núcleo não encontrado: a pasta app/ precisa ficar ao lado desta pasta ou acima dela.']);
    exit;
}

require $raiz . '/app/Core/Autoloader.php';
App\Core\Autoloader::registrar($raiz . '/app');

(new App\Core\Aplicacao($raiz, __DIR__))->executar();
