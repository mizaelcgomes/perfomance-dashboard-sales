<?php
declare(strict_types=1);

/**
 * Testes do módulo do líder, fase L3 (registros da liderança), num banco DESCARTÁVEL (nucleo_teste_lider_reg), apagado no fim.
 * Cobre: as regras de cada entidade (reuniões, feedbacks, cobranças, revisões, treinamentos), o isolamento entre equipes, o "Meus feedbacks"
 * do closer e as rotas reais (login, liderança, CSRF do portal, equipe da URL ignorada, ausência de exclusão).
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\lider\registros.php
 */

use App\Core\Aplicacao;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Core\Session;
use App\Services\CobrancaCadastroService;
use App\Services\FeedbackService;
use App\Services\MeusFeedbacksService;
use App\Services\ReuniaoService;
use App\Services\RevisaoAtendimentoService;
use App\Services\Telefone;
use App\Services\TreinamentoService;

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
$esquema = 'nucleo_teste_lider_reg';
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
// Alpha (equipe 1, líder Ana): Ana, Bruno, Bia e Zé (desligado). Beta (equipe 2, líder Caio): Caio e Davi.
$db->executar("INSERT INTO equipes (id, nome, cor, lider_id) VALUES (1, 'Alpha', '#111111', 1), (2, 'Beta', '#222222', 3)");
$vend = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor, deleted_at) VALUES (?,?,?,?,?,?,?)');
foreach ([[1, 1, 'Ana', 10, 40, '#abcdef', null], [2, 1, 'Bruno', 5, 20, '#aaaaaa', null], [6, 1, 'Bia', 5, 20, '#bbbbbb', null], [7, 1, 'Ze', 5, 20, '#cccccc', '2026-10-01 00:00:00'],
          [3, 2, 'Caio', 8, 30, '#123456', null], [4, 2, 'Davi', 6, 24, '#0a0b0c', null]] as $l) {
    $vend->execute($l);
}
$conta = $db->pdo()->prepare('INSERT INTO closer_usuarios (id, vendedor_id, equipe_id, nome, email, senha_hash, status) VALUES (?,?,?,?,?,?,?)');
foreach ([[10, 1, 1, 'Ana', 'ana@t.com', 'x', 'ativo'], [11, 2, 1, 'Bruno', 'bruno@t.com', 'x', 'ativo'], [12, 3, 2, 'Caio', 'caio@t.com', 'x', 'ativo'],
          [13, 4, 2, 'Davi', 'davi@t.com', 'x', 'ativo'], [14, 6, 1, 'Bia', 'bia@t.com', 'x', 'pendente']] as $l) {
    $conta->execute($l);
}
// as colunas da L3 existem na base de teste (vêm do banco local, que já rodou a migração)
foreach (['reunioes', 'feedbacks', 'cobrancas_cadastro', 'revisoes_atendimento', 'treinamentos', 'treinamento_presencas'] as $tabela) {
    verdade((int) $db->valor('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$tabela]) === 1, "tabela $tabela na base de teste");
}

$container = new Container();
$container->instancia(Database::class, $db);
$reunioes = $container->obter(ReuniaoService::class);
$feedbacks = $container->obter(FeedbackService::class);
$cobrancas = $container->obter(CobrancaCadastroService::class);
$revisoes = $container->obter(RevisaoAtendimentoService::class);
$treinos = $container->obter(TreinamentoService::class);
$meus = $container->obter(MeusFeedbacksService::class);
$ANA = 1;   // vendedor líder da equipe 1
$CAIO = 3;  // vendedor líder da equipe 2

// ═══ Telefone ═══════════════════════════════════════════════════════════════════════════════════
t('telefone: mesmas regras do portal (dígitos, +55, DDD, celular com 9, fixo, dígitos repetidos)', function () {
    igual('11987654321', Telefone::digitos('+55 (11) 98765-4321'));
    verdade(Telefone::valido('11987654321'));
    verdade(Telefone::valido('1133334444'));
    foreach (['123', '11887654321', '11033334444', '11999999999', '00987654321', '119876543210'] as $ruim) {
        verdade(!Telefone::valido(Telefone::digitos($ruim)), "$ruim deveria ser inválido");
    }
    igual('(11) 98765-4321', Telefone::formatar('11987654321'));
    igual('(11) 3333-4444', Telefone::formatar('1133334444'));
});

// ═══ Reuniões ═══════════════════════════════════════════════════════════════════════════════════
t('reunião: marcar com título e horário; entra como agendada', function () use ($reunioes, $ANA) {
    $r = $reunioes->criar(1, $ANA, '  Alinhamento semanal ', '2026-11-12 09:30');
    verdade($r->sucesso());
    $i = $r->dados()['item'];
    igual('Alinhamento semanal', $i['titulo']);
    igual('2026-11-12 09:30:00', $i['data_hora_marcada']);
    igual('agendada', $i['status']);
    igual(null, $i['data_hora_realizada']);
});
t('reunião: recusa título vazio ou longo, data inválida e ano absurdo, com a mensagem do campo', function () use ($reunioes, $ANA) {
    foreach ([['', '2026-11-12 09:30', 'titulo'], [str_repeat('a', 121), '2026-11-12 09:30', 'titulo'], ['X', '2026-13-40 09:30', 'data_hora_marcada'],
              ['X', 'amanhã', 'data_hora_marcada'], ['X', '2026-11-12 25:00', 'data_hora_marcada'], ['X', '2099-01-01 09:00', 'data_hora_marcada']] as [$t, $d, $campo]) {
        $r = $reunioes->criar(1, $ANA, $t, $d);
        verdade(!$r->sucesso(), "deveria recusar ($t, $d)");
        verdade(isset($r->erros()[$campo]), "erro no campo $campo");
    }
});
t('reunião: realizar sem horário vale agora; só reunião agendada pode ser realizada', function () use ($reunioes, $ANA) {
    $id = $reunioes->criar(1, $ANA, 'Hoje cedo', '2026-11-10 09:00')->dados()['item']['id'];
    $r = $reunioes->realizar(1, $id, null);
    verdade($r->sucesso());
    igual('realizada', $r->dados()['item']['status']);
    igual('2026-11-10 10:00:00', $r->dados()['item']['data_hora_realizada']);
    verdade(!$reunioes->realizar(1, $id, null)->sucesso(), 'já realizada');
    verdade(!$reunioes->cancelar(1, $id)->sucesso(), 'realizada não cancela');
});
t('reunião: o horário realizado não pode ser antes do marcado nem no futuro', function () use ($reunioes, $ANA) {
    $id = $reunioes->criar(1, $ANA, 'Cedo demais', '2026-11-10 09:00')->dados()['item']['id'];
    $antes = $reunioes->realizar(1, $id, '2026-11-10 08:59');
    verdade(!$antes->sucesso());
    verdade(isset($antes->erros()['data_hora_realizada']));
    verdade(!$reunioes->realizar(1, $id, '2026-11-10 10:30')->sucesso(), 'futuro');
    verdade(!$reunioes->realizar(1, $id, 'lixo')->sucesso(), 'inválido');
    verdade($reunioes->realizar(1, $id, '2026-11-10 09:45')->sucesso(), 'horário certo passa');
});
t('reunião: agendada para amanhã não pode ser realizada hoje sem horário', function () use ($reunioes, $ANA) {
    $id = $reunioes->criar(1, $ANA, 'Amanhã', '2026-11-11 09:00')->dados()['item']['id'];
    verdade(!$reunioes->realizar(1, $id, null)->sucesso());
});
t('reunião: cancelar só agendada; cancelada não muda mais', function () use ($reunioes, $ANA) {
    $id = $reunioes->criar(1, $ANA, 'Cancelar', '2026-11-15 14:00')->dados()['item']['id'];
    igual('cancelada', $reunioes->cancelar(1, $id)->dados()['item']['status']);
    verdade(!$reunioes->cancelar(1, $id)->sucesso());
    verdade(!$reunioes->realizar(1, $id, null)->sucesso());
    verdade(!$reunioes->editar(1, $id, 'Novo', null, null)->sucesso());
});
t('reunião: editar título e horário enquanto agendada; horário marcado trava depois de realizada; corrige o realizado', function () use ($reunioes, $ANA) {
    $id = $reunioes->criar(1, $ANA, 'Original', '2026-11-13 10:00')->dados()['item']['id'];
    $e = $reunioes->editar(1, $id, 'Reagendada', '2026-11-14 11:00', null);
    verdade($e->sucesso());
    igual('Reagendada', $e->dados()['item']['titulo']);
    igual('2026-11-14 11:00:00', $e->dados()['item']['data_hora_marcada']);
    verdade(!$reunioes->editar(1, $id, null, null, '2026-11-10 09:00')->sucesso(), 'agendada não tem horário realizado');
    $id2 = $reunioes->criar(1, $ANA, 'Feita', '2026-11-09 10:00')->dados()['item']['id'];
    verdade($reunioes->realizar(1, $id2, '2026-11-09 10:20')->sucesso());
    verdade(!$reunioes->editar(1, $id2, null, '2026-11-09 12:00', null)->sucesso(), 'marcado travado');
    $c = $reunioes->editar(1, $id2, 'Feita (ata)', null, '2026-11-09 10:40');
    verdade($c->sucesso());
    igual('2026-11-09 10:40:00', $c->dados()['item']['data_hora_realizada']);
    verdade(!$reunioes->editar(1, $id2, null, null, '2026-11-09 09:00')->sucesso(), 'realizado antes do marcado');
});
t('reunião: a equipe 2 não vê, não edita, não realiza nem cancela a reunião da equipe 1 (e vice-versa)', function () use ($reunioes, $ANA, $CAIO) {
    $da1 = $reunioes->criar(1, $ANA, 'Só da Alpha', '2026-11-16 09:00')->dados()['item']['id'];
    $da2 = $reunioes->criar(2, $CAIO, 'Só da Beta', '2026-11-16 09:00')->dados()['item']['id'];
    verdade(!in_array($da1, array_column($reunioes->listar(2), 'id'), true));
    verdade(!in_array($da2, array_column($reunioes->listar(1), 'id'), true));
    foreach ([$reunioes->editar(2, $da1, 'Invadida', null, null), $reunioes->realizar(2, $da1, null), $reunioes->cancelar(2, $da1)] as $r) {
        verdade(!$r->sucesso());
        verdade(strpos($r->mensagem(), 'não encontrada') !== false);
    }
    igual('Só da Alpha', $reunioes->listar(1)[array_search($da1, array_column($reunioes->listar(1), 'id'), true)]['titulo'], 'nada mudou');
});

// ═══ Feedbacks ══════════════════════════════════════════════════════════════════════════════════
t('feedback: criar para um closer da equipe; data padrão é hoje; assunto e texto limpos', function () use ($feedbacks, $ANA) {
    $r = $feedbacks->criar(1, $ANA, 2, ' Ritmo de ligações ', "Texto do feedback\ncom duas linhas", null);
    verdade($r->sucesso());
    $i = $r->dados()['item'];
    igual('Ritmo de ligações', $i['assunto']);
    igual("Texto do feedback\ncom duas linhas", $i['texto']);
    igual('2026-11-10', $i['data']);
    igual('Bruno', $i['closer_nome']);
    igual(false, $i['visto']);
});
t('feedback: closer de outra equipe, o próprio líder, desligado, inexistente ou vazio são recusados (mesmo com o id certo no corpo)', function () use ($feedbacks, $ANA, $db) {
    foreach ([[3, 'closer da equipe 2'], [$ANA, 'o próprio líder'], [7, 'desligado'], [99, 'inexistente'], ['', 'vazio'], ['abc', 'texto']] as [$closer, $motivo]) {
        $r = $feedbacks->criar(1, $ANA, $closer, 'Assunto', 'Texto', null);
        verdade(!$r->sucesso(), "deveria recusar: $motivo");
        verdade(isset($r->erros()['closer_id']), "erro no closer ($motivo)");
    }
    igual(0, (int) $db->valor("SELECT COUNT(*) FROM feedbacks WHERE assunto = 'Assunto'"), 'nada foi gravado');
});
t('feedback: assunto e texto obrigatórios e com limite; data inválida, futura ou antiga demais recusadas', function () use ($feedbacks, $ANA) {
    foreach ([['', 'T', null, 'assunto'], [str_repeat('a', 121), 'T', null, 'assunto'], ['A', '', null, 'texto'], ['A', str_repeat('t', 2001), null, 'texto'],
              ['A', 'T', '2026-11-11', 'data'], ['A', 'T', '2026-02-30', 'data'], ['A', 'T', '2025-01-01', 'data']] as [$a, $tx, $d, $campo]) {
        $r = $feedbacks->criar(1, $ANA, 2, $a, $tx, $d);
        verdade(!$r->sucesso(), "deveria recusar ($campo)");
        verdade(isset($r->erros()[$campo]), "erro no campo $campo");
    }
    verdade($feedbacks->criar(1, $ANA, 2, 'A', 'T', '2026-11-09')->sucesso(), 'ontem passa');
    verdade($feedbacks->criar(1, $ANA, 2, 'A', 'T', '2026-11-10')->sucesso(), 'hoje passa');
});
t('feedback: editar muda só assunto e texto (data e closer ficam) e o closer volta a ver como novo/atualizado', function () use ($feedbacks, $ANA, $db) {
    $id = $feedbacks->criar(1, $ANA, 2, 'Antigo', 'Texto antigo', '2026-11-05')->dados()['item']['id'];
    $db->executar("UPDATE feedbacks SET visto_em = '2026-11-06 08:00:00' WHERE id = ?", [$id]);
    $e = $feedbacks->editar(1, $id, 'Novo assunto', 'Texto novo');
    verdade($e->sucesso());
    $i = $e->dados()['item'];
    igual('Novo assunto', $i['assunto']);
    igual('2026-11-05', $i['data'], 'a data original fica');
    igual(2, $i['closer_id']);
    igual(false, $i['visto'], 'a edição limpa o visto');
    verdade($i['atualizado_em'] !== null);
    verdade(!$feedbacks->editar(1, $id, '', 'T')->sucesso());
});
t('feedback: a equipe 2 não edita nem vê o feedback da equipe 1', function () use ($feedbacks, $ANA) {
    $id = $feedbacks->criar(1, $ANA, 2, 'Reservado', 'Só da Alpha', null)->dados()['item']['id'];
    $r = $feedbacks->editar(2, $id, 'Invadido', 'X');
    verdade(!$r->sucesso());
    verdade(strpos($r->mensagem(), 'não encontrado') !== false);
    verdade(!in_array($id, array_column($feedbacks->listar(2), 'id'), true));
});

// ═══ Cobranças de cadastramento ═════════════════════════════════════════════════════════════════
t('cobrança: registrar com ou sem observação para closer da equipe; closer de fora e o próprio líder recusados', function () use ($cobrancas, $ANA) {
    $a = $cobrancas->criar(1, $ANA, 2, 'Cadastrar o contrato do Sr. João', null);
    verdade($a->sucesso());
    igual('Cadastrar o contrato do Sr. João', $a->dados()['item']['observacao']);
    $b = $cobrancas->criar(1, $ANA, 6, '', '2026-11-09');
    verdade($b->sucesso());
    igual('', $b->dados()['item']['observacao']);
    foreach ([3, $ANA, 7] as $ruim) {
        verdade(!$cobrancas->criar(1, $ANA, $ruim, 'X', null)->sucesso(), "closer $ruim");
    }
    verdade(!$cobrancas->criar(1, $ANA, 2, str_repeat('o', 256), null)->sucesso(), 'observação longa');
});
t('cobrança: editar só a observação (data e closer ficam); da outra equipe não é encontrada', function () use ($cobrancas, $ANA) {
    $id = $cobrancas->criar(1, $ANA, 2, 'Primeira', '2026-11-04')->dados()['item']['id'];
    $e = $cobrancas->editar(1, $id, 'Segunda');
    verdade($e->sucesso());
    igual('Segunda', $e->dados()['item']['observacao']);
    igual('2026-11-04', $e->dados()['item']['data']);
    verdade(!$cobrancas->editar(2, $id, 'Invadida')->sucesso());
});

// ═══ Revisões de atendimento ════════════════════════════════════════════════════════════════════
t('revisão: telefone do cliente validado com as regras do portal e guardado só com dígitos', function () use ($revisoes, $ANA) {
    $r = $revisoes->criar(1, $ANA, 2, '+55 (11) 98765-4321', 'Cliente pediu retorno', null);
    verdade($r->sucesso());
    igual('11987654321', $r->dados()['item']['telefone']);
    igual('(11) 98765-4321', $r->dados()['item']['telefone_fmt']);
    foreach (['123', '11999999999', '(11) 08765-4321', ''] as $ruim) {
        $x = $revisoes->criar(1, $ANA, 2, $ruim, 'X', null);
        verdade(!$x->sucesso(), "deveria recusar o telefone '$ruim'");
        verdade(isset($x->erros()['telefone']));
    }
    verdade(!$revisoes->criar(1, $ANA, 3, '11987654321', 'X', null)->sucesso(), 'closer de outra equipe');
});
t('revisão: editar telefone e observação; da outra equipe não é encontrada', function () use ($revisoes, $ANA) {
    $id = $revisoes->criar(1, $ANA, 2, '11987654321', 'A', '2026-11-03')->dados()['item']['id'];
    $e = $revisoes->editar(1, $id, '(21) 3333-4444', 'B');
    verdade($e->sucesso());
    igual('2133334444', $e->dados()['item']['telefone']);
    igual('2026-11-03', $e->dados()['item']['data']);
    verdade(!$revisoes->editar(1, $id, '123', 'B')->sucesso());
    verdade(!$revisoes->editar(2, $id, '11987654321', 'Invadida')->sucesso());
});

// ═══ Treinamentos e presença ════════════════════════════════════════════════════════════════════
t('treinamento: criar e editar o da equipe; o geral (admin) aparece mas o líder não o edita', function () use ($treinos, $ANA, $db) {
    $r = $treinos->criar(1, $ANA, '2026-11-18T14:00', ' Vendas por telefone ');
    verdade($r->sucesso());
    $id = $r->dados()['item']['id'];
    igual('Vendas por telefone', $r->dados()['item']['assunto']);
    igual(true, $r->dados()['item']['editavel']);
    verdade($treinos->editar(1, $id, '2026-11-19 15:00', 'Vendas por telefone II')->sucesso());
    verdade(!$treinos->criar(1, $ANA, '', 'X')->sucesso(), 'data vazia');
    verdade(!$treinos->criar(1, $ANA, '2026-11-18 14:00', '')->sucesso(), 'assunto vazio');
    $db->executar("INSERT INTO treinamentos (id, equipe_id, data_hora, assunto, criado_por, criado_em) VALUES (900, NULL, '2026-11-20 09:00:00', 'Geral', 999, '2026-11-01 09:00:00')");
    $geral = array_values(array_filter($treinos->listar(1), static fn(array $t): bool => $t['id'] === 900))[0];
    igual(true, $geral['geral']);
    igual(false, $geral['editavel']);
    $e = $treinos->editar(1, 900, '2026-11-21 09:00', 'Tomado');
    verdade(!$e->sucesso());
    verdade(strpos($e->mensagem(), 'administrador') !== false);
});
t('treinamento: o da equipe 2 não aparece nem é editável pela equipe 1', function () use ($treinos, $CAIO) {
    $id = $treinos->criar(2, $CAIO, '2026-11-18 14:00', 'Só da Beta')->dados()['item']['id'];
    verdade(!in_array($id, array_column($treinos->listar(1), 'id'), true));
    verdade(!$treinos->editar(1, $id, '2026-11-18 14:00', 'Invadido')->sucesso());
    verdade(!$treinos->definirPresencas(1, $id, [2])->sucesso());
});
t('treinamento: presença só de closers da equipe; um id de fora recusa a lista inteira sem gravar nada', function () use ($treinos, $ANA, $db) {
    $id = $treinos->criar(1, $ANA, '2026-11-18 16:00', 'Presença')->dados()['item']['id'];
    $ok = $treinos->definirPresencas(1, $id, [1, 2, '6']);
    verdade($ok->sucesso());
    igual([1, 2, 6], $ok->dados()['item']['presentes']);
    $ruim = $treinos->definirPresencas(1, $id, [2, 3]);
    verdade(!$ruim->sucesso());
    igual([1, 2, 6], array_values(array_filter($treinos->listar(1), static fn(array $t): bool => $t['id'] === $id))[0]['presentes'], 'a lista antiga continua');
    verdade(!$treinos->definirPresencas(1, $id, [7])->sucesso(), 'desligado não é da equipe');
    verdade(!$treinos->definirPresencas(1, $id, 'texto')->sucesso());
    $troca = $treinos->definirPresencas(1, $id, [2]);
    igual([2], $troca->dados()['item']['presentes'], 'trocar a lista substitui');
    igual(0, (int) $db->valor('SELECT COUNT(*) FROM treinamento_presencas WHERE treinamento_id = ? AND vendedor_id = 1', [$id]));
});
t('treinamento geral: cada líder marca só a presença da própria equipe; a das outras não é tocada nem mostrada', function () use ($treinos, $db) {
    $db->executar('DELETE FROM treinamento_presencas WHERE treinamento_id = 900');
    verdade($treinos->definirPresencas(1, 900, [1, 2])->sucesso());
    verdade($treinos->definirPresencas(2, 900, [3])->sucesso());
    $da1 = array_values(array_filter($treinos->listar(1), static fn(array $t): bool => $t['id'] === 900))[0];
    $da2 = array_values(array_filter($treinos->listar(2), static fn(array $t): bool => $t['id'] === 900))[0];
    igual([1, 2], $da1['presentes']);
    igual([3], $da2['presentes']);
    verdade($treinos->definirPresencas(1, 900, [])->sucesso());
    $depois2 = array_values(array_filter($treinos->listar(2), static fn(array $t): bool => $t['id'] === 900))[0];
    igual([3], $depois2['presentes'], 'limpar a equipe 1 não apaga a presença da equipe 2');
});

// ═══ Meus feedbacks (o lado do closer) ═══════════════════════════════════════════════════════════
t('meus feedbacks: o closer vê só o que recebeu (feedbacks e cobranças), mais recente primeiro, com os novos contados', function () use ($meus, $feedbacks, $cobrancas, $ANA, $db) {
    $db->executar('DELETE FROM feedbacks');
    $db->executar('DELETE FROM cobrancas_cadastro');
    $feedbacks->criar(1, $ANA, 2, 'Para o Bruno', 'Texto B', null);
    $feedbacks->criar(1, $ANA, 6, 'Para a Bia', 'Texto Bia', null);
    $cobrancas->criar(1, $ANA, 2, 'Cadastre o contrato', null);
    $lista = $meus->listar(11);
    verdade($lista !== null);
    igual(2, count($lista['itens']));
    igual(2, $lista['novos']);
    $tipos = array_column($lista['itens'], 'tipo');
    sort($tipos);
    igual(['cobranca', 'feedback'], $tipos);
    foreach ($lista['itens'] as $i) {
        verdade(strpos(json_encode($i), 'Para a Bia') === false && strpos(json_encode($i), 'Texto Bia') === false, 'nada da Bia');
        igual('Ana', $i['lider_nome']);
        igual(true, $i['novo']);
    }
});
t('meus feedbacks: conta pendente, sem vínculo ou inexistente não lê nada (null); outro closer não vê o do Bruno', function () use ($meus) {
    igual(null, $meus->listar(14), 'pendente');
    igual(null, $meus->listar(999), 'inexistente');
    igual(0, $meus->contarNovos(14));
    $davi = $meus->listar(13);
    igual([], $davi['itens']);
    igual(0, $davi['novos']);
});
t('meus feedbacks: abrir a seção marca como visto; editar o registro faz voltar como novo e atualizado', function () use ($meus, $feedbacks, $db) {
    igual(2, $meus->contarNovos(11));
    igual(2, $meus->marcarVistos(11));
    igual(0, $meus->contarNovos(11));
    igual(0, $meus->marcarVistos(11), 'de novo não muda nada');
    $id = (int) $db->valor("SELECT id FROM feedbacks WHERE assunto = 'Para o Bruno'");
    $feedbacks->editar(1, $id, 'Para o Bruno (revisado)', 'Texto B2');
    $l = $meus->listar(11);
    igual(1, $l['novos']);
    $novo = array_values(array_filter($l['itens'], static fn(array $i): bool => $i['tipo'] === 'feedback'))[0];
    igual(true, $novo['novo']);
    igual(true, $novo['atualizado']);
    igual('Para o Bruno (revisado)', $novo['titulo']);
    igual(0, $meus->contarNovos(13), 'o Davi não ganha aviso do que é do Bruno');
});

// ═══ As rotas (Aplicacao::responder: rota, middleware, CSRF e controller reais) ═══════════════════
$app = new Aplicacao($RAIZ, $RAIZ . '/public');
$chamar = static function (string $metodo, string $uri, array $sessao, array $corpo = [], ?string $token = null) use ($app, $db): array {
    Session::simular($sessao);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $get);
    $extra = ['CONTENT_TYPE' => 'application/json'] + ($token !== null ? ['HTTP_X_CSRF_TOKEN' => $token] : []);
    $r = $app->responder(req($metodo, $uri, $extra, $get, [], $corpo === [] ? '' : (string) json_encode($corpo)), $db->pdo());
    return [$r->status(), json_decode($r->corpo(), true)];
};
$liderLogado = ['closer_id' => 10, 'cr_csrf' => 'token-do-portal'];
$contaFeedbacks = static fn() => (int) $GLOBALS['db']->valor('SELECT COUNT(*) FROM feedbacks');
$GLOBALS['db'] = $db;

t('rota: líder lista os registros da própria equipe, com os closers para os menus (sem ele mesmo)', function () use ($chamar, $liderLogado) {
    [$s, $j] = $chamar('GET', '/api/lider/registros/feedbacks', $liderLogado);
    igual(200, $s);
    igual('feedbacks', $j['entidade']);
    igual(['Bia', 'Bruno'], array_column($j['closers'], 'nome'), 'só os closers ativos da equipe, sem a Ana (líder) e sem o Zé (desligado)');
    igual('2026-11-10', $j['hoje']);
});
t('rota: sem login 401, closer comum 403, líder com conta pendente 403, admin 401 (não entra pela área do líder)', function () use ($chamar) {
    igual(401, $chamar('GET', '/api/lider/registros/feedbacks', [])[0]);
    igual(403, $chamar('GET', '/api/lider/registros/feedbacks', ['closer_id' => 11])[0]);
    igual(403, $chamar('GET', '/api/lider/registros/feedbacks', ['closer_id' => 14])[0]);
    igual(401, $chamar('GET', '/api/lider/registros/feedbacks', ['admin_logged' => true])[0]);
});
t('rota: escrita sem o token CSRF do portal (ou com token errado) responde 419 e não grava nada', function () use ($chamar, $liderLogado, $contaFeedbacks) {
    $antes = $contaFeedbacks();
    $corpo = ['closer_id' => 2, 'assunto' => 'Sem token', 'texto' => 'Texto'];
    igual(419, $chamar('POST', '/api/lider/registros/feedbacks', $liderLogado, $corpo)[0]);
    [$s, $j] = $chamar('POST', '/api/lider/registros/feedbacks', $liderLogado, $corpo, 'token-errado');
    igual(419, $s);
    igual('csrf', $j['code']);
    igual($antes, $contaFeedbacks());
});
t('rota: com o token certo o líder cria, edita e age; o POST não existe para GET e a resposta traz o item', function () use ($chamar, $liderLogado, $contaFeedbacks) {
    $antes = $contaFeedbacks();
    [$s, $j] = $chamar('POST', '/api/lider/registros/feedbacks', $liderLogado, ['closer_id' => 2, 'assunto' => 'Pela rota', 'texto' => 'Texto'], 'token-do-portal');
    igual(200, $s);
    igual('Pela rota', $j['item']['assunto']);
    igual($antes + 1, $contaFeedbacks());
    $id = $j['item']['id'];
    [$s2, $j2] = $chamar('POST', "/api/lider/registros/feedbacks/$id", $liderLogado, ['assunto' => 'Pela rota (editado)', 'texto' => 'Texto 2'], 'token-do-portal');
    igual(200, $s2);
    igual('Pela rota (editado)', $j2['item']['assunto']);
    [$sr, $jr] = $chamar('POST', '/api/lider/registros/reunioes', $liderLogado, ['titulo' => 'Pela rota', 'data_hora_marcada' => '2026-11-12 09:00'], 'token-do-portal');
    igual(200, $sr);
    [$sa, $ja] = $chamar('POST', '/api/lider/registros/reunioes/' . $jr['item']['id'] . '/cancelar', $liderLogado, [], 'token-do-portal');
    igual(200, $sa);
    igual('cancelada', $ja['item']['status']);
});
t('rota: erro de validação responde 422 com a mensagem e os erros por campo', function () use ($chamar, $liderLogado) {
    [$s, $j] = $chamar('POST', '/api/lider/registros/revisoes', $liderLogado, ['closer_id' => 2, 'telefone' => '123', 'observacao' => 'x'], 'token-do-portal');
    igual(422, $s);
    igual(false, $j['success']);
    verdade(isset($j['errors']['telefone']));
});
t('rota: o líder não escolhe a equipe — equipe_id na URL ou no corpo é ignorado e closer de outra equipe é recusado', function () use ($chamar, $liderLogado, $contaFeedbacks) {
    [$s, $j] = $chamar('GET', '/api/lider/registros/reunioes?equipe_id=2', $liderLogado);
    igual(200, $s);
    verdade(strpos(json_encode($j), 'Só da Beta') === false, 'nada da equipe 2');
    $antes = $contaFeedbacks();
    [$s2, $j2] = $chamar('POST', '/api/lider/registros/feedbacks', $liderLogado, ['equipe_id' => 2, 'closer_id' => 4, 'assunto' => 'Invasão', 'texto' => 'X'], 'token-do-portal');
    igual(422, $s2);
    verdade(isset($j2['errors']['closer_id']));
    igual($antes, $contaFeedbacks(), 'nada gravado');
});
t('rota: editar ou agir sobre registro de OUTRA equipe responde 404, e entidade desconhecida também', function () use ($chamar, $liderLogado, $db, $CAIO) {
    $db->executar("INSERT INTO reunioes (id, equipe_id, titulo, data_hora_marcada, status, criado_por, criado_em) VALUES (800, 2, 'Da Beta', '2026-11-20 09:00:00', 'agendada', 3, '2026-11-10 09:00:00')");
    igual(404, $chamar('POST', '/api/lider/registros/reunioes/800', $liderLogado, ['titulo' => 'Invadida'], 'token-do-portal')[0]);
    igual(404, $chamar('POST', '/api/lider/registros/reunioes/800/cancelar', $liderLogado, [], 'token-do-portal')[0]);
    igual('Da Beta', (string) $db->valor('SELECT titulo FROM reunioes WHERE id = 800'), 'a reunião da Beta não mudou');
    igual('agendada', (string) $db->valor('SELECT status FROM reunioes WHERE id = 800'));
    igual(404, $chamar('GET', '/api/lider/registros/segredos', $liderLogado)[0]);
    igual(404, $chamar('POST', '/api/lider/registros/reunioes/800/destruir', $liderLogado, [], 'token-do-portal')[0]);
});
t('rota: não existe exclusão — DELETE não é aceito e nada some', function () use ($chamar, $liderLogado, $app, $db) {
    $total = (int) $db->valor('SELECT COUNT(*) FROM feedbacks');
    Session::simular($liderLogado);
    $r = $app->responder(req('DELETE', '/api/lider/registros/feedbacks/1', ['HTTP_X_CSRF_TOKEN' => 'token-do-portal']), $db->pdo());
    verdade($r->status() === 404 || $r->status() === 405, 'DELETE recusado (veio ' . $r->status() . ')');
    igual($total, (int) $db->valor('SELECT COUNT(*) FROM feedbacks'));
    $r2 = $app->responder(req('POST', '/api/lider/registros/feedbacks/1/excluir', ['HTTP_X_CSRF_TOKEN' => 'token-do-portal']), $db->pdo());
    verdade($r2->status() === 404, 'não há ação excluir');
});
t('rota /api/closer/feedbacks: o closer da sessão vê o dele; conta pendente 403; sem login 401; "vistos" exige o CSRF', function () use ($chamar, $db) {
    [$s, $j] = $chamar('GET', '/api/closer/feedbacks', ['closer_id' => 11, 'cr_csrf' => 't']);
    igual(200, $s);
    verdade(count($j['itens']) >= 1);
    foreach ($j['itens'] as $i) {
        verdade(strpos(json_encode($i), 'Para a Bia') === false, 'nada de outro closer');
    }
    igual(403, $chamar('GET', '/api/closer/feedbacks', ['closer_id' => 14])[0]);
    igual(401, $chamar('GET', '/api/closer/feedbacks', [])[0]);
    [$sn, $jn] = $chamar('GET', '/api/closer/feedbacks/novos', ['closer_id' => 11]);
    igual(200, $sn);
    verdade($jn['novos'] >= 1);
    igual(419, $chamar('POST', '/api/closer/feedbacks/vistos', ['closer_id' => 11, 'cr_csrf' => 't'])[0], 'sem token');
    [$sv, $jv] = $chamar('POST', '/api/closer/feedbacks/vistos', ['closer_id' => 11, 'cr_csrf' => 't'], [], 't');
    igual(200, $sv);
    verdade($jv['marcados'] >= 1);
    igual(0, $chamar('GET', '/api/closer/feedbacks/novos', ['closer_id' => 11])[1]['novos']);
});
t('migração: as colunas da L3 entram só uma vez, sem tocar nos dados (idempotente), mesmo numa tabela da L1 sem elas', function () use ($db) {
    $m = new App\Services\MigracaoLiderService($db);
    $db->executar('ALTER TABLE feedbacks DROP COLUMN visto_em');
    $db->executar("INSERT INTO feedbacks (equipe_id, closer_vendedor_id, lider_vendedor_id, data, assunto, texto, criado_em) VALUES (1, 2, 1, '2026-11-01', 'Antigo', 'Do tempo da L1', '2026-11-01 09:00:00')");
    $rel = $m->executar();
    igual(['feedbacks.visto_em'], $rel['colunas_adicionadas']);
    igual([], $rel['criadas']);
    igual(1, (int) $db->valor("SELECT COUNT(*) FROM feedbacks WHERE assunto = 'Antigo'"), 'o dado antigo continua');
    igual([], $m->executar()['colunas_adicionadas'], 'de novo não adiciona nada');
});

t('migração ainda não rodada (tabelas não existem): o closer vê "nada recebido"; líder e admin recebem 503 com a instrução; nenhum 500', function () use ($chamar, $db, $liderLogado) {
    foreach (['reunioes', 'feedbacks', 'cobrancas_cadastro', 'revisoes_atendimento', 'treinamentos', 'treinamento_presencas'] as $tabela) {
        $db->executar("DROP TABLE `$tabela`");
    }
    [$s, $j] = $chamar('GET', '/api/closer/feedbacks', ['closer_id' => 11, 'cr_csrf' => 't']);
    igual(200, $s);
    igual(['success' => true, 'itens' => [], 'novos' => 0], $j);
    igual(0, $chamar('GET', '/api/closer/feedbacks/novos', ['closer_id' => 11])[1]['novos']);
    igual(0, $chamar('POST', '/api/closer/feedbacks/vistos', ['closer_id' => 11, 'cr_csrf' => 't'], [], 't')[1]['marcados']);
    foreach ([['GET', '/api/lider/registros/feedbacks', []], ['GET', '/api/lider/registros/reunioes', []], ['POST', '/api/lider/registros/feedbacks', ['closer_id' => 2, 'assunto' => 'a', 'texto' => 'b']]] as [$m, $uri, $corpo]) {
        [$sl, $jl] = $chamar($m, $uri, $liderLogado, $corpo, 'token-do-portal');
        igual(503, $sl, "$m $uri");
        igual('migracao', $jl['code']);
        verdade(strpos($jl['message'], 'tabelas do módulo do líder') !== false, 'a mensagem diz o que fazer');
    }
    [$sa, $ja] = $chamar('GET', '/api/admin/atividades-lideres', ['admin_logged' => true]);
    igual(503, $sa);
    igual('migracao', $ja['code']);
    igual(503, $chamar('GET', '/api/admin/atividades-lideres/1', ['admin_logged' => true])[0]);
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
exit($falharam ? 1 : 0);
