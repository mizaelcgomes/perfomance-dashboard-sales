<?php
declare(strict_types=1);

/**
 * Testes do novo Portal do Closer (Meu dia, Ranking e Minhas Interações) com DADOS SINTÉTICOS e relógio congelado em 10/11/2026 10:00
 * (America/Sao_Paulo, terça-feira: a semana automática é de 04/11 a 10/11).
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\nucleo\portal-closer.php
 * As tabelas usadas são criadas (vazias) num banco DESCARTÁVEL (nucleo_teste_portal_closer), apagado no fim: nada do banco de verdade é lido ou alterado.
 */

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Services\InteracoesCloserService;
use App\Services\PortalCloserService;

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
$esquema = 'nucleo_teste_portal_closer';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
foreach (['vendedores', 'equipes', 'closer_usuarios', 'contratos_historico', 'contratos_tipos', 'atendimentos', 'acoes_closer', 'metas_periodo', 'semanas_mes', 'app_settings',
          'tipos_beneficio_custom', 'feedbacks', 'cobrancas_cadastro', 'revisoes_atendimento', 'reunioes', 'treinamentos', 'treinamento_presencas', 'interacao_respostas'] as $tabela) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $tabela . '` LIKE `' . DB_NAME . '`.`' . $tabela . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);
$container = new Container();
$container->instancia(Database::class, $db);
$portal = $container->obter(PortalCloserService::class);
$inter = $container->obter(InteracoesCloserService::class);

// cenário: Alpha (Ana 10/sem, Bruno 10/sem, Caio 10/sem; líder = Ana) e Beta (Davi 10/sem). Contas ativas: Bruno (conta 2), Caio (conta 3) e Davi (conta 4).
$db->executar("INSERT INTO equipes (id, nome, cor, lider_id) VALUES (1,'Alpha','#111111',1),(2,'Beta','#222222',NULL)");
$v = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor) VALUES (?,?,?,?,0,?)');
foreach ([[1, 1, 'Ana', 10, '#aaaaaa'], [2, 1, 'Bruno', 10, '#bbbbbb'], [3, 1, 'Caio', 10, '#cccccc'], [4, 2, 'Davi', 10, '#dddddd']] as $l) {
    $v->execute($l);
}
$c = $db->pdo()->prepare("INSERT INTO closer_usuarios (id, vendedor_id, equipe_id, nome, email, senha_hash, status) VALUES (?,?,?,?,?,'x','ativo')");
foreach ([[2, 2, 1, 'Bruno', 'b@t.com'], [3, 3, 1, 'Caio', 'c@t.com'], [4, 4, 2, 'Davi', 'd@t.com']] as $l) {
    $c->execute($l);
}
$venda = $db->pdo()->prepare("INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?,?,?)");
// semana atual (04 a 10/11): Bruno 6 + 4 (bate a meta de 10 no 2º dia lançado: 05/11), Caio 4, Davi 10, Ana 3. Semana anterior (28/10 a 03/11 não está configurada): sem variação.
foreach ([[2, '2026-11-04', 6], [2, '2026-11-05', 4], [3, '2026-11-06', 4], [4, '2026-11-04', 10], [1, '2026-11-07', 3]] as $l) {
    $venda->execute($l);
}
$tipo = $db->pdo()->prepare("INSERT INTO contratos_tipos (vendedor_id, data_registro, tipo, tipo_descricao, quantidade) VALUES (?,?,?,?,?)");
$tipo->execute([2, '2026-11-04', 'bpc_loas', '', 6]);
$tipo->execute([3, '2026-11-06', 'bpc_loas', '', 4]);
$tipo->execute([1, '2026-11-07', 'bpc_loas', '', 3]);

// ═══ Meu dia ═══════════════════════════════════════════════════════════════════════════════════════════
t('Meu dia: só para conta de closer ATIVA (conta pendente, inexistente ou de outro tipo é recusada)', function () use ($portal, $inter, $db) {
    igual(null, $portal->meuDia(99));
    igual(null, $portal->ranking(99, 'semana'));
    igual(null, $inter->listar(99, 'feedback'));
    igual(null, $inter->contagens(99));
    $db->executar("INSERT INTO closer_usuarios (id, vendedor_id, equipe_id, nome, email, senha_hash, status) VALUES (9, 1, 1, 'Ana', 'a@t.com', 'x', 'pendente')");
    igual(null, $portal->meuDia(9), 'conta pendente não entra');
    $db->executar('DELETE FROM closer_usuarios WHERE id=9');
});
t('Meu dia: a meta da semana vem do ranking (vendas, meta, %, faltam) e "bateu a meta em N dias" conta os dias até chegar nela', function () use ($portal) {
    $d = $portal->meuDia(2);
    igual([1, '2026-11-04', '2026-11-10'], [$d['semana']['num'], $d['semana']['start'], $d['semana']['end']], 'a semana automática de novembro/2026 (quarta a terça)');
    $m = $d['meta']['semana'];
    igual([10, 10, 100, 0], [$m['vendas'], $m['meta'], $m['pct'], $m['faltam']]);
    igual(2, $m['bateu_em_dias'], '6 no 1º dia e +4 no 2º: bateu em 2 dias');
    $caio = $portal->meuDia(3)['meta']['semana'];
    igual([4, 10, 40, 6, null], [$caio['vendas'], $caio['meta'], $caio['pct'], $caio['faltam'], $caio['bateu_em_dias']]);
    igual('2026-11-10', $d['hoje']);
});
t('Meu dia: a meta do mês (sem meta mensal cadastrada) é a meta semanal × o nº de semanas do mês', function () use ($portal) {
    $m = $portal->meuDia(2)['meta']['mes'];
    igual([10, 40, 25], [$m['vendas'], $m['meta'], $m['pct']], '4 semanas automáticas em novembro/2026: 10 × 4');
});
t('Meu dia: a posição no Comercial e na equipe segue o ranking (por % da meta; empate decidido pelo desempate do admin) e closer em férias não aparece', function () use ($portal, $db) {
    $r = $portal->meuDia(2)['ranking']['semana'];
    igual([1, 4, 1, 3], [$r['pos_comercial'], $r['total_comercial'], $r['pos_equipe'], $r['total_equipe']], 'Bruno e Davi empatam em 100%; o desempate (6 BPC/LOAS contra 0) põe o Bruno em 1º');
    igual([null, null], [$r['variacao_comercial'], $r['variacao_equipe']], 'a semana anterior não está configurada no Calendário: sem variação');
    $davi = $portal->meuDia(4)['ranking']['semana'];
    igual([2, 1, 1], [$davi['pos_comercial'], $davi['pos_equipe'], $davi['total_equipe']]);
    $caio = $portal->meuDia(3)['ranking']['semana'];
    igual([3, 2], [$caio['pos_comercial'], $caio['pos_equipe']], 'Caio (40%) é o 3º do Comercial e o 2º da equipe (Ana tem 30%)');
    $db->executar("UPDATE vendedores SET suspended_at='2026-11-09 10:00:00' WHERE id=3");
    $c2 = $portal->meuDia(3)['ranking']['semana'];
    igual([null, null], [$c2['pos_comercial'], $c2['pos_equipe']], 'em férias: sem posição');
    $db->executar('UPDATE vendedores SET suspended_at=NULL WHERE id=3');
});
t('Meu dia: a evolução traz os 7 dias e a média da equipe por closer (por dia) com os totais da semana e da anterior', function () use ($portal, $db) {
    $db->executar("INSERT INTO acoes_closer (vendedor_id, tipo, telefone, registrado_em) VALUES (2,'ligacao','11987654321','2026-11-10 08:00:00'),(2,'followup','11987654321','2026-11-10 09:00:00'),(3,'ligacao','11987654322','2026-11-10 09:30:00'),(1,'ligacao','11987654323','2026-11-09 09:30:00'),(2,'ligacao','11987654324','2026-11-02 09:00:00')");
    $e = $portal->meuDia(2)['evolucao'];
    igual(7, count($e['dias']));
    igual(['2026-11-04', '2026-11-10'], [$e['dias'][0]['data'], $e['dias'][6]['data']]);
    igual([2, 1.0], [$e['dias'][6]['total'], $e['dias'][6]['media_equipe']], 'hoje: Bruno 2; a equipe Alpha (3 closers) fez 3 ações: média 1,0');
    igual([0, 0.3], [$e['dias'][5]['total'], $e['dias'][5]['media_equipe']], 'ontem: só a Ana fez 1 ação: média 0,3');
    igual([2, 1, 3], [$e['total'], $e['anterior'], $e['equipe_n']], '2 ações nesta semana, 1 na anterior (02/11), equipe de 3');
});
t('Ranking: minha posição, a equipe com "VOCÊ" na minha linha e o Comercial com no máximo 10 + a minha; o Comercial não traz ids de outros closers', function () use ($portal) {
    $r = $portal->ranking(3, 'semana');
    igual('Semana 1 · 04/11 a 10/11', $r['rotulo']);
    igual(3, count($r['equipe']));
    igual(['Bruno', 'Caio', 'Ana'], array_map(fn($l) => ucfirst(strtolower($l['nome'])), $r['equipe']));
    igual(['Caio'], array_map(fn($l) => $l['nome'], array_values(array_filter($r['equipe'], fn($l) => $l['voce']))));
    igual(4, $r['total_comercial']);
    foreach ($r['comercial'] as $l) {
        verdade(!array_key_exists('id', $l), 'nenhum id no ranking do Comercial');
    }
    igual('Período inválido.', $portal->ranking(3, 'ano')['erro']);
});
t('Ranking: em empate de pontos mostra o critério de desempate do admin ("N× Benefício") e quem ganhou ou perdeu', function () use ($portal) {
    $r = $portal->ranking(2, 'semana');
    $topo = array_values(array_filter($r['comercial'], fn($l) => $l['pct'] === 100));
    igual(2, count($topo), 'Bruno e Davi empatam em 100%');
    verdade(isset($topo[0]['desempate']) && isset($topo[1]['desempate']), 'os dois empatados trazem o critério');
    verdade($topo[0]['desempate_perdeu'] === false && $topo[1]['desempate_perdeu'] === true, 'o de cima ganhou, o de baixo perdeu');
    verdade(strpos($topo[0]['desempate'], '×') !== false, 'formato "N× nome do benefício"');
});

// ═══ Minhas Interações ═══════════════════════════════════════════════════════════════════════════════
t('Interações: feedbacks, cobranças e revisões só do closer da conta, mais recente primeiro, com "novo" (não visto) e a contagem por tipo', function () use ($inter, $db) {
    $agora = '2026-11-10 09:00:00';
    $f = $db->pdo()->prepare('INSERT INTO feedbacks (equipe_id, closer_vendedor_id, lider_vendedor_id, data, assunto, texto, criado_em) VALUES (?,?,?,?,?,?,?)');
    $f->execute([1, 2, 1, '2026-11-05', 'Antigo', 'texto antigo', $agora]);
    $f->execute([1, 2, 1, '2026-11-09', 'Recente', 'texto recente', $agora]);
    $f->execute([1, 3, 1, '2026-11-09', 'Do Caio', 'não é do Bruno', $agora]);
    $db->executar("INSERT INTO cobrancas_cadastro (equipe_id, closer_vendedor_id, lider_vendedor_id, data, observacao, criado_em) VALUES (1,2,1,'2026-11-08','cadastrar João','$agora')");
    $db->executar("INSERT INTO revisoes_atendimento (equipe_id, closer_vendedor_id, telefone_cliente, observacao, lider_vendedor_id, data, criado_em) VALUES (1,2,'11987654321','sem retorno',1,'2026-11-07','$agora')");
    $l = $inter->listar(2, 'feedback');
    igual(['Recente', 'Antigo'], array_column($l['itens'], 'titulo'));
    igual([true, true], [$l['itens'][0]['novo'], $l['pode_responder']]);
    igual('Ana', $l['itens'][0]['lider_nome']);
    igual(['feedback' => 2, 'cobranca' => 1, 'revisao' => 1, 'total' => 4], $inter->contagens(2));
    igual(['feedback' => 1, 'cobranca' => 0, 'revisao' => 0, 'total' => 1], $inter->contagens(3));
    igual('(11) 9****-4321', $inter->listar(2, 'revisao')['itens'][0]['cliente']);
    igual('Cobrança de cadastramento', $inter->listar(2, 'cobranca')['itens'][0]['titulo']);
    igual('Tipo de interação inválido.', $inter->listar(2, 'xx')['erro']);
});
t('Interações: abrir marca como visto SÓ o item (e só se for do closer); o contador cai; item de outro closer não muda', function () use ($inter, $db) {
    $recente = (int) $db->valor("SELECT id FROM feedbacks WHERE assunto='Recente'");
    $doCaio = (int) $db->valor("SELECT id FROM feedbacks WHERE assunto='Do Caio'");
    igual(['marcados' => 0], $inter->marcarVisto(2, 'feedback', $doCaio), 'o Bruno não marca o do Caio');
    verdade($db->valor('SELECT visto_em FROM feedbacks WHERE id=?', [$doCaio]) === null);
    igual(['marcados' => 1], $inter->marcarVisto(2, 'feedback', $recente));
    igual(['marcados' => 0], $inter->marcarVisto(2, 'feedback', $recente), 'já visto: nada muda');
    igual(1, $inter->contagens(2)['feedback']);
    igual(false, $inter->listar(2, 'feedback')['itens'][0]['novo']);
    igual(['marcados' => 0], $inter->marcarVisto(2, 'reuniao', 1), 'reunião não tem "visto"');
});
t('Interações: responder grava a resposta (até 500 letras), marca como visto e só vale para registro DO closer; texto vazio e tipo errado são recusados', function () use ($inter, $db) {
    $antigo = (int) $db->valor("SELECT id FROM feedbacks WHERE assunto='Antigo'");
    $doCaio = (int) $db->valor("SELECT id FROM feedbacks WHERE assunto='Do Caio'");
    igual('Escreva a resposta antes de enviar.', $inter->responder(2, 'feedback', $antigo, "  \x01 ")['message']);
    igual('Interação não encontrada.', $inter->responder(2, 'feedback', $doCaio, 'oi')['message']);
    igual('Só dá para responder feedbacks, cobranças e revisões.', $inter->responder(2, 'reuniao', 1, 'oi')['message']);
    $r = $inter->responder(2, 'feedback', $antigo, '  Obrigado! ' . str_repeat('x', 600));
    verdade($r['success'] && Texto_tamanho($r['resposta']['texto']) === 500, 'limitada a 500 letras');
    $item = $inter->listar(2, 'feedback')['itens'][1];
    igual([1, false], [count($item['respostas']), $item['novo']], 'a resposta aparece no item e quem responde, leu');
    igual(0, count($inter->listar(3, 'feedback')['itens'][0]['respostas']), 'a resposta não aparece para outro closer');
});
t('Interações: reuniões e treinamentos são da EQUIPE do closer (só leitura): a reunião da equipe, o treinamento geral e a presença dele', function () use ($inter, $db) {
    $db->executar("INSERT INTO reunioes (equipe_id, titulo, data_hora_marcada, status, criado_por, criado_em) VALUES (1,'Alinhamento','2026-11-09 09:00:00','realizada',1,'2026-11-01 09:00:00'),(2,'Da Beta','2026-11-09 09:00:00','realizada',4,'2026-11-01 09:00:00')");
    $db->executar("INSERT INTO treinamentos (equipe_id, data_hora, assunto, criado_por, criado_em) VALUES (1,'2026-11-06 14:00:00','Fechamento',1,'2026-11-01 09:00:00'),(NULL,'2026-11-12 10:00:00','Geral (todas as equipes)',1,'2026-11-01 09:00:00'),(2,'2026-11-06 14:00:00','Da Beta',4,'2026-11-01 09:00:00')");
    $db->executar("INSERT INTO treinamento_presencas (treinamento_id, vendedor_id) SELECT id, 2 FROM treinamentos WHERE assunto='Fechamento'");
    $r = $inter->listar(2, 'reuniao');
    igual(['Alinhamento'], array_column($r['itens'], 'titulo'));
    igual([false, 'realizada'], [$r['pode_responder'], $r['itens'][0]['status']]);
    $t = $inter->listar(2, 'treinamento');
    igual(['Geral (todas as equipes)', 'Fechamento'], array_column($t['itens'], 'titulo'));
    igual(['agendado', 'realizado'], array_column($t['itens'], 'status'));
    igual([false, true], array_column($t['itens'], 'presente'));
    igual('Davi', $inter->listar(4, 'reuniao')['itens'][0]['lider_nome'], 'a reunião da Beta só aparece para a Beta');
});
t('Interações: "recentes" (cartão do Meu dia) junta feedbacks, cobranças e revisões, as 3 mais novas', function () use ($inter) {
    $r = $inter->listar(2, 'recentes');
    igual(3, count($r['itens']));
    igual(['2026-11-09', '2026-11-08', '2026-11-07'], array_column($r['itens'], 'data'));
});

function Texto_tamanho(string $s): int
{
    return \App\Services\Texto::tamanho($s);
}

echo "\n" . $passaram . ' passaram, ' . count($falharam) . " falharam\n";
exit(count($falharam) > 0 ? 1 : 0);
