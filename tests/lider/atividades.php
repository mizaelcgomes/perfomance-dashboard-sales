<?php
declare(strict_types=1);

/**
 * Testes das "Atividades dos líderes" (admin, aba Produtividade), fase L3, num banco DESCARTÁVEL (nucleo_teste_lider_ativ), apagado no fim.
 * Mostra que a atividade é só o que o líder CRIOU na equipe dele (edição não conta, registro de outra equipe ou de outra pessoa não conta),
 * que o período segue a Produtividade, que o alerta e a ordem fazem sentido e que as rotas exigem o login do admin.
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\lider\atividades.php
 */

use App\Core\Aplicacao;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Core\Session;
use App\Services\AtividadeLiderService;

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
$esquema = 'nucleo_teste_lider_ativ';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
foreach ($real->consultar('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = ?', [DB_NAME, 'BASE TABLE']) as $linha) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $linha['t'] . '` LIKE `' . DB_NAME . '`.`' . $linha['t'] . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);

// ── cenário ───────────────────────────────────────────────────────────────────────────────────────
// Alpha(1, líder Ana v1, conta ativa) · Beta(2, líder Caio v3, conta PENDENTE, nenhum registro) · Gama(3, líder Eva v5, último registro 05/11)
// Delta(4, líder Hugo v9, último registro 07/11 = 3 dias) · Epsilon(5, sem líder)
$db->executar("INSERT INTO equipes (id, nome, cor, lider_id) VALUES (1,'Alpha','#111111',1),(2,'Beta','#222222',3),(3,'Gama','#333333',5),(4,'Delta','#444444',9),(5,'Epsilon','#555555',NULL)");
$vend = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor) VALUES (?,?,?,?,?,?)');
foreach ([[1, 1, 'Ana', 10, 40, '#abcdef'], [2, 1, 'Bruno', 5, 20, '#aaaaaa'], [3, 2, 'Caio', 8, 30, '#123456'], [4, 2, 'Davi', 6, 24, '#0a0b0c'],
          [5, 3, 'Eva', 5, 20, '#bbbbbb'], [8, 3, 'Fabio', 5, 20, '#cccccc'], [9, 4, 'Hugo', 5, 20, '#dddddd'], [10, 4, 'Iara', 5, 20, '#eeeeee'], [11, 5, 'Joao', 5, 20, '#ffffff']] as $l) {
    $vend->execute($l);
}
$conta = $db->pdo()->prepare('INSERT INTO closer_usuarios (id, vendedor_id, equipe_id, nome, email, senha_hash, status) VALUES (?,?,?,?,?,?,?)');
foreach ([[10, 1, 1, 'Ana', 'ana@t.com', 'x', 'ativo'], [11, 2, 1, 'Bruno', 'bruno@t.com', 'x', 'ativo'], [12, 3, 2, 'Caio', 'caio@t.com', 'x', 'pendente'],
          [15, 5, 3, 'Eva', 'eva@t.com', 'x', 'ativo'], [19, 9, 4, 'Hugo', 'hugo@t.com', 'x', 'ativo']] as $l) {
    $conta->execute($l);
}
// reuniões (equipe, título, marcada, realizada, status, criado_por, criado_em, atualizado_em)
$reu = $db->pdo()->prepare('INSERT INTO reunioes (equipe_id, titulo, data_hora_marcada, data_hora_realizada, status, criado_por, criado_em, atualizado_em) VALUES (?,?,?,?,?,?,?,?)');
foreach ([
    [1, 'R1 hoje', '2026-11-12 09:00:00', null, 'agendada', 1, '2026-11-10 09:00:00', null],
    [1, 'R2 feita', '2026-11-06 10:00:00', '2026-11-06 10:20:00', 'realizada', 1, '2026-11-05 08:00:00', '2026-11-06 10:20:00'],
    [1, 'R3 cancelada', '2026-11-04 10:00:00', null, 'cancelada', 1, '2026-11-02 08:00:00', '2026-11-03 08:00:00'],
    [1, 'R4 outubro', '2026-10-21 10:00:00', null, 'agendada', 1, '2026-10-20 08:00:00', null],
    [1, 'R5 do Bruno', '2026-11-12 11:00:00', null, 'agendada', 2, '2026-11-10 09:10:00', null],
] as $l) {
    $reu->execute($l);
}
// feedbacks (equipe, closer, líder, data, assunto, texto, criado_em, atualizado_em)
$fb = $db->pdo()->prepare("INSERT INTO feedbacks (equipe_id, closer_vendedor_id, lider_vendedor_id, data, assunto, texto, criado_em, atualizado_em) VALUES (?,?,?,?,?,'t',?,?)");
foreach ([
    [1, 2, 1, '2026-11-10', 'F1 hoje', '2026-11-10 09:05:00', null],
    [1, 2, 1, '2026-11-04', 'F2', '2026-11-04 15:00:00', null],
    [2, 4, 1, '2026-11-10', 'F3 de outra equipe, mesmo líder', '2026-11-10 09:06:00', null],
    [1, 2, 1, '2026-10-01', 'F5 antigo editado hoje', '2026-10-01 09:00:00', '2026-11-10 09:07:00'],
    [3, 8, 5, '2026-11-05', 'G1', '2026-11-05 12:00:00', null],
    [4, 10, 9, '2026-11-07', 'D1', '2026-11-07 12:00:00', null],
] as $l) {
    $fb->execute($l);
}
$db->executar("INSERT INTO cobrancas_cadastro (equipe_id, closer_vendedor_id, lider_vendedor_id, data, observacao, criado_em) VALUES (1, 2, 1, '2026-11-09', 'cobrar', '2026-11-09 11:00:00')");
$db->executar("INSERT INTO revisoes_atendimento (equipe_id, closer_vendedor_id, telefone_cliente, observacao, lider_vendedor_id, data, criado_em) VALUES
    (1, 2, '11987654321', 'V1', 1, '2026-11-10', '2026-11-10 09:20:00'), (1, 2, '11987654322', 'V2', 1, '2026-11-10', '2026-11-10 09:30:00')");
$db->executar("INSERT INTO treinamentos (id, equipe_id, data_hora, assunto, criado_por, criado_em) VALUES
    (1, 1, '2026-11-08 14:00:00', 'T1', 1, '2026-11-01 09:00:00'), (2, 1, '2026-12-01 14:00:00', 'T2 dezembro', 1, '2026-11-02 09:00:00')");
$db->executar('INSERT INTO treinamento_presencas (treinamento_id, vendedor_id) VALUES (1, 1), (1, 2), (1, 3)');   // o 3 é da equipe Beta: não conta
// registros do líder como closer (referência): 2 ligações e 1 contrato da Ana hoje
$db->executar("INSERT INTO acoes_closer (vendedor_id, tipo, telefone, registrado_em) VALUES (1, 'ligacao', '11990000001', '2026-11-10 08:00:00'), (1, 'ligacao', '11990000002', '2026-11-10 08:05:00')");
$db->executar("INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (1, '2026-11-10', 1)");

$container = new Container();
$container->instancia(Database::class, $db);
$svc = $container->obter(AtividadeLiderService::class);
$linhaDe = static function (array $r, int $equipe): array {
    foreach ($r['equipes'] as $l) {
        if ($l['equipe_id'] === $equipe) {
            return $l;
        }
    }
    throw new Exception("sem a equipe $equipe");
};
$NOV = ['custom', '2026-11-01', '2026-11-10'];

// ═══ Contagens por líder ════════════════════════════════════════════════════════════════════════
t('atividade: contagens de novembro até hoje da Ana (reuniões, feedbacks, cobranças, revisões, treinamentos e presenças)', function () use ($svc, $linhaDe, $NOV) {
    $a = $linhaDe($svc->resumo(...$NOV), 1);
    igual(['marcadas' => 3, 'realizadas' => 1, 'canceladas' => 1], $a['reunioes']);
    igual(2, $a['feedbacks'], 'F1 e F2; o F3 é de outra equipe e o F5 só foi editado hoje');
    igual(1, $a['cobrancas']);
    igual(2, $a['revisoes']);
    igual(['dados' => 1, 'presencas' => 2], $a['treinamentos'], 'T2 é de dezembro; a presença do Caio (outra equipe) não conta');
    igual(9, $a['total'], '3 reuniões + 2 feedbacks + 1 cobrança + 2 revisões + 1 treinamento');
});
t('atividade: só o que o líder CRIOU conta — reunião de outra pessoa, feedback de outra equipe e edição de registro antigo ficam de fora', function () use ($svc, $linhaDe) {
    $hoje = $linhaDe($svc->resumo('hoje'), 1);
    igual(1, $hoje['reunioes']['marcadas'], 'R1; a R5 é do Bruno');
    igual(1, $hoje['feedbacks'], 'só o F1: o F3 é de outra equipe e o F5 foi criado em outubro');
    igual(2, $hoje['revisoes']);
    igual(4, $hoje['total']);
});
t('atividade: o período personalizado, invertido e no futuro seguem a regra da Produtividade', function () use ($svc, $linhaDe) {
    $inv = $linhaDe($svc->resumo('custom', '2026-11-10', '2026-11-01'), 1);
    igual(9, $inv['total'], 'datas invertidas são trocadas');
    $fut = $svc->resumo('custom', '2099-01-01', '2099-01-10');
    igual(true, $fut['periodo']['vazio']);
    $a = $linhaDe($fut, 1);
    igual(0, $a['total']);
    verdade($a['ultima_atividade'] !== null, 'a última atividade continua sendo a de verdade');
});
t('atividade: período que o filtro de equipe restringe mostra só aquela equipe', function () use ($svc) {
    $r = $svc->resumo(...[...['custom', '2026-11-01', '2026-11-10'], 3]);
    igual([3], array_column($r['equipes'], 'equipe_id'));
});
t('atividade: registros próprios do líder como closer (referência) vêm da Produtividade', function () use ($svc, $linhaDe) {
    $a = $linhaDe($svc->resumo('hoje'), 1);
    igual(['ligacoes' => 2, 'followups' => 0, 'leads_quentes' => 0, 'contratos' => 1], $a['proprios']);
});

// ═══ Última atividade, alerta e acesso ═════════════════════════════════════════════════════════
t('atividade: última atividade e dias; Ana atuou hoje (sem alerta)', function () use ($svc, $linhaDe, $NOV) {
    $a = $linhaDe($svc->resumo(...$NOV), 1);
    igual('2026-11-10 09:30:00', $a['ultima_atividade']);
    igual(0, $a['dias_sem_atividade']);
    igual(null, $a['alerta']);
});
t('atividade: alerta "sem atividade há X dias" só passa do limite (3 dias não alerta, 5 dias alerta) e "nunca" quando não há registro', function () use ($svc, $linhaDe, $NOV) {
    $r = $svc->resumo(...$NOV);
    $delta = $linhaDe($r, 4);
    igual(3, $delta['dias_sem_atividade']);
    igual(null, $delta['alerta'], 'exatamente 3 dias não alerta');
    $gama = $linhaDe($r, 3);
    igual(5, $gama['dias_sem_atividade']);
    igual(['k' => 'parado', 't' => 'Sem atividade de liderança há 5 dias'], $gama['alerta']);
    $beta = $linhaDe($r, 2);
    igual(null, $beta['ultima_atividade']);
    igual('nunca', $beta['alerta']['k']);
    igual(3, $r['limite_dias']);
});
t('atividade: aviso de acesso (líder com conta pendente) e equipe sem líder aparece com zeros e sem alerta', function () use ($svc, $linhaDe, $NOV) {
    $r = $svc->resumo(...$NOV);
    igual(true, $linhaDe($r, 1)['lider_acesso']);
    igual(false, $linhaDe($r, 2)['lider_acesso']);
    $eps = $linhaDe($r, 5);
    igual(null, $eps['lider_nome']);
    igual(null, $eps['lider_acesso']);
    igual(0, $eps['total']);
    igual(null, $eps['alerta']);
});
t('atividade: ordem — líderes sem atividade no período primeiro, depois quem mais atuou, e equipe sem líder por último', function () use ($svc, $NOV) {
    igual([2, 1, 4, 3, 5], array_column($svc->resumo(...$NOV)['equipes'], 'equipe_id'));
});
t('atividade: líder que sai (arquivado) deixa a equipe como sem líder e sem atividade atribuída', function () use ($svc, $linhaDe, $db, $NOV) {
    $db->executar('UPDATE vendedores SET deleted_at = NOW() WHERE id = 1');
    $a = $linhaDe($svc->resumo(...$NOV), 1);
    igual(null, $a['lider_nome']);
    igual(0, $a['total']);
    $db->executar('UPDATE vendedores SET deleted_at = NULL WHERE id = 1');
});

// ═══ Detalhe ═══════════════════════════════════════════════════════════════════════════════════
t('detalhe: os últimos registros do líder, do mais novo para o mais antigo, com o closer e sem nada de outra equipe ou de outra pessoa', function () use ($svc) {
    $d = $svc->detalhe(1);
    igual('Ana', $d['lider_nome']);
    igual('Alpha', $d['equipe']['nome']);
    $descricoes = array_column($d['registros'], 'descricao');
    foreach (['R5 do Bruno', 'F3 de outra equipe, mesmo líder'] as $fora) {
        verdade(!in_array($fora, $descricoes, true), "$fora não deveria aparecer");
    }
    igual('V2', $d['registros'][0]['descricao'], 'o mais recente primeiro');
    $quandos = array_column($d['registros'], 'quando');
    $ordenado = $quandos;
    rsort($ordenado);
    igual($ordenado, $quandos);
    $revisao = $d['registros'][0];
    igual('revisao', $revisao['tipo']);
    igual('Bruno', $revisao['closer_nome']);
    $reuniao = array_values(array_filter($d['registros'], static fn(array $r): bool => $r['descricao'] === 'R2 feita'))[0];
    igual('realizada', $reuniao['status']);
    igual(null, $reuniao['closer_nome']);
});
t('detalhe: limite de 20 por padrão e respeita o limite pedido; equipe sem líder devolve lista vazia; equipe inexistente devolve null', function () use ($svc) {
    verdade(count($svc->detalhe(1)['registros']) <= 20);
    igual(3, count($svc->detalhe(1, 3)['registros']));
    $eps = $svc->detalhe(5);
    igual([], $eps['registros']);
    igual(null, $eps['lider_nome']);
    igual(null, $svc->detalhe(999));
});

// ═══ Rotas ═════════════════════════════════════════════════════════════════════════════════════
$app = new Aplicacao($RAIZ, $RAIZ . '/public');
$chamar = static function (string $uri, array $sessao) use ($app, $db): array {
    Session::simular($sessao);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $get);
    $r = $app->responder(req('GET', $uri, [], $get), $db->pdo());
    return [$r->status(), json_decode($r->corpo(), true)];
};
t('rotas: exigem o login do admin (sem login 401; o líder logado no portal também recebe 401)', function () use ($chamar) {
    igual(401, $chamar('/api/admin/atividades-lideres', [])[0]);
    igual(401, $chamar('/api/admin/atividades-lideres', ['closer_id' => 10])[0]);
    igual(401, $chamar('/api/admin/atividades-lideres/1', [])[0]);
});
t('rotas: resumo com o período e o filtro de equipe da URL; detalhe pela rota; equipe inexistente 404', function () use ($chamar) {
    [$s, $j] = $chamar('/api/admin/atividades-lideres?periodo=custom&from=2026-11-01&to=2026-11-10&equipe_id=1', ['admin_logged' => true]);
    igual(200, $s);
    igual(true, $j['success']);
    igual(1, count($j['equipes']));
    igual(9, $j['equipes'][0]['total']);
    [$sd, $jd] = $chamar('/api/admin/atividades-lideres/1', ['admin_logged' => true]);
    igual(200, $sd);
    igual('Ana', $jd['lider_nome']);
    igual(404, $chamar('/api/admin/atividades-lideres/999', ['admin_logged' => true])[0]);
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
exit($falharam ? 1 : 0);
