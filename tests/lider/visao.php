<?php
declare(strict_types=1);

/**
 * Testes do módulo do líder, fase L2 (métricas do time e seção Líderes do admin), num banco DESCARTÁVEL (nucleo_teste_lider_visao), apagado
 * no fim. Mostra que o líder só enxerga a própria equipe (nos dados e nas rotas), que os números fecham entre si (contratos, ranking,
 * painel) e que o resumo do admin traz líder, acesso e totais certos por equipe.
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\lider\visao.php
 * A igualdade com a Produtividade ANTIGA do admin é provada à parte (tests\lider\equivalencia.php, que fala com o servidor 8787).
 */

use App\Core\Aplicacao;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Core\Session;
use App\Services\LiderVisaoService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');
require __DIR__ . '/../nucleo/harness.php';

Relogio::fixar('2026-11-10 10:00:00');   // terça-feira

$real = new Database();
$real->testar();
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este teste só roda contra o MySQL local de teste.\n");
    exit(2);
}
$esquema = 'nucleo_teste_lider_visao';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
// a estrutura de TODAS as tabelas do banco de teste (sem os dados): os serviços do Painel leem várias
foreach ($real->consultar('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = ?', [DB_NAME, 'BASE TABLE']) as $linha) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $linha['t'] . '` LIKE `' . DB_NAME . '`.`' . $linha['t'] . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);

// ── cenário ───────────────────────────────────────────────────────────────────────────────────────
// Alpha (equipe 1, líder Ana), Beta (equipe 2, líder Caio, cuja conta ainda está pendente) e Gama (equipe 3, sem líder).
$db->executar("INSERT INTO equipes (id, nome, cor, lider_id) VALUES (1, 'Alpha', '#111111', 1), (2, 'Beta', '#222222', 3), (3, 'Gama', '#333333', NULL)");
$vend = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor) VALUES (?,?,?,?,?,?)');
foreach ([[1, 1, 'Ana', 10, 40, '#abcdef'], [2, 1, 'Bruno', 5, 20, '#aaaaaa'], [3, 2, 'Caio', 8, 30, '#123456'], [4, 2, 'Davi', 6, 24, '#0a0b0c'], [5, 3, 'Eva', 4, 16, '#bbbbbb']] as $l) {
    $vend->execute($l);
}
$conta = $db->pdo()->prepare('INSERT INTO closer_usuarios (id, vendedor_id, equipe_id, nome, email, senha_hash, status) VALUES (?,?,?,?,?,?,?)');
foreach ([[10, 1, 1, 'Ana', 'ana@t.com', 'x', 'ativo'], [11, 2, 1, 'Bruno', 'bruno@t.com', 'x', 'ativo'], [12, 3, 2, 'Caio', 'caio@t.com', 'x', 'pendente'], [13, 4, 2, 'Davi', 'davi@t.com', 'x', 'ativo']] as $l) {
    $conta->execute($l);
}
$acao = $db->pdo()->prepare('INSERT INTO acoes_closer (vendedor_id, tipo, telefone, registrado_em) VALUES (?,?,?,?)');
foreach ([
    [1, 'ligacao', '11990000001'], [1, 'ligacao', '11990000002'], [1, 'ligacao', '11990000003'], [1, 'followup', '11990000001'], [1, 'followup', '11990000002'], [1, 'lead_quente', '11990000001'],
    [2, 'ligacao', '11990000009'],
    [4, 'ligacao', '11880000001'], [4, 'ligacao', '11880000002'],
] as $l) {
    $acao->execute([$l[0], $l[1], $l[2], '2026-11-10 09:00:00']);
}
$contr = $db->pdo()->prepare('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?,?,?)');
foreach ([[1, '2026-11-10', 2], [2, '2026-11-09', 1], [3, '2026-11-10', 3], [4, '2026-11-10', 1], [5, '2026-11-10', 5]] as $l) {
    $contr->execute($l);
}

$container = new Container();
$container->instancia(Database::class, $db);
$visoes = $container->obter(LiderVisaoService::class);
$nomes = static fn(array $linhas, string $chave = 'nome'): array => array_column($linhas, $chave);

// ═══ A visão de UMA equipe ═══════════════════════════════════════════════════════════════════════
t('visão da equipe 1 (hoje): equipe, líder e só os closers dela', function () use ($visoes, $nomes) {
    $v = $visoes->visao(1, 'hoje');
    igual(['id' => 1, 'nome' => 'Alpha', 'cor' => '#111111', 'lider_vendedor_id' => 1, 'lider_nome' => 'Ana'], $v['equipe']);
    $ordenados = $nomes($v['produtividade']['ranking']);
    sort($ordenados);
    igual(['Ana', 'Bruno'], $ordenados);
});
t('visão da equipe 1 (hoje): indicadores só da equipe (ligações, follow-ups, leads quentes, contratos)', function () use ($visoes) {
    $k = $visoes->visao(1, 'hoje')['produtividade']['kpis'];
    igual(4, $k['ligacoes']);
    igual(2, $k['followups']);
    igual(1, $k['leads_quentes']);
    igual(2, $k['contratos'], 'só o contrato de hoje (o do Bruno é de ontem)');
});
t('visão da equipe 1: contratos do período fecham com os indicadores e com a soma por closer, do maior para o menor', function () use ($visoes, $nomes) {
    $v = $visoes->visao(1, 'semana');
    igual(3, $v['contratos']['total'], 'semana: 2 da Ana + 1 do Bruno');
    igual($v['produtividade']['kpis']['contratos'], $v['contratos']['total']);
    igual(['Ana', 'Bruno'], $nomes($v['contratos']['por_closer']));
    igual([2, 1], array_column($v['contratos']['por_closer'], 'contratos'));
});
t('visão da equipe 1: nada da equipe 2 nem da 3 aparece em lugar nenhum (nomes, ranking, painel)', function () use ($visoes) {
    $v = $visoes->visao(1, 'semana');
    $json = json_encode($v, JSON_UNESCAPED_UNICODE);
    foreach (['Caio', 'Davi', 'Eva', 'Beta', 'Gama'] as $fora) {
        verdade(strpos($json, $fora) === false, "$fora vazou para a visão da equipe 1");
    }
});
t('visão: rankings da semana e do mês só com a equipe, numerados de 1 em diante', function () use ($visoes) {
    $v = $visoes->visao(1, 'semana');
    foreach (['semana', 'mes'] as $r) {
        verdade(is_array($v['ranking'][$r]), "ranking $r existe");
        foreach ($v['ranking'][$r] as $i => $linha) {
            igual(1, $linha['equipe_id']);
            igual($i + 1, $linha['pos']);
        }
        igual(2, count($v['ranking'][$r]), "ranking $r tem os 2 closers");
    }
});
t('visão: painel da equipe e dos closers é o do admin (mesma conta: vendas da semana e do mês)', function () use ($visoes, $container) {
    $v = $visoes->visao(1, 'semana');
    $p = $container->obter(\App\Services\PainelService::class)->painel(0, 0, 0);
    $time = null;
    foreach ($p['teams'] as $t) {
        if ($t['id'] === 1) {
            $time = $t;
        }
    }
    igual($time['vendas'], $v['painel']['equipe']['vendas']);
    igual($time['vendas_mes'], $v['painel']['equipe']['vendas_mes']);
    igual($time['score'], $v['painel']['equipe']['score']);
    igual(3, $v['painel']['equipe']['vendas_mes'], 'mês de novembro: 2 + 1');
    igual([1], array_values(array_unique(array_column($v['painel']['closers'], 'equipe_id'))));
});
t('visão da equipe 2: só Caio e Davi; o líder sem conta ativa não conta ligações, e os contratos contam todos', function () use ($visoes, $nomes) {
    $v = $visoes->visao(2, 'hoje');
    $ordenados = $nomes($v['produtividade']['ranking']);
    sort($ordenados);
    igual(['Caio', 'Davi'], $ordenados);
    igual(4, $v['contratos']['total'], '3 do Caio + 1 do Davi');
    igual(1, $v['produtividade']['participantes'], 'só o Davi tem conta ativa');
    igual(2, $v['produtividade']['kpis']['ligacoes'], 'as ligações do Davi');
});
t('visão de equipe sem nenhum closer ou que não existe: resposta vazia, sem erro', function () use ($visoes, $db) {
    $db->executar("INSERT INTO equipes (id, nome, cor) VALUES (9, 'Vazia', '#999999')");
    $v = $visoes->visao(9, 'hoje');
    igual(0, $v['contratos']['total']);
    igual([], $v['produtividade']['ranking']);
    igual([], $v['ranking']['mes'] ?? []);
    $x = $visoes->visao(77, 'hoje');
    igual(null, $x['equipe']);
    igual(0, $x['contratos']['total']);
    $db->executar('DELETE FROM equipes WHERE id = 9');
});
t('visão: período personalizado, invertido e no futuro seguem a mesma regra da Produtividade', function () use ($visoes) {
    $c = $visoes->visao(1, 'custom', '2026-11-09', '2026-11-10');
    igual(3, $c['contratos']['total']);
    $inv = $visoes->visao(1, 'custom', '2026-11-10', '2026-11-09');
    igual('2026-11-09', $inv['produtividade']['range']['start'], 'datas invertidas são trocadas');
    $fut = $visoes->visao(1, 'custom', '2099-01-01', '2099-01-10');
    igual(true, $fut['produtividade']['range']['vazio']);
    igual(0, $fut['contratos']['total']);
});

// ═══ O resumo por equipe (seção Líderes do admin) ═════════════════════════════════════════════════
t('resumo do admin: uma linha por equipe, com líder, nº de closers e contratos do período', function () use ($visoes) {
    $r = $visoes->resumoPorEquipe('hoje');
    igual(['Alpha', 'Beta', 'Gama'], array_column($r, 'equipe_nome'));
    igual([2, 4, 5], array_column($r, 'contratos_total'));
    igual([2, 2, 1], array_column($r, 'closers'));
    igual(['Ana', 'Caio', null], array_column($r, 'lider_nome'));
});
t('resumo do admin: "lider_acesso" diz se o líder tem conta ativa (Ana sim, Caio pendente não, equipe sem líder = null)', function () use ($visoes) {
    $r = $visoes->resumoPorEquipe('hoje');
    igual([true, false, null], array_column($r, 'lider_acesso'));
});
t('resumo do admin: os melhores do período e o painel da equipe', function () use ($visoes) {
    $r = $visoes->resumoPorEquipe('semana');
    igual(['Ana', 'Bruno'], array_column($r[0]['melhores'], 'nome'));
    igual(3, $r[0]['painel']['vendas_mes']);
    igual(['Caio', 'Davi'], array_column($r[1]['melhores'], 'nome'));
    igual(['Eva'], array_column($r[2]['melhores'], 'nome'));
});
t('resumo do admin: cada equipe bate com a visão do líder dessa equipe (mesmos números nos dois lados)', function () use ($visoes) {
    foreach ($visoes->resumoPorEquipe('semana') as $linha) {
        $v = $visoes->visao($linha['equipe_id'], 'semana');
        igual($v['produtividade']['kpis'], $linha['kpis']);
        igual($v['produtividade']['funil'], $linha['funil']);
        igual($v['contratos']['total'], $linha['contratos_total']);
    }
});
t('resumo do admin: líder que muda de equipe/arquivado deixa de aparecer como líder (sem nome) e sem acesso', function () use ($visoes, $db) {
    $db->executar('UPDATE vendedores SET deleted_at = NOW() WHERE id = 1');
    $r = $visoes->resumoPorEquipe('hoje');
    igual(null, $r[0]['lider_nome']);
    igual(null, $r[0]['lider_acesso']);
    $db->executar('UPDATE vendedores SET deleted_at = NULL WHERE id = 1');
});

// ═══ As rotas (Aplicacao::responder: rota, middleware e controller reais) ═══════════════════════════
$app = new Aplicacao($RAIZ, $RAIZ . '/public');
$chamar = static function (array $sessao, string $uri) use ($app, $db): array {
    Session::simular($sessao);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $get);   // o req() só entende a query se ela vier em $get
    $r = $app->responder(req('GET', $uri, [], $get), $db->pdo());
    return [$r->status(), json_decode($r->corpo(), true)];
};

t('rota /api/lider/visao: o líder recebe a equipe DELE, mesmo pedindo outra (equipe_id na URL é ignorado)', function () use ($chamar) {
    [$status, $j] = $chamar(['closer_id' => 10], '/api/lider/visao?periodo=semana&equipe_id=2');
    igual(200, $status);
    igual(1, $j['equipe']['id']);
    igual(false, strpos(json_encode($j), 'Caio') !== false);
});
t('rota /api/lider/visao: sem login 401, closer comum 403, líder com conta pendente 403', function () use ($chamar) {
    igual(401, $chamar([], '/api/lider/visao')[0]);
    igual(403, $chamar(['closer_id' => 11], '/api/lider/visao')[0]);
    igual(403, $chamar(['closer_id' => 12], '/api/lider/visao')[0]);
    igual(401, $chamar(['admin_logged' => true], '/api/lider/visao')[0], 'o admin não entra pela área do líder');
});
t('rota /api/lider/visao: o período da URL é respeitado (hoje × semana)', function () use ($chamar) {
    $hoje = $chamar(['closer_id' => 10], '/api/lider/visao?periodo=hoje')[1];
    $sem = $chamar(['closer_id' => 10], '/api/lider/visao?periodo=semana')[1];
    igual(2, $hoje['contratos']['total']);
    igual(3, $sem['contratos']['total']);
    igual('hoje', $hoje['produtividade']['range']['periodo']);
});
t('rota /api/lider/visao: a liderança muda de mãos na hora (Ana sai, Bruno entra, mesma sessão)', function () use ($chamar, $db) {
    igual(200, $chamar(['closer_id' => 10], '/api/lider/visao')[0]);
    $db->executar('UPDATE equipes SET lider_id = 2 WHERE id = 1');
    igual(403, $chamar(['closer_id' => 10], '/api/lider/visao')[0]);
    [$s, $j] = $chamar(['closer_id' => 11], '/api/lider/visao');
    igual(200, $s);
    igual('Bruno', $j['equipe']['lider_nome']);
    $db->executar('UPDATE equipes SET lider_id = 1 WHERE id = 1');
});
t('rota /api/lider/papel: líder → lider=true com a equipe; closer comum e conta pendente → lider=false; sem login 401', function () use ($chamar) {
    igual([200, ['success' => true, 'lider' => true, 'equipe_id' => 1, 'equipe_nome' => 'Alpha']], $chamar(['closer_id' => 10], '/api/lider/papel'));
    igual([200, ['success' => true, 'lider' => false]], $chamar(['closer_id' => 11], '/api/lider/papel'));
    igual([200, ['success' => true, 'lider' => false]], $chamar(['closer_id' => 12], '/api/lider/papel'));
    igual(401, $chamar([], '/api/lider/papel')[0]);
});
t('rota /api/admin/lideres: só o admin; devolve as 3 equipes com o líder de cada uma', function () use ($chamar) {
    igual(401, $chamar([], '/api/admin/lideres')[0]);
    igual(401, $chamar(['closer_id' => 10], '/api/admin/lideres')[0], 'nem o líder entra na seção do admin');
    [$s, $j] = $chamar(['admin_logged' => true], '/api/admin/lideres?periodo=hoje');
    igual(200, $s);
    igual(true, $j['success']);
    igual(['Ana', 'Caio', null], array_column($j['equipes'], 'lider_nome'));
});
t('rotas do líder aceitam só GET (POST = 405)', function () use ($app, $db) {
    Session::simular(['closer_id' => 10]);
    igual(405, $app->responder(req('POST', '/api/lider/visao'), $db->pdo())->status());
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
exit($falharam ? 1 : 0);
