<?php
declare(strict_types=1);

/**
 * Testes do módulo do líder, fase L1 (acesso e dados), num banco DESCARTÁVEL (nucleo_teste_lider), apagado no fim.
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\lider\escopo.php
 * Passa pela rota e pelo middleware reais (Aplicacao::responder), não só pelo serviço. Sem rede.
 */

use App\Core\Aplicacao;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Database;
use App\Core\Relogio;
use App\Core\Session;
use App\Repositories\CobrancaCadastroRepository;
use App\Repositories\FeedbackRepository;
use App\Repositories\ReuniaoRepository;
use App\Repositories\RevisaoAtendimentoRepository;
use App\Repositories\TreinamentoRepository;
use App\Services\LiderEscopoService;
use App\Services\MigracaoLiderService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');
require __DIR__ . '/../nucleo/harness.php';

Relogio::fixar('2026-11-10 10:00:00');

$real = new Database();
$real->testar();
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este teste só roda contra o MySQL local de teste.\n");
    exit(2);
}
$esquema = 'nucleo_teste_lider';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
foreach (['vendedores', 'equipes', 'closer_usuarios'] as $tabela) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $tabela . '` LIKE `' . DB_NAME . '`.`' . $tabela . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);

// ── cenário ───────────────────────────────────────────────────────────────────────────────────────
// equipe 1 (Alpha) é liderada por Ana (vendedor 1); equipe 2 (Beta) por Caio (vendedor 3);
// equipe 3 (Gama) está registrada como liderada pelo vendedor 6, que na verdade é da equipe 2.
$db->executar("INSERT INTO equipes (id, nome, cor, lider_id) VALUES (1, 'Alpha', '#111111', 1), (2, 'Beta', '#222222', 3), (3, 'Gama', '#333333', 6)");
$vend = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor, deleted_at, suspended_at) VALUES (?,?,?,?,?,?,?,?)');
foreach ([
    [1, 1, 'Ana', 10, 0, '#abcdef', null, null],
    [2, 1, 'Bruno', 5, 0, '#aaaaaa', null, null],
    [3, 2, 'Caio', 8, 0, '#123456', null, null],
    [4, 2, 'Davi', 6, 0, '#0a0b0c', null, null],
    [6, 2, 'Fabio', 4, 0, '#bbbbbb', null, null],
] as $linha) {
    $vend->execute($linha);
}
$conta = $db->pdo()->prepare('INSERT INTO closer_usuarios (id, vendedor_id, equipe_id, nome, email, senha_hash, status) VALUES (?,?,?,?,?,?,?)');
foreach ([
    [10, 1, 1, 'Ana', 'ana@teste.com', 'x', 'ativo'],          // líder da equipe 1
    [11, 2, 1, 'Bruno', 'bruno@teste.com', 'x', 'ativo'],      // closer comum
    [12, 3, 2, 'Caio', 'caio@teste.com', 'x', 'pendente'],     // líder da equipe 2, ainda não aprovado
    [13, 4, 2, 'Davi', 'davi@teste.com', 'x', 'rejeitado'],    // revogado
    [14, null, null, 'Sem vínculo', 'sem@teste.com', 'x', 'ativo'], // conta sem closer
    [15, 6, 2, 'Fabio', 'fabio@teste.com', 'x', 'ativo'],      // vendedor da equipe 2, mas líder da equipe 3 (não é dele)
] as $linha) {
    $conta->execute($linha);
}

$escopo = new LiderEscopoService($db);

// ═══ LiderEscopoService: quem é líder, a cada consulta ═══════════════════════════════════════════════
t('LiderEscopoService: conta ativa, do vendedor que é o líder da própria equipe → essa equipe', function () use ($escopo) {
    igual(1, $escopo->equipeDoLider(10));
});
t('LiderEscopoService: closer comum (não é líder) → null', function () use ($escopo) {
    igual(null, $escopo->equipeDoLider(11));
});
t('LiderEscopoService: conta pendente, mesmo sendo o líder cadastrado → null (só entra quem foi aprovado)', function () use ($escopo) {
    igual(null, $escopo->equipeDoLider(12));
});
t('LiderEscopoService: conta revogada (rejeitada) → null', function () use ($escopo) {
    igual(null, $escopo->equipeDoLider(13));
});
t('LiderEscopoService: conta sem vendedor vinculado → null', function () use ($escopo) {
    igual(null, $escopo->equipeDoLider(14));
});
t('LiderEscopoService: vendedor de outra equipe não lidera a equipe que só está registrada no nome dele → null', function () use ($escopo) {
    igual(null, $escopo->equipeDoLider(15));
    igual(null, $escopo->equipeDoLider(0), 'id inválido');
});
t('LiderEscopoService: a liderança vale quando a conta é aprovada (pendente → ativo)', function () use ($escopo, $db) {
    $db->executar("UPDATE closer_usuarios SET status='ativo' WHERE id=12");
    igual(2, $escopo->equipeDoLider(12));
});
t('LiderEscopoService: tirar a liderança derruba o acesso na hora (a conta continua ativa)', function () use ($escopo, $db) {
    $db->executar('UPDATE equipes SET lider_id = 2 WHERE id = 1');
    igual(null, $escopo->equipeDoLider(10));
    igual('ativo', (string) $db->valor("SELECT status FROM closer_usuarios WHERE id = 10"), 'a conta do closer não mudou');
    $db->executar('UPDATE equipes SET lider_id = 1 WHERE id = 1');
    igual(1, $escopo->equipeDoLider(10));
});
t('LiderEscopoService: arquivar o vendedor derruba a liderança; desfazer devolve', function () use ($escopo, $db) {
    $db->executar('UPDATE vendedores SET deleted_at = NOW() WHERE id = 3');
    igual(null, $escopo->equipeDoLider(12));
    $db->executar('UPDATE vendedores SET deleted_at = NULL WHERE id = 3');
    igual(2, $escopo->equipeDoLider(12));
});
t('LiderEscopoService: suspender a conta (revogar) derruba o acesso do líder', function () use ($escopo, $db) {
    $db->executar("UPDATE closer_usuarios SET status='rejeitado' WHERE id = 10");
    igual(null, $escopo->equipeDoLider(10));
    $db->executar("UPDATE closer_usuarios SET status='ativo' WHERE id = 10");
    igual(1, $escopo->equipeDoLider(10));
});
t('LiderEscopoService: suspensão por férias (suspended_at) NÃO tira a liderança (decisão: o líder em férias segue líder)', function () use ($escopo, $db) {
    $db->executar('UPDATE vendedores SET suspended_at = NOW() WHERE id = 1');
    igual(1, $escopo->equipeDoLider(10));
    $db->executar('UPDATE vendedores SET suspended_at = NULL WHERE id = 1');
});

// ═══ Middleware auth:lider e rota de escopo (Aplicacao::responder, a rota e o middleware reais) ════════
$app = new Aplicacao($RAIZ, $RAIZ . '/public');
$chamar = static function (array $sessao, string $uri) use ($app, $db): App\Core\Response {
    Session::simular($sessao);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $get);   // o req() só entende a query se ela vier em $get
    return $app->responder(req('GET', $uri, [], $get), $db->pdo());
};

t('rota /api/lider/escopo sem login: 401 JSON "Não autenticado" (não vaza que a rota existe)', function () use ($chamar) {
    $r = $chamar([], '/api/lider/escopo');
    igual(401, $r->status());
    igual(['success' => false, 'message' => 'Não autenticado'], json_decode($r->corpo(), true));
});
t('rota /api/lider/escopo com sessão de admin (sem conta de closer): 401, o admin não entra pela área do líder', function () use ($chamar) {
    igual(401, $chamar(['admin_logged' => true], '/api/lider/escopo')->status());
});
t('rota /api/lider/escopo com closer comum: 403, sem dizer de qual equipe', function () use ($chamar) {
    $r = $chamar(['closer_id' => 11], '/api/lider/escopo');
    igual(403, $r->status());
    verdade(strpos($r->corpo(), 'equipe_id') === false, 'a resposta não traz equipe');
});
t('rota /api/lider/escopo com líder: 200 e a equipe dele (não a que ele pedir)', function () use ($chamar) {
    $r = $chamar(['closer_id' => 10], '/api/lider/escopo?equipe_id=3');
    igual(200, $r->status());
    igual(['success' => true, 'equipe_id' => 1], json_decode($r->corpo(), true), 'o parâmetro equipe_id da requisição é ignorado');
});
t('rota /api/lider/escopo: o líder perde o acesso na requisição seguinte, com a mesma sessão aberta', function () use ($chamar, $db) {
    igual(200, $chamar(['closer_id' => 10], '/api/lider/escopo')->status());
    $db->executar('UPDATE equipes SET lider_id = 2 WHERE id = 1');
    igual(403, $chamar(['closer_id' => 10], '/api/lider/escopo')->status(), 'mesma sessão, sem novo login');
    $db->executar('UPDATE equipes SET lider_id = 1 WHERE id = 1');
});
t('rota de escopo aceita só GET (POST = 405)', function () use ($app, $db) {
    Session::simular(['closer_id' => 10]);
    igual(405, $app->responder(req('POST', '/api/lider/escopo'), $db->pdo())->status());
});

// ═══ Migração idempotente ══════════════════════════════════════════════════════════════════════════
$migra = new MigracaoLiderService($db);
t('migração: cria as 10 tabelas numa base limpa (6 da L1 + 3 do portal do líder + as respostas do closer)', function () use ($migra, $db) {
    $rel = $migra->executar();
    igual([], $rel['ja_existiam']);
    igual(['reunioes', 'feedbacks', 'cobrancas_cadastro', 'revisoes_atendimento', 'treinamentos', 'treinamento_presencas', 'reuniao_participantes', 'evento_notas', 'interacao_respostas', 'lider_preferencias'], $rel['criadas']);
    igual(10, (int) $db->valor("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('reunioes','feedbacks','cobrancas_cadastro','revisoes_atendimento','treinamentos','treinamento_presencas','reuniao_participantes','evento_notas','lider_preferencias','interacao_respostas')"));
});
t('migração: rodar de novo não cria nem apaga nada e não duplica dados', function () use ($migra, $db) {
    $db->executar("INSERT INTO reunioes (equipe_id, titulo, data_hora_marcada, status, criado_por, criado_em) VALUES (1, 'Antes', '2026-11-11 09:00:00', 'agendada', 1, '2026-11-10 09:00:00')");
    $rel = $migra->executar();
    igual([], $rel['criadas']);
    igual(10, count($rel['ja_existiam']));
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM reunioes'), 'o dado de antes continua lá, uma vez só');
});

// ═══ Repositórios: isolamento entre equipes (toda leitura e escrita pede a equipe) ════════════════════
$reunioes = new ReuniaoRepository($db);
$feedbacks = new FeedbackRepository($db);
$cobrancas = new CobrancaCadastroRepository($db);
$revisoes = new RevisaoAtendimentoRepository($db);
$treinos = new TreinamentoRepository($db);
$agora = '2026-11-10 10:00:00';

t('reuniões: a da equipe 2 não aparece na lista da equipe 1 e não abre por id na equipe 1', function () use ($reunioes, $agora) {
    $id = $reunioes->inserir(2, 'Reunião da Beta', '2026-11-12 10:00:00', 3, $agora);
    igual(false, in_array($id, array_column($reunioes->listar(1), 'id'), true));
    igual(null, $reunioes->buscar($id, 1));
    igual($id, $reunioes->buscar($id, 2)['id']);
    igual(['Reunião da Beta'], array_column($reunioes->listar(2), 'titulo'));
});
t('feedbacks: o da equipe 2 não aparece para a equipe 1; o closer só vê os que recebeu', function () use ($feedbacks, $agora) {
    $id = $feedbacks->inserir(2, 4, 3, '2026-11-10', 'Ritmo', 'Texto do feedback', $agora);
    igual(null, $feedbacks->buscar($id, 1));
    igual($id, $feedbacks->buscar($id, 2)['id']);
    igual([$id], array_column($feedbacks->listarDoCloser(4), 'id'));
    igual([], array_column($feedbacks->listarDoCloser(2), 'id'), 'outro closer não vê');
});
t('cobranças de cadastramento: isoladas por equipe', function () use ($cobrancas, $agora) {
    $id = $cobrancas->inserir(2, 4, 3, '2026-11-10', 'Cadastrar contrato', $agora);
    igual(null, $cobrancas->buscar($id, 1));
    igual($id, $cobrancas->buscar($id, 2)['id']);
});
t('revisões de atendimento: telefone do cliente guardado como dígitos e isolado por equipe', function () use ($revisoes, $agora) {
    $id = $revisoes->inserir(1, 2, '11987654321', 'Cliente pediu retorno', 1, '2026-11-10', $agora);
    igual(null, $revisoes->buscar($id, 2));
    igual('11987654321', $revisoes->buscar($id, 1)['telefone_cliente']);
});
t('treinamentos: o da equipe 2 não aparece para a equipe 1; o geral (admin) aparece para as duas', function () use ($treinos, $agora) {
    $daBeta = $treinos->inserir(2, '2026-11-15 14:00:00', 'Vendas por telefone', 3, $agora);
    $geral = $treinos->inserir(null, '2026-11-16 09:00:00', 'Treinamento geral', 999, $agora);
    igual(null, $treinos->buscar($daBeta, 1));
    igual($geral, $treinos->buscar($geral, 1)['id']);
    igual($geral, $treinos->buscar($geral, 2)['id']);
    igual(false, in_array($daBeta, array_column($treinos->listar(1), 'id'), true));
});
t('presença em treinamento: marcar duas vezes não duplica', function () use ($treinos, $agora) {
    $id = $treinos->inserir(1, '2026-11-17 10:00:00', 'Presença', 1, $agora);
    $treinos->marcarPresenca($id, 2);
    $treinos->marcarPresenca($id, 2);
    igual([2], $treinos->presentes($id));
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
exit($falharam ? 1 : 0);
