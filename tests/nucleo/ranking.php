<?php
declare(strict_types=1);

/**
 * Testes dos rankings no núcleo (fase 3B): ranking ao vivo da TV, ranking por período, Painel, gráficos, Visão do Time (semana),
 * Meta Ads/CAC e férias — com DADOS SINTÉTICOS de números conhecidos, relógio congelado em 10/11/2026 10:00 (terça) e um banco
 * DESCARTÁVEL (nucleo_teste_ranking). O Meta Ads é simulado: NENHUM teste chama a rede.
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\nucleo\ranking.php
 * Complementa o golden/diferencial, que comparam o código novo com o antigo nos dados reais e em 9 estados do banco.
 */

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Services\Calendario;
use App\Services\FeriasService;
use App\Services\GraficoService;
use App\Services\InvestimentoService;
use App\Services\MixDeBeneficios;
use App\Services\PainelService;
use App\Services\RankingAoVivoService;
use App\Services\RankingFiltradoService;
use App\Services\ResumoSemanaService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');
require __DIR__ . '/harness.php';

Relogio::fixar('2026-11-10 10:00:00');
ini_set('error_log', sys_get_temp_dir() . '/nucleo-ranking-erros.log');   // os erros simulados do Meta Ads vão para um arquivo, não para a tela

$real = new Database();
$real->testar();
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este teste só roda contra o MySQL local de teste.\n");
    exit(2);
}
$esquema = 'nucleo_teste_ranking';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
foreach (['vendedores', 'equipes', 'contratos_historico', 'contratos_tipos', 'atendimentos', 'metas_periodo', 'metas_diarias_periodo', 'semanas_mes', 'app_settings', 'propagandas', 'investimento_mensal', 'contratos', 'contratos_feed', 'tipos_beneficio_custom', 'closer_usuarios'] as $tabela) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $tabela . '` LIKE `' . DB_NAME . '`.`' . $tabela . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);

// ── dados (hoje = terça 10/11; a semana automática é 04/11 a 10/11; novembro tem as quartas 4, 11, 18 e 25 = 4 semanas) ──
$db->executar("INSERT INTO equipes (id, nome, cor, meta_semanal, meta_mensal) VALUES (1, 'Alpha', '#112233', 0, 0), (2, 'Beta', '#445566', 0, 0)");
$vend = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, meta_diaria, cor, deleted_at, suspended_at) VALUES (?,?,?,?,?,?,?,?,?)');
foreach ([
    [1, 1, 'Ana', 10, 0, 0, '#abcdef', null, null],
    [2, 2, 'Bruno', 5, 0, 0, '#aaaaaa', null, null],
    [3, 1, 'Caio', 8, 0, 0, '#123456', '2026-11-08 10:00:00', null],      // desligado COM contrato na semana
    [4, 1, 'Eva', 6, 0, 0, '#0a0b0c', null, '2026-11-02 10:00:00'],       // suspensa
    [5, 2, 'Fabio', 4, 0, 0, '#bbbbbb', null, null],
] as $linha) {
    $vend->execute($linha);
}
$hist = $db->pdo()->prepare('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?,?,?)');
foreach ([[1, '2026-11-02', 1], [1, '2026-11-04', 2], [1, '2026-11-05', 3], [1, '2026-11-10', 4], [2, '2026-11-06', 5], [3, '2026-11-07', 2], [4, '2026-11-05', 1], [5, '2026-11-10', 1]] as $linha) {
    $hist->execute($linha);
}
$tp = $db->pdo()->prepare('INSERT INTO contratos_tipos (vendedor_id, data_registro, tipo, tipo_descricao, quantidade) VALUES (?,?,?,?,?)');
foreach ([[1, '2026-11-05', 'bpc_loas', '', 2], [2, '2026-11-06', 'aux_acidente', '', 1], [1, '2026-11-10', 'outros', 'Pensão X', 2]] as $linha) {
    $tp->execute($linha);
}
$db->executar("INSERT INTO atendimentos (vendedor_id, data_registro, quantidade) VALUES (1, '2026-11-05', 18)");
$db->executar("INSERT INTO metas_diarias_periodo (closer_id, ano, mes, dia, meta) VALUES (1, 2026, 11, 10, 3)");
$db->executar("INSERT INTO app_settings (setting_key, setting_value) VALUES ('investimento_mes', '1900')");

$container = new Container();
$container->instancia(Database::class, $db);
function achar(array $linhas, string $nome, string $chave = 'name'): array
{
    foreach ($linhas as $l) {
        if (($l[$chave] ?? null) === $nome) {
            return $l;
        }
    }
    throw new Exception("não achei $nome");
}

// ═══ Calendário: limites e quantidade de semanas ══════════════════════════════════════════════════════
t('Calendario::limitesDaSemana: automática (n-ésima quarta a terça), e a 5ª semana quando o mês só tem 4 quartas devolve a semana que começa fora do mês (como sempre foi)', function () use ($container) {
    $c = $container->obter(Calendario::class);
    igual(['start' => '2026-11-04', 'end' => '2026-11-10'], $c->limitesDaSemana(2026, 11, 1));
    igual(['start' => '2026-11-25', 'end' => '2026-12-01'], $c->limitesDaSemana(2026, 11, 4));
    igual(['start' => '2026-12-02', 'end' => '2026-12-08'], $c->limitesDaSemana(2026, 11, 5), 'o laço para quando a quarta passa do fim do mês, mas o número já bateu');
    igual(null, $c->limitesDaSemana(2026, 11, 6));
    igual(null, $c->limitesDaSemana(2026, 11, 0));
});
t('Calendario::quantidadeDeSemanas: configuradas valem; sem configuração conta as quartas do mês (mínimo 1)', function () use ($container, $db) {
    $c = $container->obter(Calendario::class);
    igual([4, 5, 4], [$c->quantidadeDeSemanas(2026, 11), $c->quantidadeDeSemanas(2026, 12), $c->quantidadeDeSemanas(2026, 10)], 'quartas-feiras: nov 4, 11, 18, 25; dez 2, 9, 16, 23, 30; out 7, 14, 21, 28');
    $db->executar("INSERT INTO semanas_mes (ano, mes, semana_num, data_inicio, data_fim) VALUES (2026, 11, 1, '2026-11-02', '2026-11-08'), (2026, 11, 2, '2026-11-09', '2026-11-15')");
    igual(2, $c->quantidadeDeSemanas(2026, 11));
    $semanas = $c->semanasDoMesParaTv(2026, 11);
    igual([['start' => '2026-11-02', 'end' => '2026-11-08', 'label' => 'S1', 'semana_num' => 1, 'configured' => true], ['start' => '2026-11-09', 'end' => '2026-11-15', 'label' => 'S2', 'semana_num' => 2, 'configured' => true]], $semanas);
    $db->executar('DELETE FROM semanas_mes');
    igual(false, $c->semanasDoMesParaTv(2026, 11)[0]['configured']);
});

// ═══ Ranking ao vivo (TV) ═══════════════════════════════════════════════════════════════════════════
t('Ranking ao vivo: ordem semanal e mensal, pontuação e metas efetivas (a mensal é a projeção semanal × 4 semanas)', function () use ($container) {
    $d = $container->obter(RankingAoVivoService::class)->dados();
    igual(['Bruno', 'Ana', 'Fabio'], array_column($d['data'], 'name'), 'semanal: Bruno 100, Ana 90, Fabio 25 (Caio e Eva não aparecem)');
    igual([100, 90, 25], array_column($d['data'], 'score'));
    igual(['Bruno', 'Ana', 'Fabio'], array_column($d['data_mensal'], 'name'), 'mensal: Ana e Bruno empatam em 25; o desempate (aux_acidente) dá o 1º a Bruno');
    igual([25, 25, 6], array_column($d['data_mensal'], 'score_mensal'));
    $ana = achar($d['data'], 'Ana');
    igual([9, 10, 10, 40, 1, [2, 3, 0, 0, 0, 0, 4], [null, null, null, null, null, null, 3]], [$ana['vendas'], $ana['metas'], $ana['meta_semanal'], $ana['meta_m_val'], $ana['faltam'], $ana['daily'], $ana['daily_metas']]);
    igual([null, 40], [$ana['meta_m_eff'], $ana['meta_m_val']], 'sem meta mensal cadastrada: meta_m_eff é null e vale a projeção');
    igual(['bpc_loas' => 2, 'outros' => 2], $ana['tipo_totals']);
    igual(50.0, $ana['conv_rate']);
    igual([9, 0, 0, 0], $ana['week_scores'], 'week_scores soma por semana do mês: S1 (04 a 10/11) = 9; o contrato de 02/11 não cai em nenhuma semana');
    igual(10, $ana['vendas_mes'], 'o mês inclui o contrato de 02/11');
});
t('Ranking ao vivo: equipes somam também os contratos dos ex-membros (Caio desligado e Eva suspensa)', function () use ($container) {
    $d = $container->obter(RankingAoVivoService::class)->dados();
    igual(['Alpha', 'Beta'], array_column($d['teams'], 'nome'));
    $alpha = achar($d['teams'], 'Alpha', 'nome');
    igual([12, 1, 10, 120, 13, 40, 33], [$alpha['vendas'], $alpha['members'], $alpha['meta_s_eff'], $alpha['score'], $alpha['vendas_mes'], $alpha['meta_m_eff'], $alpha['score_mensal']], 'semana: Ana 9 + Caio 2 + Eva 1; mês: Ana 10 + Caio 2 + Eva 1');
    $beta = achar($d['teams'], 'Beta', 'nome');
    igual([6, 2, 9, 67, 36, 17], [$beta['vendas'], $beta['members'], $beta['meta_s_eff'], $beta['score'], $beta['meta_m_eff'], $beta['score_mensal']]);
    igual('#112233', $alpha['cor']);
});
t('Ranking ao vivo: totais, meta do dia, CAC manual, semana e metadados', function () use ($container) {
    $d = $container->obter(RankingAoVivoService::class)->dados();
    igual([15, 19, 19, 5, 3], [$d['total_semana'], $d['meta_semana_total'], $d['total_mes'], $d['total_hoje'], $d['meta_dia_total']], 'semana 9 + 5 + 1; mês inclui ex-membros (19); hoje Ana 4 + Fabio 1');
    igual([100.0, 1900.0, 'manual'], [$d['cac'], $d['investimento_mes'], $d['investimento_fonte']]);
    igual(['start' => '2026-11-04', 'end' => '2026-11-10', 'configured' => false], $d['weekBounds']);
    igual([1, 2026, 11, '11/2026', 7], [$d['curSemNum'], $d['curSemAno'], $d['curSemMes'], $d['mes_label'], $d['weekDays']]);
    igual(['Qua', 'Qui', 'Sex', 'Sáb', 'Dom', 'Seg', 'Ter'], $d['weekLabels']);
    igual(['04', '05', '06', '07', '08', '09', '10'], $d['weekDates']);
    igual(4, count($d['monthWeeks']));
    igual(Relogio::agora()->getTimestamp(), $d['timestamp']);
    igual(['aux_acidente', 'bpc_loas', 'sal_maternidade', 'aux_doenca', 'outros'], $d['tiebreaker']);
});
t('Ranking ao vivo: a meta "geral" e as metas por período (closer e equipe) sobrepõem as cadastradas', function () use ($container, $db) {
    $db->executar("INSERT INTO metas_periodo (tipo, ref_id, ano, mes, semana_num, meta) VALUES ('closer', 1, 2026, 11, 1, 3), ('closer', 2, 2026, 11, 0, 5), ('equipe', 2, 2026, 11, 1, 6), ('geral', 0, 2026, 11, 1, 400)");
    $d = $container->obter(RankingAoVivoService::class)->dados();
    $ana = achar($d['data'], 'Ana');
    igual([3, 300, 0], [$ana['meta_s_eff'], $ana['score'], $ana['faltam']], 'Ana: 9 contratos para meta 3 = 300%, e não falta nada');
    igual(400, $d['meta_semana_total'], 'a meta geral vale no lugar da soma');
    igual([5, 5], [achar($d['data'], 'Bruno')['meta_m_eff'], achar($d['data'], 'Bruno')['meta_m_val']]);
    igual([6, 100], [achar($d['teams'], 'Beta', 'nome')['meta_s_eff'], achar($d['teams'], 'Beta', 'nome')['score']]);
    $db->executar('DELETE FROM metas_periodo');
});

// ═══ Investimento e CAC (só o valor digitado à mão; o Meta Ads foi removido em 11/11/2026) ═════════════════════════════════════
t('Investimento: a TV e o Painel leem o valor manual do mês corrente; o mês passado vem do histórico mensal; a fonte é sempre "manual"', function () use ($container, $db) {
    $inv = $container->obter(InvestimentoService::class);
    igual(['investimento' => 1900.0, 'fonte' => 'manual'], $inv->paraTv(2026, 11));
    igual(['investimento' => 1900.0, 'fonte' => 'manual'], $inv->paraPainel(2026, 11, true));
    $db->executar("INSERT INTO investimento_mensal (ano, mes, investimento) VALUES (2026, 10, 987.65)");
    igual(['investimento' => 987.65, 'fonte' => 'manual'], $inv->paraPainel(2026, 10, false));
    igual(['investimento' => 0.0, 'fonte' => 'manual'], $inv->paraPainel(2026, 9, false));
});
t('Investimento: sobras do Meta Ads no banco (conta, token, cache) não mudam nada — nenhum código as lê nem faz chamada de rede', function () use ($container, $db) {
    $inv = $container->obter(InvestimentoService::class);
    $db->executar("INSERT INTO app_settings (setting_key, setting_value) VALUES ('meta_ads_account_id', '123'), ('meta_ads_token', 'TOKEN-SECRETO'), ('meta_ads_spend_cache', '777.55'), ('meta_ads_spend_cache_at', '2026-11-10 10:00:00')");
    igual(['investimento' => 1900.0, 'fonte' => 'manual'], $inv->paraTv(2026, 11));
    igual(['investimento' => 1900.0, 'fonte' => 'manual'], $inv->paraPainel(2026, 11, true));
    $db->executar("DELETE FROM app_settings WHERE setting_key LIKE 'meta_ads_%'");
    verdade(!class_exists('App\\Services\\MetaAdsService') && !class_exists('App\\Services\\ClienteHttp'), 'as classes do Meta Ads foram removidas');
});

// ═══ Férias ═══════════════════════════════════════════════════════════════════════════════════════════
t('Férias: as que terminaram reativam e limpam as datas; as que começaram suspendem; sem datas ou de desligado não mexem', function () use ($container, $db) {
    $db->executar("INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor, deleted_at, suspended_at, ferias_inicio, ferias_fim) VALUES
        (6, 1, 'Fim', 5, 0, '#1', NULL, '2026-10-20 10:00:00', '2026-10-20', '2026-11-09'),
        (7, 1, 'Comeca', 5, 0, '#2', NULL, NULL, '2026-11-10', '2026-11-20'),
        (8, 1, 'Futura', 5, 0, '#3', NULL, NULL, '2026-11-11', '2026-11-20'),
        (9, 1, 'ManualSemDatas', 5, 0, '#4', NULL, '2026-11-01 10:00:00', NULL, NULL),
        (10, 1, 'Desligado', 5, 0, '#5', '2026-11-01 10:00:00', NULL, '2026-11-01', '2026-11-30')");
    $container->obter(FeriasService::class)->sincronizar();
    $linha = fn(int $id) => $db->linha('SELECT suspended_at IS NOT NULL AS susp, ferias_inicio, ferias_fim FROM vendedores WHERE id=?', [$id]);
    igual([0, null, null], array_values($linha(6)), 'terminou ontem: volta e as datas somem');
    igual([1, '2026-11-10', '2026-11-20'], array_values($linha(7)), 'começa hoje: suspende');
    igual([0, '2026-11-11', '2026-11-20'], array_values($linha(8)), 'começa amanhã: fica como está');
    igual([1, null, null], array_values($linha(9)), 'suspensão manual, sem datas, não é tocada');
    igual([0, '2026-11-01', '2026-11-30'], array_values($linha(10)), 'desligado não é tocado');
    $db->executar('DELETE FROM vendedores WHERE id >= 6');
});

// ═══ Ranking por período, Painel, gráficos e Visão do Time ═════════════════════════════════════════
t('Ranking por período (semana): ex-closer com contrato aparece no fim; suspensa fora; por equipe soma os ex-membros', function () use ($container) {
    $s = $container->obter(RankingFiltradoService::class);
    $r = $s->consultar('semana', '1', 'closer');
    igual(['Bruno', 'Ana', 'Fabio', 'Caio'], array_column($r['data'], 'name'));
    igual([true, 2], [$r['data'][3]['ex_closer'], $r['data'][3]['vendas']]);
    $e = $s->consultar('semana', '1', 'equipe');
    igual(['Alpha', 'Beta'], array_column($e['data'], 'name'));
    igual([12, 10, 120], [$e['data'][0]['vendas'], $e['data'][0]['meta'], $e['data'][0]['score']]);
    igual(['success' => false, 'message' => 'Semana não encontrada'], $s->consultar('semana', '9', 'closer'));
    igual(['success' => false, 'message' => 'Parâmetros inválidos'], $s->consultar('xx', '1', 'closer'));
});
t('Ranking por período (mês): pontuação mensal, equipe com ex-membros, período mal formado cai no mês 0 sem erro', function () use ($container) {
    $s = $container->obter(RankingFiltradoService::class);
    $r = $s->consultar('mes', '2026-11', 'closer');
    igual(['Bruno', 'Ana', 'Fabio', 'Caio'], array_column($r['data'], 'name'));
    igual([25, 25, 6], array_slice(array_column($r['data'], 'score_mensal'), 0, 3));
    $e = $s->consultar('mes', '2026-11', 'equipe')['data'];
    igual([13, 40, 33], [$e[0]['vendas_mes'], $e[0]['meta_mes'], $e[0]['score_mensal']]);
    $x = $s->consultar('mes', 'abc', 'closer');
    igual(true, $x['success']);
    igual([0, 0, 0], array_column($x['data'], 'vendas_mes'), 'mês 0 (sem "-" no período): nenhum contrato; o ex-closer sem contrato não aparece');
});
t('Painel: semana atual e mês corrente, ex-closer no fim, totais, CAC manual e mix de benefícios com "outros" expandido', function () use ($container, $db) {
    $db->executar("UPDATE app_settings SET setting_value='1900' WHERE setting_key='investimento_mes'");   // o teste do Meta Ads acima espelhou outro valor
    $p = $container->obter(PainelService::class)->painel(0, 0, 0);
    igual(true, $p['success']);
    igual(['2026-11-04', '2026-11-10', true, '2026-11-10', 1], [$p['week']['start'], $p['week']['end'], $p['week']['is_current'], $p['week']['today'], $p['week']['semana_num']]);
    igual(['Bruno', 'Ana', 'Fabio', 'Caio'], array_column($p['closers'], 'name'));
    igual([false, false, false, true], array_column($p['closers'], 'ex_closer'));
    igual([3, 1, 18, 19], [$p['totals']['closers'], $p['totals']['suspensos'], $p['totals']['semana'], $p['totals']['mes']]);
    igual([100.0, 1900.0, 85.0, 'manual'], [$p['cac']['valor'], $p['cac']['investimento'], $p['cac']['meta'], $p['cac']['fonte']]);
    $tipos = (array) $p['tipos_equipe'];
    igual(['bpc_loas' => 2, 'outros:Pensão X' => 2], $tipos[1]);
    igual(['aux_acidente' => 1], $tipos[2]);
    igual(['Alpha', 'Beta'], array_column($p['teams'], 'nome'));
    igual(30, count($p['month']['dates']));
    igual(['success' => false, 'message' => 'Semana não encontrada'], $container->obter(PainelService::class)->painel(2026, 11, 9));
});
t('Gráficos: semana, mês, ano e dia, com ex-closer marcado "(Ex)", filtro por closer, "outros" expandido; período desconhecido = null', function () use ($container) {
    $g = $container->obter(GraficoService::class);
    $w = $g->dados('week', null, null, null, null, null);
    igual(['04', '05', '06', '07', '08', '09', '10'], $w['labels']);
    igual([2, 3, 0, 0, 0, 0, 4], $w['vendors'][1]);
    igual('Caio (Ex)', $w['vendorNames'][3]['name']);
    $tiposSemana = $w['tipos'];
    ksort($tiposSemana);   // a ordem das linhas do GROUP BY não é garantida
    igual(['aux_acidente' => 1, 'bpc_loas' => 2, 'outros:Pensão X' => 2], $tiposSemana);
    igual(array_keys($w['vendors']), [1, 2, 3, 4, 5], 'Eva (suspensa, sem ser desligada) entra como qualquer closer ativo');
    $um = $g->dados('week', 2, null, null, null, null);
    igual([2], array_keys(array_filter($um['vendors'], fn($v) => array_sum($v) > 0)));
    $m = $g->dados('month', null, 2026, 11, null, null);
    igual(30, count($m['data']));
    igual([1, 2, 4, 5, 2, 5], [$m['data'][1], $m['data'][3], $m['data'][4], $m['data'][5], $m['data'][6], $m['data'][9]], 'dias 2, 4, 5, 6, 7 e 10: Ana 1; Ana 2; Ana 3 + Eva 1; Bruno 5; Caio 2; Ana 4 + Fabio 1');
    igual([10], $m['vendors'][1], 'total do mês de Ana');
    $a = $g->dados('year', null, 2026, null, null, null);
    igual([12, 19], [count($a['data']), $a['data'][10]]);
    igual('Jan', $a['labels'][0]);
    $d = $g->dados('day', null, null, null, null, '2026-11-10');
    igual([[4], [1]], [$d['vendors'][1], $d['vendors'][5]]);
    igual('2026-11-10', $g->dados('day', null, null, null, null, 'lixo')['date'], 'data mal formada vira hoje');
    igual(null, $g->dados('foo', null, null, null, null, null));
});
t('Visão do Time (semana): precisa da semana no Calendário; pontuação limitada a 100; desligado só com contrato e no fim', function () use ($container, $db) {
    $s = $container->obter(ResumoSemanaService::class);
    igual(['success' => false, 'message' => 'Parâmetros inválidos'], $s->semana(0, 11, 1, false));
    igual(['success' => false, 'message' => 'Semana não encontrada'], $s->semana(2026, 11, 1, false));
    $db->executar("INSERT INTO semanas_mes (ano, mes, semana_num, data_inicio, data_fim) VALUES (2026, 11, 1, '2026-11-04', '2026-11-10')");
    $r = $s->semana(2026, 11, 1, false);
    igual(['Bruno', 'Ana', 'Fabio', 'Eva'], array_column($r['data'], 'name'), 'sem "incluir desligados": Caio fora; Eva (suspensa, 0%) entra, depois de Fabio (25%)');
    igual(['Qua 04', 'Qui 05', 'Sex 06', 'Sáb 07', 'Dom 08', 'Seg 09', 'Ter 10'], $r['labels']);
    $todos = $s->semana(2026, 11, 1, true);
    igual(['Bruno', 'Ana', 'Fabio', 'Eva', 'Caio'], array_column($todos['data'], 'name'));
    igual([true, 100], [end($todos['data'])['deleted'], achar($todos['data'], 'Bruno')['score']]);
    $db->executar("INSERT INTO metas_periodo (tipo, ref_id, ano, mes, semana_num, meta) VALUES ('closer', 1, 2026, 11, 1, 3)");
    igual(100, achar($s->semana(2026, 11, 1, false)['data'], 'Ana')['score'], 'Ana: 9 de 3 = 300%, limitado a 100');
    $db->executar('DELETE FROM metas_periodo');
    $db->executar('DELETE FROM semanas_mes');
});
t('MixDeBeneficios::expandir: o "outros" com descrição vira "outros:<descrição>" e somas repetidas se acumulam', function () {
    igual(['bpc_loas' => 3, 'outros' => 1, 'outros:Pensão' => 5], MixDeBeneficios::expandir([
        ['tipo' => 'bpc_loas', 'total' => '1'], ['tipo' => 'bpc_loas', 'tipo_descricao' => '', 'total' => 2], ['tipo' => 'outros', 'tipo_descricao' => '  ', 'total' => 1],
        ['tipo' => 'outros', 'tipo_descricao' => ' Pensão ', 'total' => 2], ['tipo' => 'outros', 'tipo_descricao' => 'Pensão', 'total' => 3]]));
});

// ═══ Equipe oculta da TV (equipes.oculta_tv): só o ranking da TV soma os contratos dela; a equipe vai por último e não disputa ══════
$db->executar('UPDATE equipes SET oculta_tv = 1 WHERE id = 2');   // Beta: Bruno (5 na semana, 1 aux_acidente) e Fabio (1 hoje)
t('Equipe oculta: no ranking da TV os closers dela não aparecem; a equipe vai por último com a soma dos contratos deles', function () use ($container) {
    $d = $container->obter(RankingAoVivoService::class)->dados();
    igual([], array_values(array_intersect(array_column($d['data'], 'name'), ['Bruno', 'Fabio'])), 'nem Bruno nem Fabio entre os closers');
    igual(['Alpha', 'Beta'], array_column($d['teams'], 'nome'), 'Beta (oculta) por último, mesmo com a soma menor');
    igual(['Alpha', 'Beta'], array_column($d['teams_mensal'], 'nome'), 'o mesmo na visão mensal');
    $beta = achar($d['teams'], 'Beta', 'nome');
    igual([1, 6, 2], [$beta['oculta_tv'], $beta['vendas'], $beta['members']], 'soma Bruno 5 + Fabio 1 na semana; os dois são membros da equipe');
    igual([9, 13, 4], [$d['total_semana'], $d['total_mes'], $d['total_hoje']], 'totais da TV só com quem disputa: semana Ana 9; mês 13 (sem Bruno e Fabio); hoje Ana 4');
    igual(19, $d['total_mes_com_ocultas'], 'o total do mês que a campanha soma inclui a equipe oculta: 13 + Bruno 5 + Fabio 1');
});
t('Equipe oculta: os contratos dos closers dela ficam de fora de toda leitura, exceto a da TV (que os pede com o parâmetro)', function () use ($container) {
    $c = $container->obter(\App\Repositories\ContratoRepository::class);
    $v = $container->obter(\App\Repositories\VendedorRepository::class);
    $e = $container->obter(\App\Repositories\EquipeRepository::class);
    igual([1 => 9, 3 => 2, 4 => 1], $c->totaisPorVendedor('2026-11-04', '2026-11-10'), 'sem Bruno e Fabio');
    igual([], array_values(array_intersect(array_keys($c->porVendedorEDia('2026-11-04', '2026-11-10')), [2, 5])), 'porVendedorEDia padrão');
    igual([2, 5], array_values(array_intersect(array_keys($c->porVendedorEDia('2026-11-04', '2026-11-10', true)), [2, 5])), 'com a TV, os dois entram');
    igual(19 - 6, $c->totalDoMes('2026-11'), 'total do mês: 19 menos os 6 da equipe oculta');
    igual(null, $c->tiposPorVendedor('2026-11-04', '2026-11-10')[2] ?? null, 'benefícios de Bruno ficam de fora');
    igual(['aux_acidente' => 1], $c->tiposPorVendedor('2026-11-04', '2026-11-10', true)[2] ?? [], 'com a TV, o benefício de Bruno entra');
    igual([1, 3, 4], array_map('intval', array_column($v->paraRelatorio(), 'id')), 'relatório: Bruno e Fabio não são listados');
    igual([1, 3], array_map('intval', array_column($v->naoSuspensosPorNome(), 'id')), 'lista do ranking por período: sem os da equipe oculta');
    igual([1, 3, 4], array_map('intval', array_column($v->todosParaGrafico(), 'id')), 'gráficos: sem Bruno e Fabio');
    igual([1], array_map('intval', array_column($v->ativosParaRanking(), 'id')), 'ativos que disputam: só Ana');
    igual([[1, 0], [2, 1], [5, 1]], array_map(fn($r) => [(int) $r['id'], (int) $r['equipe_oculta']], $v->ativosParaTv()), 'a TV recebe todos os ativos, com a marca');
    igual([1], array_map('intval', array_column($e->todasParaRanking(), 'id')), 'equipes do ranking: sem Beta');
    igual([1], array_map('intval', array_column($e->todasPorNome(), 'id')), 'equipes do Painel: sem Beta');
    igual([1], array_map('intval', array_column($e->todasParaTv(), 'id')), 'equipes competindo (Hall da Fama): sem Beta');
    $elenco = $container->obter(\App\Services\VendedorService::class)->elenco();
    $ids = array_column($elenco, 'id'); sort($ids);
    igual([1, 2, 5], $ids, 'o admin recebe todos os ativos, inclusive os da equipe oculta (Bruno e Fabio); sem o desligado nem a suspensa');
    igual(['Beta', 'Beta'], [achar($elenco, 'Bruno')['equipe_nome'], achar($elenco, 'Fabio')['equipe_nome']]);
    igual(0, (int) achar($e->comTotais(), 'Beta', 'nome')['total_vendas'], 'a tela de Equipes não mostra o total da equipe oculta');
});
t('Equipe oculta: o closer dela continua registrando contratos; a venda soma no total da equipe da TV e não muda quem disputa', function () use ($container) {
    $r = $container->obter(\App\Actions\Contrato\AdicionarVenda::class)->executar(2, '2026-11-10', 'bpc_loas', 2, '');
    igual(true, $r->sucesso(), 'venda de um closer oculto é aceita: ' . $r->mensagem());
    $d = $container->obter(RankingAoVivoService::class)->dados();
    igual([8], [achar($d['teams'], 'Beta', 'nome')['vendas']], 'Beta: 6 + 2 registrados na semana');
    igual([9, 4], [$d['total_semana'], $d['total_hoje']], 'os totais da TV de quem disputa não mudam');
});
t('Equipe oculta: a matriz de contratos mostra os contratos do closer; o feed não; o closer e o líder dela não têm portal', function () use ($container, $db) {
    $m = $container->obter(\App\Services\MatrizService::class)->matriz('2026-11-04', '2026-11-10', 2);
    igual(1, $m['cells']->{'2026-11-06'}['aux_acidente'] ?? null, 'a matriz do closer oculto mostra o benefício dele');
    igual([], array_values(array_filter($container->obter(\App\Repositories\LancamentoRepository::class)->lancamentosDoDia('2026-11-10'), fn($r) => (int) $r['vendedor_id'] === 2)), 'o feed não mostra os lançamentos da equipe oculta');
    $db->executar("INSERT INTO closer_usuarios (id, vendedor_id, nome, email, senha_hash, status) VALUES (101, 2, 'Bruno', 'bruno@teste', 'x', 'ativo'), (102, 1, 'Ana', 'ana@teste', 'x', 'ativo')");
    $db->executar('UPDATE equipes SET lider_id = 2 WHERE id = 2');   // Bruno lidera a Beta (oculta); Ana lidera a Alpha
    $db->executar('UPDATE equipes SET lider_id = 1 WHERE id = 1');
    $c = $container->obter(\App\Repositories\ContaCloserRepository::class);
    igual([true, false], [$c->pertenceAEquipeOculta(101), $c->pertenceAEquipeOculta(102)], 'a conta de Bruno é de equipe oculta; a de Ana, não');
    $l = $container->obter(\App\Services\LiderEscopoService::class);
    igual([null, 1], [$l->equipeDoLider(101), $l->equipeDoLider(102)], 'o líder da equipe oculta não tem portal de líder; o da equipe normal tem');
});
echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
exit($falharam ? 1 : 0);
