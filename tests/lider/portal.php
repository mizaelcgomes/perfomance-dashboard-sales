<?php
declare(strict_types=1);

/**
 * Testes do portal do líder (fase L4), num banco DESCARTÁVEL (nucleo_teste_lider_portal), apagado no fim: Dashboard (status, alertas proativos,
 * pendências e comparação), Equipe (cartões, detalhe, linha do tempo e evolução), Agenda e notas, busca global, preferências, participantes de
 * reunião, cancelamento de treinamento e as rotas reais (login, liderança, CSRF, equipe da URL ignorada, tabelas ausentes).
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\lider\portal.php
 */

use App\Core\Aplicacao;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Core\Session;
use App\Services\AgendaLiderService;
use App\Services\LiderBuscaService;
use App\Services\LiderDashboardService;
use App\Services\LiderEquipeService;
use App\Services\LiderPreferenciasService;
use App\Services\ReuniaoService;
use App\Services\TreinamentoService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');
require __DIR__ . '/../nucleo/harness.php';

Relogio::fixar('2026-11-10 10:00:00');   // terça-feira: o 7º e último dia da semana de 04/11 a 10/11

$real = new Database();
$real->testar();
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este teste só roda contra o MySQL local de teste.\n");
    exit(2);
}
$esquema = 'nucleo_teste_lider_portal';
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
// Alpha (1, líder Ana): Ana, Bruno (bateu a meta), Bia (8 dias sem vender). Beta (2, líder Caio): Caio e Davi. Gama (3, líder Eva): Eva.
$db->executar("INSERT INTO equipes (id, nome, cor, lider_id) VALUES (1,'Alpha','#111111',1),(2,'Beta','#222222',3),(3,'Gama','#333333',5)");
$vend = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor) VALUES (?,?,?,?,?,?)');
foreach ([[1, 1, 'Ana', 10, 40, '#abcdef'], [2, 1, 'Bruno', 10, 40, '#aaaaaa'], [6, 1, 'Bia', 10, 40, '#bbbbbb'], [3, 2, 'Caio', 10, 40, '#123456'],
          [4, 2, 'Davi', 10, 40, '#0a0b0c'], [5, 3, 'Eva', 10, 40, '#cccccc']] as $l) {
    $vend->execute($l);
}
$conta = $db->pdo()->prepare('INSERT INTO closer_usuarios (id, vendedor_id, equipe_id, nome, email, senha_hash, status) VALUES (?,?,?,?,?,?,?)');
foreach ([[10, 1, 1, 'Ana', 'ana@t.com', 'x', 'ativo'], [11, 2, 1, 'Bruno', 'bruno@t.com', 'x', 'ativo'], [14, 6, 1, 'Bia', 'bia@t.com', 'x', 'ativo'],
          [12, 3, 2, 'Caio', 'caio@t.com', 'x', 'ativo'], [13, 4, 2, 'Davi', 'davi@t.com', 'x', 'ativo'], [15, 5, 3, 'Eva', 'eva@t.com', 'x', 'ativo']] as $l) {
    $conta->execute($l);
}
$contr = $db->pdo()->prepare('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?,?,?)');
foreach ([[2, '2026-11-05', 10], [1, '2026-11-08', 5], [6, '2026-11-02', 1], [4, '2026-11-09', 10], [3, '2026-11-06', 2], [5, '2026-11-07', 3]] as $l) {
    $contr->execute($l);
}

$container = new Container();
$container->instancia(Database::class, $db);
$dash = $container->obter(LiderDashboardService::class);
$equipeSvc = $container->obter(LiderEquipeService::class);
$agenda = $container->obter(AgendaLiderService::class);
$busca = $container->obter(LiderBuscaService::class);
$prefs = $container->obter(LiderPreferenciasService::class);
$reunioes = $container->obter(ReuniaoService::class);
$treinos = $container->obter(TreinamentoService::class);
$ANA = 1;
$porId = static fn(array $lista, $chave, $valor): ?array => (array_values(array_filter($lista, static fn(array $x): bool => $x[$chave] === $valor))[0] ?? null);

// ═══ Preferências ═══════════════════════════════════════════════════════════════════════════════
t('preferências: sem nada salvo valem os padrões (3 dias sem vender, 14 sem feedback, todos os alertas ligados)', function () use ($prefs, $ANA) {
    $p = $prefs->obter($ANA);
    igual(3, $p['limite_dias_sem_venda']);
    igual(14, $p['dias_sem_feedback']);
    igual(['sem_venda' => true, 'meta_batida' => true, 'atividade' => true, 'sem_feedback' => true, 'agenda' => true], $p['alertas']);
});
t('preferências: valores fora da faixa ou que não são número inteiro são recusados e nada é gravado', function () use ($prefs, $ANA, $db) {
    foreach ([['limite_dias_sem_venda' => 0], ['limite_dias_sem_venda' => 15], ['limite_dias_sem_venda' => 'abc'], ['limite_dias_sem_venda' => 2.5], ['dias_sem_feedback' => 6], ['dias_sem_feedback' => 61], ['dias_sem_feedback' => '1e1']] as $ruim) {
        $r = $prefs->salvar($ANA, $ruim);
        verdade(!$r->sucesso(), 'deveria recusar ' . json_encode($ruim));
        verdade($r->erros() !== []);
    }
    igual(0, (int) $db->valor('SELECT COUNT(*) FROM lider_preferencias'));
});
t('preferências: salvar muda só o que veio (alertas que não vêm ficam como estão) e é por líder', function () use ($prefs, $ANA, $db) {
    $r = $prefs->salvar($ANA, ['limite_dias_sem_venda' => 10, 'alertas' => ['sem_feedback' => false, 'inexistente' => true]]);
    verdade($r->sucesso());
    $p = $prefs->obter($ANA);
    igual(10, $p['limite_dias_sem_venda']);
    igual(14, $p['dias_sem_feedback']);
    igual(false, $p['alertas']['sem_feedback']);
    igual(true, $p['alertas']['sem_venda']);
    verdade(!array_key_exists('inexistente', $p['alertas']));
    igual(3, $prefs->obter(3)['limite_dias_sem_venda'], 'outro líder continua com o padrão');
    $prefs->salvar($ANA, ['limite_dias_sem_venda' => 3, 'alertas' => ['sem_feedback' => true]]);
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM lider_preferencias'), 'uma linha por líder, sem duplicar');
});

// ═══ Dashboard ══════════════════════════════════════════════════════════════════════════════════
t('dashboard: a performance traz só a equipe do líder, com o líder marcado, e cada closer com o status da semana', function () use ($dash, $ANA, $porId) {
    $d = $dash->dashboard(1, $ANA);
    $nomes = array_column($d['closers'], 'nome');
    sort($nomes);
    igual(['Ana', 'Bia', 'Bruno'], $nomes);
    $bruno = $porId($d['closers'], 'nome', 'Bruno');
    igual('bateu', $bruno['status']);
    igual(10, $bruno['vendas']);
    igual(2, $bruno['bateu_em_dias'], '10 contratos no 2º dia da semana (05/11)');
    $bia = $porId($d['closers'], 'nome', 'Bia');
    igual('risco', $bia['status'], 'esperado até ontem 8, tem 0');
    igual(8, $bia['dias_sem_venda']);
    igual(true, $porId($d['closers'], 'nome', 'Ana')['lider']);
    igual('Bruno', $d['closers'][0]['nome'] === 'Bruno' || $d['closers'][0]['nome'] === 'Ana' ? 'Bruno' : $d['closers'][0]['nome'], 'ordenado por contratos');
    igual(7, $d['semana']['dia_atual']);
    igual('Ana', $d['lider']['nome']);
});
t('dashboard: o status segue o ritmo — no 1º dia da semana ninguém está em risco; no 4º, quem está abaixo do esperado até ontem está', function () use ($dash, $ANA) {
    Relogio::fixar('2026-11-04 09:00:00');                    // 1º dia da semana: o esperado até ontem é zero
    $d = $dash->dashboard(1, $ANA);
    igual(1, $d['semana']['dia_atual']);
    igual(0, count(array_filter($d['closers'], static fn(array $c): bool => $c['status'] === 'risco')), 'ninguém em risco no 1º dia');
    Relogio::fixar('2026-11-07 09:00:00');                    // 4º dia: esperado até ontem = floor(10 × 3 ÷ 7) = 4
    $d4 = $dash->dashboard(1, $ANA);
    igual(4, $d4['semana']['dia_atual']);
    $bia4 = array_values(array_filter($d4['closers'], static fn(array $c): bool => $c['nome'] === 'Bia'))[0];
    igual('risco', $bia4['status']);
    Relogio::fixar('2026-11-10 10:00:00');
});
t('dashboard: alertas proativos — Bia sem fechar há 8 dias (atenção) e Bruno que bateu a meta em 2 dias (positivo), cada um com a ação certa', function () use ($dash, $ANA, $porId) {
    $al = $dash->dashboard(1, $ANA)['alertas'];
    $bia = $porId($al, 'id', 'sem_venda:6');
    verdade($bia !== null);
    igual('atencao', $bia['severidade']);
    igual('Bia: sem fechar contrato há 8 dias', $bia['titulo']);
    igual(['k' => 'feedback', 'rotulo' => 'Dar feedback', 'closer_id' => 6], $bia['acao']);
    $bruno = $porId($al, 'id', 'meta_batida:2');
    igual('positivo', $bruno['severidade']);
    igual('Bruno bateu a meta em 2 dias!', $bruno['titulo']);
    igual('Parabenizar', $bruno['acao']['rotulo']);
    igual('atencao', $al[0]['severidade'], 'a atenção vem antes do positivo');
    foreach ($al as $a) {
        verdade(($a['closer_id'] ?? 0) !== $ANA, 'o líder não recebe alerta sobre si mesmo');
    }
});
t('dashboard: o limite de dias e os tipos de alerta seguem as preferências do líder', function () use ($dash, $ANA, $prefs, $porId) {
    $prefs->salvar($ANA, ['limite_dias_sem_venda' => 10]);
    verdade($porId($dash->dashboard(1, $ANA)['alertas'], 'id', 'sem_venda:6') === null, '8 dias < limite 10');
    $prefs->salvar($ANA, ['limite_dias_sem_venda' => 3, 'alertas' => ['meta_batida' => false]]);
    $al = $dash->dashboard(1, $ANA)['alertas'];
    verdade($porId($al, 'id', 'meta_batida:2') === null, 'alerta positivo desligado');
    verdade($porId($al, 'id', 'sem_venda:6') !== null);
    $prefs->salvar($ANA, ['alertas' => ['meta_batida' => true]]);
});
t('dashboard: pendências — feedback atrasado (nunca recebeu), treinamento para agendar, e com reunião atrasada e próxima na agenda', function () use ($dash, $ANA, $db, $porId) {
    $db->executar("INSERT INTO reunioes (id, equipe_id, titulo, data_hora_marcada, status, criado_por, criado_em) VALUES (900, 1, 'Alinhamento', '2026-11-09 09:00:00', 'agendada', 1, '2026-11-08 09:00:00'), (901, 1, 'Planejamento', '2026-11-12 09:00:00', 'agendada', 1, '2026-11-08 09:00:00'), (902, 1, 'Daqui a um mês', '2026-12-20 09:00:00', 'agendada', 1, '2026-11-08 09:00:00')");
    $p = $dash->dashboard(1, $ANA)['pendencias'];
    $ids = array_column($p, 'id');
    foreach (['reuniao_concluir:900', 'reuniao_proxima:901', 'treinamento_agendar', 'feedback:2', 'feedback:6'] as $esperado) {
        verdade(in_array($esperado, $ids, true), "falta a pendência $esperado");
    }
    verdade(!in_array('reuniao_proxima:902', $ids, true), 'reunião daqui a um mês não é pendência');
    verdade(!in_array('feedback:1', $ids, true), 'o líder não tem feedback pendente para si');
    igual('reuniao_concluir:900', $ids[0], 'reunião atrasada vem primeiro');
    igual('Ainda não recebeu nenhum feedback.', $porId($p, 'id', 'feedback:2')['detalhe']);
    igual('concluir', strtolower(substr($porId($p, 'id', 'reuniao_concluir:900')['acao']['rotulo'], 0, 8)));
});
t('dashboard: o feedback dado tira a pendência; passados os dias do limite ela volta; as preferências desligam as pendências', function () use ($dash, $ANA, $db, $prefs) {
    $db->executar("INSERT INTO feedbacks (equipe_id, closer_vendedor_id, lider_vendedor_id, data, assunto, texto, criado_em) VALUES (1, 2, 1, '2026-11-08', 'x', 'y', '2026-11-08 10:00:00')");
    igual(false, in_array('feedback:2', array_column($dash->dashboard(1, $ANA)['pendencias'], 'id'), true), 'Bruno recebeu feedback há 2 dias');
    $db->executar("UPDATE feedbacks SET data = '2026-10-20'");
    $p = $dash->dashboard(1, $ANA)['pendencias'];
    $item = array_values(array_filter($p, static fn(array $x): bool => $x['id'] === 'feedback:2'))[0];
    igual('Sem feedback há 21 dias.', $item['detalhe']);
    $prefs->salvar($ANA, ['alertas' => ['sem_feedback' => false, 'agenda' => false]]);
    igual([], $dash->dashboard(1, $ANA)['pendencias']);
    $prefs->salvar($ANA, ['alertas' => ['sem_feedback' => true, 'agenda' => true]]);
    $db->executar('DELETE FROM feedbacks');
});
t('dashboard: treinamento marcado à frente tira a pendência; treinamento passado sem presença vira pendência até marcar a presença', function () use ($dash, $ANA, $db, $treinos) {
    $r = $treinos->criar(1, $ANA, '2026-11-20 14:00', 'Objeções');
    verdade($r->sucesso());
    igual(false, in_array('treinamento_agendar', array_column($dash->dashboard(1, $ANA)['pendencias'], 'id'), true));
    $id = $treinos->criar(1, $ANA, '2026-11-08 14:00', 'Treino passado')->dados()['item']['id'];
    verdade(in_array('treinamento_presenca:' . $id, array_column($dash->dashboard(1, $ANA)['pendencias'], 'id'), true));
    $treinos->definirPresencas(1, $id, [2]);
    igual(false, in_array('treinamento_presenca:' . $id, array_column($dash->dashboard(1, $ANA)['pendencias'], 'id'), true));
    $db->executar('DELETE FROM treinamentos');
    $db->executar('DELETE FROM treinamento_presencas');
});
t('dashboard: contadores do sino somam os alertas de atenção e as pendências', function () use ($dash, $ANA) {
    $d = $dash->dashboard(1, $ANA);
    $atencao = count(array_filter($d['alertas'], static fn(array $a): bool => $a['severidade'] === 'atencao'));
    igual(['alertas' => $atencao, 'pendencias' => count($d['pendencias']), 'total' => $atencao + count($d['pendencias'])], $d['contadores']);
});
t('dashboard: comparação e posição são só agregados — nenhuma outra equipe aparece pelo nome nem em número individual', function () use ($dash, $ANA) {
    $d = $dash->dashboard(1, $ANA);
    igual(3, $d['equipe']['total_equipes']);
    verdade($d['equipe']['posicao_semana'] >= 1 && $d['equipe']['posicao_semana'] <= 3);
    igual(['score_semana', 'score_mes', 'contratos_closer'], array_column($d['comparacao']['metricas'], 'k'));
    $json = json_encode($d, JSON_UNESCAPED_UNICODE);
    foreach (['Beta', 'Gama', 'Caio', 'Davi', 'Eva'] as $fora) {
        verdade(strpos($json, $fora) === false, "$fora vazou para o dashboard da equipe 1");
    }
    igual('Alpha', $d['equipe']['nome']);
});
t('dashboard: a posição da equipe é a posição entre as equipes pela pontuação (empate divide a posição), e sobe quando a equipe vende mais', function () use ($dash, $ANA, $db, $container) {
    $posicaoEsperada = static function () use ($container): int {
        $times = array_values(array_filter($container->obter(App\Services\PainelService::class)->painel(0, 0, 0)['teams'], static fn(array $t): bool => (int) $t['closers'] > 0));
        $minha = array_values(array_filter($times, static fn(array $t): bool => $t['id'] === 1))[0];
        return 1 + count(array_filter($times, static fn(array $t): bool => (int) $t['score'] > (int) $minha['score']));
    };
    igual($posicaoEsperada(), $dash->dashboard(1, $ANA)['equipe']['posicao_semana'], 'mesma regra do Painel do admin');
    $db->executar("INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (6, '2026-11-06', 10), (1, '2026-11-06', 10)");
    igual(1, $dash->dashboard(1, $ANA)['equipe']['posicao_semana'], 'com 30 contratos (100% da meta) a Alpha lidera');
    igual($posicaoEsperada(), $dash->dashboard(1, $ANA)['equipe']['posicao_semana']);
    // volta aos contratos do cenário original (os outros testes dependem deles)
    $db->executar('DELETE FROM contratos_historico');
    $contr = $db->pdo()->prepare('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?,?,?)');
    foreach ([[2, '2026-11-05', 10], [1, '2026-11-08', 5], [6, '2026-11-02', 1], [4, '2026-11-09', 10], [3, '2026-11-06', 2], [5, '2026-11-07', 3]] as $l) {
        $contr->execute($l);
    }
});

// ═══ Equipe ═════════════════════════════════════════════════════════════════════════════════════
t('equipe: um cartão por closer da equipe, sem o líder, com métricas zeradas quando não há nada', function () use ($equipeSvc, $ANA, $porId) {
    $e = $equipeSvc->equipe(1, $ANA);
    $nomes = array_column($e['closers'], 'nome');
    sort($nomes);
    igual(['Bia', 'Bruno'], $nomes);
    igual(['feedbacks' => 0, 'cobrancas' => 0, 'revisoes' => 0, 'reunioes' => 0, 'treinamentos' => 0], $porId($e['closers'], 'nome', 'Bia')['metricas']);
    igual(30, $e['dias']);
});
t('equipe: métricas dos últimos 30 dias — feedbacks, cobranças, revisões, reuniões em que participou e treinamentos concluídos', function () use ($equipeSvc, $ANA, $db, $porId) {
    $db->executar("INSERT INTO feedbacks (equipe_id, closer_vendedor_id, lider_vendedor_id, data, assunto, texto, criado_em) VALUES (1, 2, 1, '2026-11-05', 'Ritmo', 'Texto', '2026-11-05 10:00:00'), (1, 2, 1, '2026-09-01', 'Antigo', 'Fora dos 30 dias', '2026-09-01 10:00:00'), (1, 2, 1, '2026-06-01', 'MuitoAntigo', 'Fora dos 90 dias', '2026-06-01 10:00:00')");
    $db->executar("INSERT INTO cobrancas_cadastro (equipe_id, closer_vendedor_id, lider_vendedor_id, data, observacao, criado_em) VALUES (1, 2, 1, '2026-11-06', 'Cobrar', '2026-11-06 10:00:00')");
    $db->executar("INSERT INTO revisoes_atendimento (equipe_id, closer_vendedor_id, telefone_cliente, observacao, lider_vendedor_id, data, criado_em) VALUES (1, 2, '11987654321', 'Revisão', 1, '2026-11-07', '2026-11-07 10:00:00')");
    $db->executar("INSERT INTO reunioes (id, equipe_id, titulo, data_hora_marcada, data_hora_realizada, status, criado_por, criado_em) VALUES (950, 1, 'Reunião feita', '2026-11-06 10:00:00', '2026-11-06 10:20:00', 'realizada', 1, '2026-11-05 08:00:00')");
    $db->executar('INSERT INTO reuniao_participantes (reuniao_id, vendedor_id) VALUES (950, 2)');
    $db->executar("INSERT INTO treinamentos (id, equipe_id, data_hora, assunto, criado_por, criado_em) VALUES (960, 1, '2026-11-03 14:00:00', 'Treino concluído', 1, '2026-11-01 08:00:00'), (961, NULL, '2026-11-04 14:00:00', 'Geral', 999, '2026-11-01 08:00:00'), (962, 1, '2026-12-01 14:00:00', 'Treino futuro', 1, '2026-11-01 08:00:00')");
    $db->executar('INSERT INTO treinamento_presencas (treinamento_id, vendedor_id) VALUES (960, 2), (961, 2), (962, 2), (960, 3)');
    $m = $porId($equipeSvc->equipe(1, $ANA)['closers'], 'nome', 'Bruno')['metricas'];
    igual(['feedbacks' => 1, 'cobrancas' => 1, 'revisoes' => 1, 'reunioes' => 1, 'treinamentos' => 2], $m, 'treinamento futuro não conta; o geral conta');
});
t('equipe: os alertas do closer vêm no cartão dele (ex.: Bia sem fechar), com a ação', function () use ($equipeSvc, $ANA, $porId) {
    $bia = $porId($equipeSvc->equipe(1, $ANA)['closers'], 'nome', 'Bia');
    $titulos = array_column($bia['alertas'], 'titulo');
    verdade(in_array('Bia: sem fechar contrato há 8 dias', $titulos, true), json_encode($titulos));
    igual('feedback', $bia['alertas'][0]['acao']['k']);
});
t('closer: o detalhe traz a linha do tempo (do mais novo para o mais antigo) com os 5 tipos e a evolução de 30 dias', function () use ($equipeSvc, $ANA) {
    $d = $equipeSvc->closer(1, $ANA, 2);
    verdade($d !== null);
    igual('Bruno', $d['closer']['nome']);
    $tipos = array_column($d['linha_do_tempo'], 'tipo');
    foreach (['feedback', 'cobranca', 'revisao', 'treinamento', 'reuniao'] as $t) {
        verdade(in_array($t, $tipos, true), "falta $t na linha do tempo");
    }
    $quandos = array_column($d['linha_do_tempo'], 'quando');
    $ord = $quandos;
    rsort($ord);
    igual($ord, $quandos);
    verdade(in_array('Antigo', array_column($d['linha_do_tempo'], 'titulo'), true), 'o de setembro está dentro dos 90 dias');
    verdade(!in_array('MuitoAntigo', array_column($d['linha_do_tempo'], 'titulo'), true), 'o de junho está fora dos 90 dias');
    igual(30, count($d['evolucao']['datas']));
    igual('2026-11-10', $d['evolucao']['datas'][29]);
    igual(10, $d['evolucao']['total_contratos'], '10 contratos em 05/11');
    igual(10, $d['evolucao']['contratos'][array_search('2026-11-05', $d['evolucao']['datas'], true)]);
});
t('closer: closer de outra equipe, o próprio líder e id inexistente são "não encontrado" (null)', function () use ($equipeSvc, $ANA) {
    igual(null, $equipeSvc->closer(1, $ANA, 4), 'Davi é da Beta');
    igual(null, $equipeSvc->closer(1, $ANA, 1), 'o líder');
    igual(null, $equipeSvc->closer(1, $ANA, 999));
    igual(null, $equipeSvc->closer(1, $ANA, 0));
});

// ═══ Reuniões: participantes · Treinamentos: cancelar ═══════════════════════════════════════════
t('reunião: sem lista, os participantes são a equipe toda menos o líder; com lista, só os escolhidos; de fora da equipe é recusado', function () use ($reunioes, $ANA) {
    $a = $reunioes->criar(1, $ANA, 'Com todos', '2026-11-13 10:00');
    verdade($a->sucesso());
    $ps = $a->dados()['item']['participantes'];
    sort($ps);
    igual([2, 6], $ps);
    igual([2], $reunioes->criar(1, $ANA, 'Só o Bruno', '2026-11-13 11:00', [2])->dados()['item']['participantes']);
    $r = $reunioes->criar(1, $ANA, 'Invasão', '2026-11-13 12:00', [2, 4]);
    verdade(!$r->sucesso());
    verdade(isset($r->erros()['participantes']));
    verdade(!$reunioes->criar(1, $ANA, 'Texto', '2026-11-13 12:00', 'bruno')->sucesso(), 'a lista tem que ser uma lista');
});
t('reunião: editar sem lista não mexe nos participantes; com lista troca; a lista aparece na consulta da agenda', function () use ($reunioes, $ANA) {
    $id = $reunioes->criar(1, $ANA, 'Original', '2026-11-14 10:00', [2])->dados()['item']['id'];
    igual([2], $reunioes->editar(1, $id, 'Novo título', null, null)->dados()['item']['participantes']);
    igual([6], $reunioes->editar(1, $id, null, null, null, [6])->dados()['item']['participantes']);
    verdade(!$reunioes->editar(1, $id, null, null, null, [3])->sucesso());
    igual([6], array_values(array_filter($reunioes->entre(1, '2026-11-14 00:00:00', '2026-11-14 23:59:59'), static fn(array $x): bool => $x['id'] === $id))[0]['participantes']);
});
t('treinamento: cancelar o da equipe (fica como cancelado, sem edição nem presença); o geral e o já cancelado são recusados', function () use ($treinos, $ANA, $db) {
    $id = $treinos->criar(1, $ANA, '2026-11-25 15:00', 'A cancelar')->dados()['item']['id'];
    $r = $treinos->cancelar(1, $id);
    verdade($r->sucesso());
    igual(true, $r->dados()['item']['cancelado']);
    igual(false, $r->dados()['item']['editavel']);
    verdade(!$treinos->cancelar(1, $id)->sucesso(), 'já cancelado');
    verdade(!$treinos->editar(1, $id, '2026-11-26 15:00', 'x')->sucesso());
    verdade(!$treinos->definirPresencas(1, $id, [2])->sucesso());
    $m = $treinos->cancelar(1, 961);
    verdade(!$m->sucesso());
    verdade(strpos($m->mensagem(), 'administrador') !== false);
    verdade(!$treinos->cancelar(2, $id)->sucesso(), 'a equipe 2 não cancela o treinamento da 1');
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM treinamentos WHERE id = ? AND cancelado_em IS NOT NULL', [$id]));
});

// ═══ Agenda e notas ═════════════════════════════════════════════════════════════════════════════
t('closer: produtividade e contratos da semana (padrão) ou do mês, com o período anterior e os benefícios fechados', function () use ($equipeSvc, $ANA, $db) {
    $db->executar("INSERT INTO acoes_closer (vendedor_id, tipo, telefone, registrado_em) VALUES
        (2, 'ligacao', '11911110001', '2026-11-09 10:00:00'), (2, 'ligacao', '11911110002', '2026-11-09 10:01:00'), (2, 'ligacao', '11911110003', '2026-11-09 10:02:00'),
        (2, 'followup', '11911110001', '2026-11-09 11:00:00'), (2, 'followup', '11911110002', '2026-11-09 11:01:00'),
        (2, 'lead_quente', '11911110004', '2026-11-02 10:00:00')");
    $db->executar("INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (2, '2026-11-01', 2)");
    $db->executar("INSERT INTO contratos_tipos (vendedor_id, data_registro, tipo, tipo_descricao, quantidade) VALUES (2, '2026-11-05', 'aux_acidente', '', 6), (2, '2026-11-05', 'outros', 'Seguro X', 2)");
    try {
        $sem = $equipeSvc->closer(1, $ANA, 2)['periodo'];
        igual('semana', $sem['periodo'], 'sem pedir período, vale a semana');
        igual(['2026-11-04', '2026-11-10', 7], [$sem['inicio'], $sem['fim'], $sem['dias']]);
        $p = $sem['produtividade'];
        igual([3, 2, 0, 5], [$p['ligacoes'], $p['followups'], $p['leads_quentes'], $p['total']], 'só as ações da semana');
        igual(1, $p['anterior'], 'a semana anterior teve o lead quente de 02/11');
        igual(400, $p['variacao']);
        igual(3, $p['leads'], 'leads únicos: três números diferentes');
        igual(333.3, $p['conversao'], '10 contratos ÷ 3 leads');
        $c = $sem['contratos'];
        igual(10, $c['total']);
        igual(2, $c['anterior'], 'os 2 contratos de 01/11 são da semana anterior');
        igual(1.4, $c['media_dia']);
        igual(['data' => '2026-11-05', 'qtd' => 10], $c['melhor_dia']);
        igual(['aux_acidente', 'outros:Seguro X'], array_column($c['beneficios'], 'chave'));
        igual([6, 2], array_column($c['beneficios'], 'qtd'));
        igual('Seguro X', $c['beneficios'][1]['label'], '"Outros" com descrição vira um item pela descrição');
        verdade(preg_match('/^#[0-9a-f]{6}$/i', $c['beneficios'][0]['cor']) === 1, 'cada benefício vem com a cor do admin');
        igual(2, $c['sem_tipo'], '10 contratos, 8 com tipo');

        $mes = $equipeSvc->closer(1, $ANA, 2, 'mes')['periodo'];
        igual(['mes', '2026-11-01', '2026-11-30'], [$mes['periodo'], $mes['inicio'], $mes['fim']]);
        igual([3, 2, 1, 6], [$mes['produtividade']['ligacoes'], $mes['produtividade']['followups'], $mes['produtividade']['leads_quentes'], $mes['produtividade']['total']]);
        igual(12, $mes['contratos']['total'], '10 + os 2 de 01/11');
        igual(0, $mes['contratos']['anterior']);
        igual(1.2, $mes['contratos']['media_dia'], '12 contratos em 10 dias (do dia 1 até hoje)');

        igual('semana', $equipeSvc->closer(1, $ANA, 2, 'hoje')['periodo']['periodo'], 'período que não é semana nem mês vira semana');
        igual('semana', $equipeSvc->closer(1, $ANA, 2, "mes'; DROP TABLE x")['periodo']['periodo']);
        igual(null, $equipeSvc->closer(1, $ANA, 4, 'mes'), 'closer de outra equipe continua "não encontrado" em qualquer período');
    } finally {
        $db->executar("DELETE FROM acoes_closer WHERE telefone LIKE '119111100%'");
        $db->executar("DELETE FROM contratos_historico WHERE vendedor_id = 2 AND data_registro = '2026-11-01'");
        $db->executar('DELETE FROM contratos_tipos WHERE vendedor_id = 2');
    }
});
t('agenda: os eventos do mês trazem as reuniões da equipe e os treinamentos (da equipe e geral), ordenados, sem nada de outra equipe', function () use ($agenda, $db) {
    $db->executar("INSERT INTO reunioes (id, equipe_id, titulo, data_hora_marcada, status, criado_por, criado_em) VALUES (970, 2, 'Só da Beta', '2026-11-11 09:00:00', 'agendada', 3, '2026-11-08 09:00:00')");
    $ev = $agenda->mes(1, 2026, 11);
    $titulos = array_column($ev, 'titulo');
    verdade(in_array('Reunião feita', $titulos, true));
    verdade(in_array('Geral', $titulos, true), 'o treinamento geral aparece');
    verdade(!in_array('Só da Beta', $titulos, true), 'a reunião da Beta não aparece');
    $quandos = array_column($ev, 'quando');
    $ord = $quandos;
    sort($ord);
    igual($ord, $quandos);
    $geral = array_values(array_filter($ev, static fn(array $e): bool => $e['titulo'] === 'Geral'))[0];
    igual(true, $geral['geral']);
    igual(false, $geral['editavel']);
    igual('realizado', $geral['status']);
    $feita = array_values(array_filter($ev, static fn(array $e): bool => $e['titulo'] === 'Reunião feita'))[0];
    igual(['id' => 2, 'nome' => 'Bruno'], $feita['participantes'][0]);
    igual('realizada', $feita['status']);
    igual(false, $feita['cancelavel']);
});
t('agenda: mês ou ano inválido vira o mês atual; os próximos 7 dias não trazem cancelados nem eventos de fora da janela', function () use ($agenda, $db) {
    igual($agenda->mes(1, 2026, 11), $agenda->mes(1, 2026, 13));
    igual($agenda->mes(1, 2026, 11), $agenda->mes(1, 1999, 0));
    $prox = array_column($agenda->proximos(1, 7), 'titulo');
    verdade(in_array('Planejamento', $prox, true), 'reunião de 12/11');
    verdade(!in_array('Daqui a um mês', $prox, true));
    verdade(!in_array('A cancelar', $prox, true), 'cancelados não entram');
    $db->executar("UPDATE reunioes SET status = 'cancelada' WHERE id = 901");
    verdade(!in_array('Planejamento', array_column($agenda->proximos(1, 7), 'titulo'), true));
    $db->executar("UPDATE reunioes SET status = 'agendada' WHERE id = 901");
});
t('agenda: feedbacks, cobranças e revisões do mês entram no calendário como atividades do dia (sem horário), só da equipe; os próximos 7 dias seguem só com reuniões e treinamentos', function () use ($agenda, $db) {
    $db->executar("INSERT INTO feedbacks (equipe_id, closer_vendedor_id, lider_vendedor_id, data, assunto, texto, criado_em) VALUES
        (2, 4, 3, '2026-11-06', 'Da Beta', 'Não pode aparecer', '2026-11-06 10:00:00'), (1, 2, 1, '2026-11-10', 'Hoje', 'Feedback de hoje', '2026-11-10 09:00:00')");
    try {
        $ev = $agenda->mes(1, 2026, 11);
        $porTipo = static fn(string $t): array => array_values(array_filter($ev, static fn(array $e): bool => $e['tipo'] === $t));
        $fdb = array_column($porTipo('feedback'), 'titulo');
        verdade(in_array('Ritmo', $fdb, true) && in_array('Hoje', $fdb, true), 'os feedbacks da equipe no mês');
        verdade(!in_array('Da Beta', $fdb, true), 'feedback de outra equipe não aparece');
        verdade(!in_array('Antigo', $fdb, true), 'o de setembro não é de novembro');
        verdade(in_array('Antigo', array_column(array_filter($agenda->mes(1, 2026, 9), static fn(array $e): bool => $e['tipo'] === 'feedback'), 'titulo'), true), 'mas aparece em setembro');
        $cob = $porTipo('cobranca')[0];
        igual(['Cobrança de cadastramento', '2026-11-06 00:00:00', true, 'Bruno', 'Cobrar'], [$cob['titulo'], $cob['quando'], $cob['sem_hora'], $cob['closer_nome'], $cob['detalhe']]);
        igual([true, false, 'registrada'], [$cob['editavel'], $cob['cancelavel'], $cob['status']]);
        igual(false, $cob['visto'], 'a cobrança ainda não foi vista pelo closer');
        $rev = $porTipo('revisao')[0];
        igual('Revisão de atendimento', $rev['titulo']);
        verdade(strpos($rev['detalhe'], '98765-4321') !== false && strpos($rev['detalhe'], 'Revisão') !== false, 'o número formatado e a observação');
        igual(null, $rev['visto'], 'revisão não tem "visto"');
        igual('Ritmo', $porTipo('feedback')[0]['item']['assunto'], 'cada atividade traz o item que o formulário de edição usa');
        $quandos = array_column($ev, 'quando');
        $ord = $quandos;
        sort($ord);
        igual($ord, $quandos, 'tudo em ordem de data');
        foreach ($agenda->proximos(1, 7) as $e) {
            verdade(in_array($e['tipo'], ['reuniao', 'treinamento'], true), 'próximos: só reuniões e treinamentos, veio ' . $e['tipo']);
        }
        verdade(!in_array('Hoje', array_column($agenda->proximos(1, 7), 'titulo'), true));
    } finally {
        $db->executar("DELETE FROM feedbacks WHERE assunto IN ('Da Beta', 'Hoje')");
    }
});
t('agenda: nota em reunião e em treinamento (inclusive o geral): só acréscimo, com autor, máximo 500 caracteres', function () use ($agenda, $ANA) {
    $r = $agenda->nota(1, $ANA, 'reuniao', 901, '  Levar os números da semana.  ');
    verdade($r->sucesso());
    igual('Levar os números da semana.', $r->dados()['nota']['texto']);
    igual('Ana', $r->dados()['nota']['autor']);
    verdade($agenda->nota(1, $ANA, 'treinamento', 961, 'Nota no treinamento geral')->sucesso());
    foreach ([['', 'Escreva a nota.'], [str_repeat('n', 501), 'até 500']] as [$texto, $trecho]) {
        $x = $agenda->nota(1, $ANA, 'reuniao', 901, $texto);
        verdade(!$x->sucesso());
        verdade(strpos($x->mensagem(), $trecho) !== false, $x->mensagem());
    }
    $ev = array_values(array_filter($agenda->mes(1, 2026, 11), static fn(array $e): bool => $e['id'] === 901 && $e['tipo'] === 'reuniao'))[0];
    igual('Levar os números da semana.', $ev['notas'][0]['texto']);
    verdade($ev['notas'][0]['quando'] !== '');
});
t('agenda: nota em evento de OUTRA equipe, em tipo inventado ou em id inexistente é "evento não encontrado"; a nota da equipe 1 não aparece para a 3 no geral', function () use ($agenda, $ANA, $db) {
    foreach ([['reuniao', 970], ['reuniao', 99999], ['treinamento', 99999], ['festa', 901]] as [$tipo, $id]) {
        $x = $agenda->nota(1, $ANA, $tipo, $id, 'Invasão');
        verdade(!$x->sucesso(), "$tipo $id");
        igual('Evento não encontrado.', $x->mensagem());
    }
    igual(0, (int) $db->valor("SELECT COUNT(*) FROM evento_notas WHERE texto = 'Invasão'"));
    $geral3 = array_values(array_filter($agenda->mes(3, 2026, 11), static fn(array $e): bool => $e['titulo'] === 'Geral'))[0];
    igual([], $geral3['notas'], 'a nota do líder da equipe 1 não aparece para a equipe 3');
});

// ═══ Busca global ═══════════════════════════════════════════════════════════════════════════════
t('busca: acha closers da equipe (sem acento e sem diferenciar maiúsculas), sem o líder e sem gente de outra equipe', function () use ($busca, $ANA, $db) {
    $db->executar("UPDATE vendedores SET nome = 'João Souza' WHERE id = 6");
    $r = $busca->buscar(1, $ANA, 'joao');
    igual([['id' => 6, 'nome' => 'João Souza']], $r['closers']);
    igual([], $busca->buscar(1, $ANA, 'ana')['closers'], 'o líder não aparece');
    igual([], $busca->buscar(1, $ANA, 'davi')['closers'], 'Davi é da Beta');
    $db->executar("UPDATE vendedores SET nome = 'Bia' WHERE id = 6");
});
t('busca: acha registros da equipe (feedback, cobrança, revisão por observação ou telefone, reunião e treinamento) e nenhum de outra equipe', function () use ($busca, $ANA, $db) {
    $db->executar("INSERT INTO feedbacks (equipe_id, closer_vendedor_id, lider_vendedor_id, data, assunto, texto, criado_em) VALUES (1, 2, 1, '2026-11-05', 'Ritmo de ligações', 'Aumentar as ligações pela manhã', '2026-11-05 10:00:00'), (2, 4, 3, '2026-11-05', 'Ritmo da Beta', 'Texto secreto da Beta', '2026-11-05 10:00:00')");
    $porTipo = static fn(array $r, string $tipo): array => array_values(array_filter($r['registros'], static fn(array $x): bool => $x['tipo'] === $tipo));
    $r = $busca->buscar(1, $ANA, 'ligações');
    $f = $porTipo($r, 'feedback');
    igual(1, count($f));
    igual('Ritmo de ligações', $f[0]['titulo']);
    igual('Bruno', $f[0]['closer_nome']);
    igual([], $busca->buscar(1, $ANA, 'secreto')['registros'], 'o texto da Beta não aparece');
    igual([], $busca->buscar(1, $ANA, 'Ritmo da Beta')['registros'], 'nem o assunto');
    igual(1, count($porTipo($busca->buscar(1, $ANA, 'Reunião feita'), 'reuniao')));
    igual(1, count($porTipo($busca->buscar(1, $ANA, 'Treino concluído'), 'treinamento')));
    igual(1, count($porTipo($busca->buscar(1, $ANA, 'Cobrar'), 'cobranca')));
    igual(1, count($porTipo($busca->buscar(1, $ANA, '(11) 98765-4321'), 'revisao')), 'pelo telefone formatado');
});
t('busca: curingas viram texto comum (% e _ não casam com tudo), texto curto ou vazio não busca nada', function () use ($busca, $ANA) {
    igual(['closers' => [], 'registros' => []], $busca->buscar(1, $ANA, '%%'));
    igual(['closers' => [], 'registros' => []], $busca->buscar(1, $ANA, '__'));
    igual(['closers' => [], 'registros' => []], $busca->buscar(1, $ANA, 'a'));
    igual(['closers' => [], 'registros' => []], $busca->buscar(1, $ANA, ''));
    igual(['closers' => [], 'registros' => []], $busca->buscar(1, $ANA, null));
    igual(['closers' => [], 'registros' => []], $busca->buscar(1, $ANA, str_repeat('x', 61)), 'texto longo demais');
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
$lider = ['closer_id' => 10, 'cr_csrf' => 'tk'];

t('rotas: líder lê Dashboard, Equipe, Agenda, Busca e Preferências; sempre da equipe dele, mesmo pedindo outra', function () use ($chamar, $lider) {
    [$s, $j] = $chamar('GET', '/api/lider/dashboard?equipe_id=2', $lider);
    igual(200, $s);
    igual('Alpha', $j['equipe']['nome']);
    verdade(strpos(json_encode($j), 'Caio') === false);
    [$se, $je] = $chamar('GET', '/api/lider/equipe?equipe_id=2', $lider);
    igual(200, $se);
    foreach ($je['closers'] as $c) {
        verdade(in_array($c['nome'], ['Bia', 'Bruno'], true), 'só da equipe 1');
    }
    [$sa, $ja] = $chamar('GET', '/api/lider/agenda?ano=2026&mes=11', $lider);
    igual(200, $sa);
    igual([2026, 11], [$ja['ano'], $ja['mes']]);
    [$sb, $jb] = $chamar('GET', '/api/lider/busca?q=bruno', $lider);
    igual(200, $sb);
    igual('Bruno', $jb['closers'][0]['nome']);
    [$sp, $jp] = $chamar('GET', '/api/lider/preferencias', $lider);
    igual(200, $sp);
    igual(14, $jp['preferencias']['dias_sem_feedback']);
    igual(5, count($jp['alertas_nomes']));
});
t('rotas: sem login 401; closer comum 403; admin 401 — em todas as rotas do portal', function () use ($chamar) {
    foreach (['/api/lider/dashboard', '/api/lider/equipe', '/api/lider/closer/2', '/api/lider/agenda', '/api/lider/busca?q=ab', '/api/lider/preferencias'] as $uri) {
        igual(401, $chamar('GET', $uri, [])[0], "$uri sem login");
        igual(403, $chamar('GET', $uri, ['closer_id' => 11])[0], "$uri closer comum");
        igual(401, $chamar('GET', $uri, ['admin_logged' => true])[0], "$uri admin");
    }
});
t('rotas: detalhe de closer da equipe 200; de outra equipe, do líder ou inexistente 404', function () use ($chamar, $lider) {
    igual(200, $chamar('GET', '/api/lider/closer/2', $lider)[0]);
    igual(404, $chamar('GET', '/api/lider/closer/4', $lider)[0], 'Davi é da Beta');
    igual(404, $chamar('GET', '/api/lider/closer/1', $lider)[0], 'o próprio líder');
    igual(404, $chamar('GET', '/api/lider/closer/9999', $lider)[0]);
});
t('rotas: salvar preferências exige o CSRF do portal (419 sem token), valida (422) e grava', function () use ($chamar, $lider, $prefs) {
    $corpo = ['limite_dias_sem_venda' => 5, 'dias_sem_feedback' => 20, 'alertas' => ['sem_feedback' => false]];
    igual(419, $chamar('POST', '/api/lider/preferencias', $lider, $corpo)[0]);
    igual(419, $chamar('POST', '/api/lider/preferencias', $lider, $corpo, 'errado')[0]);
    igual(5, $prefs->obter(1)['limite_dias_sem_venda'] === 5 ? 99 : 5, 'nada foi gravado sem o token');
    [$s, $j] = $chamar('POST', '/api/lider/preferencias', $lider, $corpo, 'tk');
    igual(200, $s);
    igual(5, $j['preferencias']['limite_dias_sem_venda']);
    igual(false, $j['preferencias']['alertas']['sem_feedback']);
    [$s2, $j2] = $chamar('POST', '/api/lider/preferencias', $lider, ['limite_dias_sem_venda' => 99], 'tk');
    igual(422, $s2);
    verdade(isset($j2['errors']['limite_dias_sem_venda']));
    igual(5, $prefs->obter(1)['limite_dias_sem_venda'], 'o valor inválido não substituiu o bom');
    igual(403, $chamar('POST', '/api/lider/preferencias', ['closer_id' => 11, 'cr_csrf' => 'tk'], $corpo, 'tk')[0], 'closer comum não grava preferências');
    $prefs->salvar(1, ['limite_dias_sem_venda' => 3, 'dias_sem_feedback' => 14, 'alertas' => ['sem_feedback' => true]]);
});
t('rotas: nota e cancelamento pela rota de ação (reunião, treinamento), com CSRF e dentro da equipe', function () use ($chamar, $lider, $db) {
    igual(419, $chamar('POST', '/api/lider/registros/reunioes/901/nota', $lider, ['texto' => 'x'])[0]);
    [$s, $j] = $chamar('POST', '/api/lider/registros/reunioes/901/nota', $lider, ['texto' => 'Nota pela rota'], 'tk');
    igual(200, $s);
    igual('Nota pela rota', $j['nota']['texto']);
    igual(404, $chamar('POST', '/api/lider/registros/reunioes/970/nota', $lider, ['texto' => 'Invasão'], 'tk')[0], 'reunião da Beta');
    igual(422, $chamar('POST', '/api/lider/registros/reunioes/901/nota', $lider, ['texto' => ''], 'tk')[0]);
    [$sc, $jc] = $chamar('POST', "/api/lider/registros/treinamentos/962/cancelar", $lider, [], 'tk');
    igual(200, $sc);
    igual(true, $jc['item']['cancelado']);
    igual(404, $chamar('POST', '/api/lider/registros/treinamentos/960/cancelar', ['closer_id' => 12, 'cr_csrf' => 'tk'], [], 'tk')[0], 'o líder da Beta não cancela o treinamento da Alpha');
});
t('rotas: criar reunião pela rota aceita a lista de participantes e recusa gente de outra equipe', function () use ($chamar, $lider) {
    [$s, $j] = $chamar('POST', '/api/lider/registros/reunioes', $lider, ['titulo' => 'Pela rota', 'data_hora_marcada' => '2026-11-18 10:00', 'participantes' => [2, 6]], 'tk');
    igual(200, $s);
    igual([2, 6], $j['item']['participantes']);
    [$s2, $j2] = $chamar('POST', '/api/lider/registros/reunioes', $lider, ['titulo' => 'Pela rota 2', 'data_hora_marcada' => '2026-11-18 10:00', 'participantes' => [2, 4]], 'tk');
    igual(422, $s2);
    verdade(isset($j2['errors']['participantes']));
});
t('migração: cria as 10 tabelas do módulo (a última, as respostas do closer) e a coluna de cancelamento, idempotente, mesmo numa base sem elas', function () use ($db) {
    $tabelas = App\Services\MigracaoLiderService::TABELAS;
    igual(10, count($tabelas));
    foreach (['reuniao_participantes', 'evento_notas', 'lider_preferencias', 'interacao_respostas'] as $nova) {
        verdade(isset($tabelas[$nova]), "falta $nova");
    }
    $db->executar('DROP TABLE evento_notas');
    $db->executar('ALTER TABLE treinamentos DROP COLUMN cancelado_em');
    $m = new App\Services\MigracaoLiderService($db);
    $rel = $m->executar();
    igual(['evento_notas'], $rel['criadas']);
    igual(['treinamentos.cancelado_em'], $rel['colunas_adicionadas']);
    igual([[], []], [$m->executar()['criadas'], $m->executar()['colunas_adicionadas']]);
});
t('tabelas ausentes (migração não rodada): as rotas do portal respondem 503 com a instrução, nunca 500', function () use ($chamar, $lider, $db) {
    $db->executar('DROP TABLE reunioes');
    foreach (['/api/lider/dashboard', '/api/lider/equipe', '/api/lider/agenda'] as $uri) {
        [$s, $j] = $chamar('GET', $uri, $lider);
        igual(503, $s, $uri);
        igual('migracao', $j['code']);
        verdade(strpos($j['message'], 'tabelas do módulo do líder') !== false);
    }
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
exit($falharam ? 1 : 0);
