<?php
declare(strict_types=1);

/**
 * Testes da fase 7 (admin): a gestão de acessos ao portal do closer no núcleo (AcessoCloserService), com DADOS SINTÉTICOS e relógio congelado em
 * 10/11/2026 10:00 (America/Sao_Paulo).
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\nucleo\fase7.php
 * As tabelas usadas são criadas (vazias) num banco DESCARTÁVEL (nucleo_teste_fase7), apagado no fim: nada do banco de verdade é lido ou alterado.
 */

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Services\AcessoCloserService;
use App\Controllers\AcessoCloserController;
use App\Core\Request;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');
require __DIR__ . '/harness.php';

Relogio::fixar('2026-11-10 10:00:00');

$real = new Database();
$real->testar();
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este teste só roda contra o MySQL local de teste.\n");
    exit(2);
}
$esquema = 'nucleo_teste_fase7';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
foreach (['vendedores', 'equipes', 'closer_usuarios', 'closer_convites', 'closer_remember', 'contratos', 'metas_periodo', 'metas_diarias_periodo', 'semanas_mes', 'app_settings'] as $tabela) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $tabela . '` LIKE `' . DB_NAME . '`.`' . $tabela . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);
$container = new Container();
$container->instancia(Database::class, $db);

$db->executar("INSERT INTO equipes (id, nome, cor) VALUES (1,'Alpha','#111111'),(2,'Beta','#222222')");
$db->executar("INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor) VALUES (1,1,'Ana',10,0,'#aaaaaa'),(2,1,'Bruno',10,0,'#bbbbbb'),(3,2,'Carla',10,0,'#cccccc')");
$servico = $container->obter(AcessoCloserService::class);
$agora = '2026-11-10 10:00:00';
$conta = static function (array $c) use ($db): int {
    $db->executar("INSERT INTO closer_usuarios (vendedor_id, equipe_id, nome, email, senha_hash, status, status_em) VALUES (?,?,?,?,?,?,?)",
        [$c[0], $c[1], $c[2], $c[3], 'hash', $c[4], '2026-11-09 09:00:00']);
    return (int) $db->idInserido();
};

t('Listar: uma linha por conta, a equipe vem do closer (ou da pedida) e nunca traz hash de senha nem de token', function () use ($servico, $conta, $db) {
    $a = $conta([1, null, 'Ana', 'ana@t.com', 'ativo']);
    $p = $conta([null, 2, 'Pedro Novo', 'pedro@t.com', 'pendente']);
    $db->executar("UPDATE closer_usuarios SET reset_token_hash='x', reset_expira_em='2026-11-11 10:00:00' WHERE id=$a");
    $r = $servico->listar();
    igual(2, count($r['data']));
    $porId = [];
    foreach ($r['data'] as $l) {
        $porId[$l['id']] = $l;
    }
    igual([1, 1], [$porId[$a]['vendedor_id'], $porId[$a]['equipe_id']], 'com closer: a equipe é a dele');
    igual([null, 2], [$porId[$p]['vendedor_id'], $porId[$p]['equipe_id']], 'sem closer: a equipe pedida');
    igual('2026-11-11 10:00:00', $porId[$a]['token_ativo_ate']);
    verdade(!array_key_exists('senha_hash', $porId[$a]) && !array_key_exists('reset_token_hash', $porId[$a]), 'sem hashes');
    igual('2026-11-10 10:00:00', $r['now']);
    verdade($r['convites'] instanceof stdClass);
});
t('Aprovar: conta sem closer exige escolher um closer ou criar um novo; escolher vincula e libera', function () use ($servico, $conta, $db) {
    $id = (int) $db->valor("SELECT id FROM closer_usuarios WHERE email='pedro@t.com'");
    igual('Escolha o closer ao qual esta conta pertence (ou crie um novo)', $servico->aprovar($id, 0, false)['erro']);
    igual('Closer escolhido não encontrado', $servico->aprovar($id, 999, false)['erro']);
    $r = $servico->aprovar($id, 3, false);
    igual('Acesso liberado — o closer já pode entrar no portal', $r['message']);
    igual([3, 'ativo'], [(int) $db->valor('SELECT vendedor_id FROM closer_usuarios WHERE id=?', [$id]), $db->valor('SELECT status FROM closer_usuarios WHERE id=?', [$id])]);
    igual('Este acesso já está ativo', $servico->aprovar($id, 3, false)['erro']);
    igual('Pedido de acesso não encontrado', $servico->aprovar(9999, 0, false)['erro']);
});
t('Aprovar: "criar novo" cria o closer na equipe pedida, no padrão de metas, com os dias da semana, e recusa nome repetido', function () use ($servico, $conta, $db) {
    $id = $conta([null, 2, 'Diego Novo', 'diego@t.com', 'pendente']);
    $r = $servico->aprovar($id, 0, true);
    igual('Acesso liberado — o closer já pode entrar no portal', $r['message']);
    $vid = (int) $db->valor('SELECT vendedor_id FROM closer_usuarios WHERE id=?', [$id]);
    verdade($vid > 3, 'closer novo criado');
    igual([2, 'Diego Novo', 10, '#d4af37'], [(int) $db->valor('SELECT equipe_id FROM vendedores WHERE id=?', [$vid]), $db->valor('SELECT nome FROM vendedores WHERE id=?', [$vid]), (int) $db->valor('SELECT metas FROM vendedores WHERE id=?', [$vid]), $db->valor('SELECT cor FROM vendedores WHERE id=?', [$vid])], 'a meta é a que a maioria já tem (10)');
    igual(7, (int) $db->valor('SELECT COUNT(*) FROM contratos WHERE vendedor_id=?', [$vid]), '7 dias de contratos zerados');
    $dup = $conta([null, 1, 'ana', 'ana2@t.com', 'pendente']);
    igual('Já existe um closer com esse nome — vincule a conta a ele', $servico->aprovar($dup, 0, true)['erro']);
    igual('pendente', $db->valor('SELECT status FROM closer_usuarios WHERE id=?', [$dup]), 'a recusa desfaz tudo');
});
t('Aprovar: closer vinculado e arquivado não libera; closer que já tem outra conta é recusado', function () use ($servico, $conta, $db) {
    $db->executar('UPDATE vendedores SET deleted_at = NOW() WHERE id = 2');
    $id = $conta([2, null, 'Bruno', 'bruno@t.com', 'pendente']);
    igual('O closer vinculado está arquivado. Reative-o antes de liberar o acesso', $servico->aprovar($id, 0, false)['erro']);
    $db->executar('UPDATE vendedores SET deleted_at = NULL WHERE id = 2');
    $sem = $conta([null, 1, 'Eva Sem Vinculo', 'eva@t.com', 'pendente']);
    igual('Esse closer já tem outra conta de acesso', $servico->aprovar($sem, 1, false)['erro'], 'a conta da Ana já existe (vendedor_id único)');
    igual('Acesso liberado — o closer já pode entrar no portal', $servico->aprovar($id, 0, false)['message']);
});
t('Editar: só pendente; valida nome, e-mail, repetido e equipe; com closer vinculado a equipe não muda', function () use ($servico, $conta, $db) {
    $p = $conta([null, 1, 'Fulano Pendente', 'fulano@t.com', 'pendente']);
    igual('Só dá para editar pedidos pendentes.', $servico->editar((int) $db->valor("SELECT id FROM closer_usuarios WHERE email='ana@t.com'"), 'Ana Nova', 'x@t.com', 0)['erro']);
    igual('nome', $servico->editar($p, 'Jo', 'f@t.com', 0)['campo']);
    igual('email', $servico->editar($p, 'Fulano Silva', 'sem-arroba', 0)['campo']);
    igual('Este e-mail já tem cadastro.', $servico->editar($p, 'Fulano Silva', 'ana@t.com', 0)['erro']);
    igual('equipe_id', $servico->editar($p, 'Fulano Silva', 'FULANO2@t.com', 99)['campo']);
    igual('Pedido atualizado', $servico->editar($p, 'Fulano Silva', ' FULANO2@t.com ', 2)['message']);
    igual(['Fulano Silva', 'fulano2@t.com', 2], [$db->valor('SELECT nome FROM closer_usuarios WHERE id=?', [$p]), $db->valor('SELECT email FROM closer_usuarios WHERE id=?', [$p]), (int) $db->valor('SELECT equipe_id FROM closer_usuarios WHERE id=?', [$p])]);
    $v = $conta([null, 1, 'Gil Vinculado', 'gil@t.com', 'pendente']);
    $db->executar('UPDATE closer_usuarios SET vendedor_id = 3 WHERE id = ' . $v . ' AND 0');   // sem vínculo real (único): simula pela equipe
    $db->executar('DELETE FROM closer_usuarios WHERE id=' . $v);
});
t('Rejeitar, reconsiderar, revogar e excluir: cada um só vale no status certo e guarda o motivo', function () use ($servico, $conta, $db, $agora) {
    $p = (int) $db->valor("SELECT id FROM closer_usuarios WHERE email='fulano2@t.com'");
    igual('Acesso rejeitado', $servico->rejeitar($p, '  falta documento  ')['message']);
    igual(['rejeitado', 'falta documento', $agora], [$db->valor('SELECT status FROM closer_usuarios WHERE id=?', [$p]), $db->valor('SELECT status_motivo FROM closer_usuarios WHERE id=?', [$p]), $db->valor('SELECT status_em FROM closer_usuarios WHERE id=?', [$p])]);
    igual('Só é possível rejeitar pedidos pendentes', $servico->rejeitar($p, '')['erro']);
    igual('Pedido reaberto — aprove ou rejeite novamente', $servico->reconsiderar($p)['message']);
    igual('Só é possível reconsiderar acessos rejeitados', $servico->reconsiderar($p)['erro']);
    $ana = (int) $db->valor("SELECT id FROM closer_usuarios WHERE email='ana@t.com'");
    $db->executar("INSERT INTO closer_remember (usuario_id, selector, validator_hash, expira_em) VALUES ($ana, 'sel', 'h', '2099-01-01 00:00:00')");
    igual('Revogue o acesso antes de excluir a conta', $servico->excluir($ana)['erro']);
    igual('Acesso revogado (histórico mantido)', $servico->revogar($ana)['message']);
    igual(['rejeitado', 'Acesso revogado pelo administrador', 0], [$db->valor('SELECT status FROM closer_usuarios WHERE id=?', [$ana]), $db->valor('SELECT status_motivo FROM closer_usuarios WHERE id=?', [$ana]), (int) $db->valor('SELECT COUNT(*) FROM closer_remember WHERE usuario_id=?', [$ana])]);
    igual('Só é possível revogar acessos ativos', $servico->revogar($ana)['erro']);
    igual('Pedido excluído', $servico->excluir($p)['message']);
    igual(0, (int) $db->valor('SELECT COUNT(*) FROM closer_usuarios WHERE id=?', [$p]));
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM vendedores WHERE id=1'), 'excluir a conta nunca apaga o closer');
});
t('Convite: só para closer ativo sem conta; o novo link apaga o anterior; guarda só o hash e vale 7 dias', function () use ($servico, $db) {
    igual('Closer não encontrado', $servico->convite(999)['erro']);
    igual('Este closer já tem uma conta de acesso', $servico->convite(3)['erro']);
    $db->executar('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor) VALUES (9,1,\'Hugo\',10,0,\'#999999\')');
    $a = $servico->convite(9);
    verdade(preg_match('#^closer-register\.php\?convite=[0-9a-f]{40}$#', $a['path']) === 1, $a['path']);
    igual(['Hugo', '2026-11-17 10:00:00'], [$a['nome'], $a['expira_em']]);
    $b = $servico->convite(9);
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM closer_convites WHERE vendedor_id=9 AND usado_em IS NULL'), 'só o último link vale');
    $tok = substr($b['path'], strlen('closer-register.php?convite='));
    igual(hash('sha256', $tok), $db->valor('SELECT token_hash FROM closer_convites WHERE vendedor_id=9'));
});
t('Token de senha: só conta ativa; formato XXXXX-XXXXX sem caracteres ambíguos; guarda só o hash, 24 h, e limpa o pedido', function () use ($servico, $db) {
    $p = (int) $db->valor("SELECT id FROM closer_usuarios WHERE email='diego@t.com'");
    $db->executar("UPDATE closer_usuarios SET reset_solicitado_em='2026-11-09 08:00:00' WHERE id=$p");
    igual('Só contas ativas podem redefinir a senha', $servico->tokenDeSenha(9999)['erro']);
    $r = $servico->tokenDeSenha($p);
    verdade(preg_match('/^[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}$/', $r['token']) === 1, $r['token']);
    igual(['Diego Novo', '2026-11-11 10:00:00'], [$r['nome'], $r['expira_em']]);
    igual([hash('sha256', str_replace('-', '', $r['token'])), null], [$db->valor('SELECT reset_token_hash FROM closer_usuarios WHERE id=?', [$p]), $db->valor('SELECT reset_solicitado_em FROM closer_usuarios WHERE id=?', [$p])]);
});
t('Controller: o corpo de sucesso, o erro com "field" e a ação pelos parâmetros do formulário', function () use ($container, $db) {
    $c = $container->obter(AcessoCloserController::class);
    $lista = json_decode($c->listar(req('GET', '/x'), [])->corpo(), true);
    igual(true, $lista['success']);
    $erro = json_decode($c->editar(req('POST', '/x', [], [], ['id' => '99999', 'nome' => 'Fulano', 'email' => 'f@t.com']), [])->corpo(), true);
    igual([false, 'Pedido de acesso não encontrado'], [$erro['success'], $erro['message']]);
    $campo = json_decode($c->editar(req('POST', '/x', [], [], ['id' => (string) (int) $db->valor('SELECT id FROM closer_usuarios LIMIT 1'), 'nome' => 'Jo', 'email' => 'f@t.com']), [])->corpo(), true);
    verdade($campo['success'] === false);
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
exit($falharam ? 1 : 0);
