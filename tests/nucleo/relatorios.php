<?php
declare(strict_types=1);

/**
 * Testes do módulo Relatórios (fase 2) com DADOS SINTÉTICOS e relógio congelado em 10/11/2026 10:00 (America/Sao_Paulo).
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\nucleo\relatorios.php
 * As tabelas usadas são criadas (vazias) num banco DESCARTÁVEL (nucleo_teste_relatorios), apagado no fim: nada do banco de verdade é lido ou alterado.
 * Complementa o golden/diferencial, que comparam o código novo com o antigo nos dados reais.
 */

use App\Actions\Relatorio\GerarPdfRelatorio;
use App\Controllers\RelatorioController;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Services\Calendario;
use App\Services\CustoPorContratoService;
use App\Services\DesempateService;
use App\Services\ImagemService;
use App\Services\RelatorioClosersPdf;
use App\Services\RelatorioService;
use App\Services\ResumoMensalService;
use App\Services\TiposBeneficioService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');
require __DIR__ . '/harness.php';

Relogio::fixar('2026-11-10 10:00:00');

// ── banco DESCARTÁVEL: um esquema novo no MySQL local de teste, com a estrutura (vazia) das tabelas usadas ────────────
// (tabela TEMPORÁRIA não serve: o MySQL não deixa citá-la duas vezes na mesma consulta, e o relatório usa "vendedores" duas vezes).
// Usa o root sem senha do MySQL portátil do ambiente de teste e SÓ roda contra 127.0.0.1. O banco de verdade nunca é lido nem alterado.
$real = new Database();
$real->testar();   // carrega o config.secrets.php (define DB_HOST e DB_NAME)
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este teste só roda contra o MySQL local de teste.\n");
    exit(2);
}
$esquema = 'nucleo_teste_relatorios';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
foreach (['vendedores', 'equipes', 'contratos_historico', 'contratos_tipos', 'metas_periodo', 'investimento_mensal', 'app_settings', 'tipos_beneficio_custom', 'semanas_mes'] as $tabela) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $tabela . '` LIKE `' . DB_NAME . '`.`' . $tabela . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);

// ── dados de teste (novembro/2026; hoje = 10/11, terça) ──────────────────────────────────────────────
$db->executar("INSERT INTO equipes (id, nome, cor, lider_id) VALUES (1, 'Alpha', '#112233', 1)");
$vend = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor, deleted_at, suspended_at) VALUES (?,?,?,?,?,?,?,?)');
foreach ([
    [1, 1, 'Ana', 10, 0, '#abcdef', null, null],                       // ativa, líder da Alpha
    [2, null, 'Bruno', 5, 20, '', null, null],                           // ativo, sem equipe, meta do mês no cadastro (20) e no período (30)
    [3, 1, 'Caio', 8, 0, '#123456', '2026-11-08 10:00:00', null],      // desligado COM contrato na janela
    [4, 1, 'Davi', 7, 0, '#654321', '2026-11-01 10:00:00', null],      // desligado só com linha de quantidade 0: não conta
    [5, 1, 'Eva', 6, 0, '#0a0b0c', null, '2026-11-09 10:00:00'],       // ativa e suspensa, sem contratos
] as $linha) {
    $vend->execute($linha);
}
$hist = $db->pdo()->prepare('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?,?,?)');
foreach ([[1, '2026-11-02', 1], [1, '2026-11-04', 3], [1, '2026-11-05', 2], [1, '2026-11-10', 4], [2, '2026-11-06', 5], [3, '2026-11-07', 2], [4, '2026-11-03', 0]] as $linha) {
    $hist->execute($linha);
}
$tp = $db->pdo()->prepare('INSERT INTO contratos_tipos (vendedor_id, data_registro, tipo, quantidade) VALUES (?,?,?,?)');
foreach ([[1, '2026-11-04', 'bpc_loas', 2], [1, '2026-11-05', 'aux_doenca', 1], [2, '2026-11-06', 'pensao_x', 5]] as $linha) {
    $tp->execute($linha);
}
$db->executar("INSERT INTO tipos_beneficio_custom (chave, label, ativo, sort_order) VALUES ('pensao_x', 'Pensão X', 1, 1)");
$db->executar("INSERT INTO metas_periodo (tipo, ref_id, ano, mes, semana_num, meta) VALUES ('closer', 1, 2026, 11, 1, 8), ('closer', 2, 2026, 11, 0, 30)");
$db->executar('INSERT INTO investimento_mensal (ano, mes, investimento) VALUES (2026, 11, 1500.50)');

// ── monta os serviços (o contêiner liga as dependências, como em produção) ───────────────────────────────
$container = new Container();
$container->instancia(Database::class, $db);
$calendario = $container->obter(Calendario::class);
$relatorio = $container->obter(RelatorioService::class);
$custo = $container->obter(CustoPorContratoService::class);
$resumo = $container->obter(ResumoMensalService::class);

function porNome(array $linhas, string $nome): array
{
    foreach ($linhas as $l) {
        if (($l['nome'] ?? $l['name'] ?? null) === $nome) {
            return $l;
        }
    }
    throw new Exception("não achei $nome");
}

// ═══ Relógio e Calendário ═════════════════════════════════════════════════════════════════════════════
t('Relogio: congelado em America/Sao_Paulo e independente do fuso padrão do PHP', function () {
    igual('2026-11-10', Relogio::hoje());
    igual('America/Sao_Paulo', Relogio::agora()->getTimezone()->getName());
    $padrao = date_default_timezone_get();
    date_default_timezone_set('Asia/Tokyo');
    igual('2026-11-10', Relogio::hoje(), 'mudar o fuso padrão não pode mexer no relógio do sistema');
    date_default_timezone_set($padrao);
    Relogio::fixar('2026-11-10 23:30:00');
    igual('2026-11-10', Relogio::hoje(), 'às 23:30 de Brasília ainda é dia 10 (em UTC já seria 11)');
    Relogio::fixar('2026-11-10 10:00:00');
});
t('Calendario::mesInfo: mês atual, encerrado, futuro; ano/mês inválidos viram o corrente; fevereiro bissexto', function () use ($calendario) {
    $atual = $calendario->mesInfo(2026, 11);
    igual(['atual', 30, 10, '2026-11-01', '2026-11-30'], [$atual['estado'], $atual['dias_mes'], $atual['dias_decorridos'], $atual['ini'], $atual['fim']]);
    $velho = $calendario->mesInfo(2026, 9);
    igual(['encerrado', 30, 30], [$velho['estado'], $velho['dias_mes'], $velho['dias_decorridos']]);
    $futuro = $calendario->mesInfo(2027, 2);
    igual(['futuro', 28, 0], [$futuro['estado'], $futuro['dias_mes'], $futuro['dias_decorridos']]);
    igual(29, $calendario->mesInfo(2028, 2)['dias_mes'], '2028 é bissexto');
    $inv = $calendario->mesInfo(1999, 13);
    igual([2026, 11], [$inv['ano'], $inv['mes']], 'ano 1999 e mês 13 viram o mês corrente');
    igual([2026, 11], [$calendario->mesInfo(0, 0)['ano'], $calendario->mesInfo(0, 0)['mes']], 'sem parâmetros = mês corrente');
    igual(2026, $calendario->mesInfo(2101, 5)['ano'], 'ano acima de 2100 também');
});
t('Calendario::datas: intervalo fechado, um dia só, e ao contrário vazio', function () use ($calendario) {
    igual(['2026-11-30', '2026-12-01', '2026-12-02'], $calendario->datas('2026-11-30', '2026-12-02'));
    igual(['2026-11-10'], $calendario->datas('2026-11-10', '2026-11-10'));
    igual([], $calendario->datas('2026-11-10', '2026-11-09'));
    igual(31, count($calendario->datas('2026-12-01', '2026-12-31')));
});
t('Calendario::semanasDoMes: sem configuração, semanas que começam na quarta (novembro/2026: 4, 11, 18 e 25)', function () use ($calendario) {
    $s = $calendario->semanasDoMes(2026, 11);
    igual(4, count($s));
    igual([1, '2026-11-04', '2026-11-10'], [$s[0]['semana_num'], $s[0]['start'], $s[0]['end']]);
    igual([4, '2026-11-25', '2026-12-01'], [$s[3]['semana_num'], $s[3]['start'], $s[3]['end']], 'a última semana invade dezembro');
});
t('Calendario::semanasDoMes: a configuração do admin (semanas_mes) vale no lugar da automática', function () use ($calendario, $db) {
    $db->executar("INSERT INTO semanas_mes (ano, mes, semana_num, data_inicio, data_fim) VALUES (2026, 12, 2, '2026-12-09', '2026-12-15'), (2026, 12, 1, '2026-12-01', '2026-12-08')");
    $s = $calendario->semanasDoMes(2026, 12);
    igual([1, 2], array_column($s, 'semana_num'), 'ordenadas por número');
    igual(['2026-12-01', '2026-12-09'], array_column($s, 'start'));
});

// ═══ Benefícios: cor, ícone, ordem ═════════════════════════════════════════════════════════════════════
t('TiposBeneficioService::estilo: ordem (fixos, personalizados, Outros), cores por família e ícone pelo nome', function () use ($container) {
    $e = $container->obter(TiposBeneficioService::class)->estilo();
    igual(['bpc_loas', 'sal_maternidade', 'aux_acidente', 'aux_doenca', 'pensao_x', 'outros'], array_keys($e));
    igual(['#60a5fa', '#f472b6', '#fbbf24', '#fb7185', '#a78bfa', '#94a3b8'], array_column($e, 'cor'));
    igual(['heart', 'smile', 'alert', 'activity', 'users', 'more'], array_column($e, 'icone'));
    igual([1, 2, 3, 4, 5, 6], array_column($e, 'ordem'));
    igual(false, $e['bpc_loas']['cor_escolhida']);
    igual(false, $e['pensao_x']['fixo']);
    igual(true, $e['outros']['ativo']);
});
t('TiposBeneficioService: cor escolhida (minúscula e só "#rrggbb" válido), ícone válido e ordem salva pelo admin', function () use ($container, $db) {
    $db->executar("INSERT INTO app_settings (setting_key, setting_value) VALUES ('tipos_fixos_estilo', ?), ('tipos_ordem', ?)", [
        json_encode(['bpc_loas' => ['cor' => '#AABBCC', 'icone' => 'star'], 'aux_doenca' => ['cor' => 'azul', 'icone' => 'inexistente']]),
        json_encode(['aux_doenca', 'nao_existe', 'bpc_loas', 'outros']),
    ]);
    $e = $container->obter(TiposBeneficioService::class)->estilo();
    igual(['aux_doenca', 'bpc_loas', 'sal_maternidade', 'aux_acidente', 'pensao_x', 'outros'], array_keys($e), 'a ordem salva vem primeiro, "outros" sempre por último');
    igual(['#aabbcc', true, 'star', true], [$e['bpc_loas']['cor'], $e['bpc_loas']['cor_escolhida'], $e['bpc_loas']['icone'], $e['bpc_loas']['icone_escolhido']]);
    igual(['#fb7185', false, 'activity', false], [$e['aux_doenca']['cor'], $e['aux_doenca']['cor_escolhida'], $e['aux_doenca']['icone'], $e['aux_doenca']['icone_escolhido']], 'cor e ícone inválidos são ignorados');
    $db->executar("DELETE FROM app_settings WHERE setting_key IN ('tipos_fixos_estilo', 'tipos_ordem')");
});
t('TiposBeneficioService::cor: dois benefícios da mesma família recebem tons diferentes; sem família usa a lista geral', function () {
    $a = TiposBeneficioService::cor('bpc_a');
    $b = TiposBeneficioService::cor('bpc_b', '', [$a => true]);
    igual('#60a5fa', $a);
    igual('#38bdf8', $b);
    $g = TiposBeneficioService::cor('qualquer_coisa');
    verdade(in_array($g, ['#2dd4bf', '#38bdf8', '#c084fc', '#f97316', '#facc15', '#4ade80', '#e879f9', '#f87171', '#818cf8', '#22d3ee'], true), "cor geral $g");
    igual($g, TiposBeneficioService::cor('qualquer_coisa'), 'a cor é estável (crc32 da chave)');
    igual('home', TiposBeneficioService::iconePadrao('rural_x'));
    igual('file', TiposBeneficioService::iconePadrao('zzz'));
    igual('shield', TiposBeneficioService::iconePadrao('x', 'Invalidez'));
});

// ═══ Imagem ═════════════════════════════════════════════════════════════════════════════════════════════
t('ImagemService: "uploads/<arquivo>?v=<data>" quando o arquivo existe; sem ?v quando não existe; null para vazio', function () {
    $pasta = pastaTemp('upl_');
    file_put_contents("$pasta/foto.webp", 'x');
    touch("$pasta/foto.webp", 1700000000);
    Config::definir('app.uploads', $pasta);
    $img = new ImagemService();
    igual('uploads/foto.webp?v=1700000000', $img->url('foto.webp'));
    igual('uploads/sumiu.webp', $img->url('sumiu.webp'));
    igual(null, $img->url(null));
    igual(null, $img->url(''));
    Config::definir('app.uploads', null);
    apagar($pasta);
});

t('ImagemService: sem app.uploads, a pasta padrão é public/uploads (dentro da pasta pública, como as músicas)', function () {
    $raiz = Config::raiz();
    $publico = Config::publico();
    $tmp = pastaTemp('upl_pub_');
    mkdir("$tmp/public/uploads", 0777, true);
    file_put_contents("$tmp/public/uploads/foto.webp", 'x');
    touch("$tmp/public/uploads/foto.webp", 1700000000);
    Config::carregar($tmp, "$tmp/public");
    try {
        igual('uploads/foto.webp?v=1700000000', (new ImagemService())->url('foto.webp'), 'acha a foto em public/uploads');
    } finally {
        Config::carregar($raiz, $publico);
        apagar($tmp);
    }
});

// ═══ Relatório "Performance de Closers" ═══════════════════════════════════════════════════════════════════
$rel = $relatorio->closers(2026, 11);
t('Relatório: cabeçalho do mês e as 4 semanas com o estado de cada uma (hoje = 10/11)', function () use ($rel) {
    igual([2026, 11, '2026-11-10', 'atual', 30, 10], [$rel['ano'], $rel['mes'], $rel['hoje'], $rel['estado'], $rel['dias_mes'], $rel['dias_decorridos']]);
    igual(4, count($rel['weeks']));
    igual(['atual', 'futura', 'futura', 'futura'], array_column($rel['weeks'], 'estado'));
    igual([7, 0, 0, 0], array_column($rel['weeks'], 'dias_decorridos'));
    igual([7, 7, 7, 7], array_column($rel['weeks'], 'dias'));
    verdade(!isset($rel['weeks'][0]['_datas']), 'o campo interno _datas não pode vazar');
});
t('Relatório: quem entra e em que ordem (ativos por nome, ex-closers no fim; desligado sem contrato fica de fora)', function () use ($rel) {
    igual(['Ana', 'Bruno', 'Eva', 'Caio'], array_column($rel['data'], 'nome'));
});
t('Relatório: Ana — semana 1 com meta do período (8) batida, demais com a meta padrão, mês por projeção (10 × 4 = 40)', function () use ($rel) {
    $a = porNome($rel['data'], 'Ana');
    igual([9, 8, true], [$a['semanas'][0]['vendas'], $a['semanas'][0]['meta'], $a['semanas'][0]['bateu']]);
    igual([0, 10, false], [$a['semanas'][1]['vendas'], $a['semanas'][1]['meta'], $a['semanas'][1]['bateu']]);
    igual([10, 40, 'projecao', 25, false], [$a['vendas_mes'], $a['meta_mes'], $a['meta_mes_origem'], $a['score_mes'], $a['bateu_mes']]);
    igual([1, 4], [$a['metas_sem_batidas'], $a['total_semanas']]);
    igual(1, $a['vendas_mes'] - $a['semanas'][0]['vendas'], 'o contrato de 02/11 é do mês, mas não de nenhuma semana');
});
t('Relatório: Ana — mix de benefícios (mês e semana 1) e contratos sem detalhe', function () use ($rel) {
    $a = porNome($rel['data'], 'Ana');
    igual(['bpc_loas' => 2, 'aux_doenca' => 1], (array) $a['tipos']);
    igual(['bpc_loas' => 2, 'aux_doenca' => 1], (array) $a['semanas'][0]['tipos']);
    igual([7, 6], [$a['sem_tipo'], $a['semanas'][0]['sem_tipo']]);
    verdade($a['tipos'] instanceof stdClass && $a['semanas'][1]['tipos'] instanceof stdClass, 'mix vazio continua sendo objeto {} no JSON');
    igual('{}', json_encode($a['semanas'][1]['tipos']));
});
t('Relatório: Ana é líder da Alpha; Eva (mesma equipe) vê a líder mas não é líder; foto ausente = null', function () use ($rel) {
    $a = porNome($rel['data'], 'Ana');
    igual([1, 'Alpha', '#112233', 'Ana', true, null], [$a['equipe_id'], $a['equipe_nome'], $a['equipe_cor'], $a['equipe_lider'], $a['e_lider'], $a['imageUrl']]);
    $e = porNome($rel['data'], 'Eva');
    igual(['Ana', false, true, false], [$e['equipe_lider'], $e['e_lider'], $e['suspenso'], $e['ex_closer']]);
});
t('Relatório: Bruno — meta do período (30) vence a do cadastro (20); sem equipe e sem cor = dourado padrão', function () use ($rel) {
    $b = porNome($rel['data'], 'Bruno');
    igual([5, 30, 'periodo', 17, false], [$b['vendas_mes'], $b['meta_mes'], $b['meta_mes_origem'], $b['score_mes'], $b['bateu_mes']]);
    igual([null, null, '#d4af37'], [$b['equipe_id'], $b['equipe_nome'], $b['cor']]);
    igual([5, 5, true], [$b['semanas'][0]['vendas'], $b['semanas'][0]['meta'], $b['semanas'][0]['bateu']]);
    igual(['pensao_x' => 5], (array) $b['tipos']);
});
t('Relatório: meta do cadastro quando não há meta do período (Caio, projeção 8 × 4 = 32); ex-closer e suspensa marcados', function () use ($rel, $db, $relatorio) {
    $c = porNome($rel['data'], 'Caio');
    igual([2, 32, 'projecao', 6, true], [$c['vendas_mes'], $c['meta_mes'], $c['meta_mes_origem'], $c['score_mes'], $c['ex_closer']]);
    $db->executar("UPDATE vendedores SET meta_mensal = 50 WHERE id = 3");
    $c2 = porNome($relatorio->closers(2026, 11)['data'], 'Caio');
    igual([50, 'cadastro'], [$c2['meta_mes'], $c2['meta_mes_origem']]);
    $db->executar("UPDATE vendedores SET meta_mensal = 0 WHERE id = 3");
});
t('Relatório: lista de benefícios na ordem do admin, com cor, ícone e rótulo; só ativos ou usados', function () use ($rel, $db, $relatorio) {
    igual(['bpc_loas', 'sal_maternidade', 'aux_acidente', 'aux_doenca', 'pensao_x', 'outros'], array_column($rel['tipos'], 'k'));
    igual(['k' => 'pensao_x', 'label' => 'Pensão X', 'cor' => '#a78bfa', 'icone' => 'users', 'ativo' => true], $rel['tipos'][4]);
    // desativa o benefício personalizado: some da lista enquanto ninguém usou; reaparece (inativo) se houver contrato dele no período
    $db->executar("UPDATE tipos_beneficio_custom SET ativo = 0 WHERE chave = 'pensao_x'");
    igual(['pensao_x'], array_column(array_filter($relatorio->closers(2026, 11)['tipos'], fn($t) => $t['k'] === 'pensao_x'), 'k'), 'com contrato no período continua na lista');
    igual(false, array_values(array_filter($relatorio->closers(2026, 11)['tipos'], fn($t) => $t['k'] === 'pensao_x'))[0]['ativo']);
    igual(false, in_array('pensao_x', array_column($relatorio->closers(2026, 10)['tipos'], 'k'), true), 'em outubro (sem contratos dele) some');
    $db->executar("UPDATE tipos_beneficio_custom SET ativo = 1 WHERE chave = 'pensao_x'");
});
t('Relatório: benefício gravado que nem existe mais na lista aparece com a própria chave (não some do total)', function () use ($db, $relatorio) {
    $db->executar("INSERT INTO contratos_tipos (vendedor_id, data_registro, tipo, quantidade) VALUES (2, '2026-11-07', 'fantasma', 1)");
    $t = array_values(array_filter($relatorio->closers(2026, 11)['tipos'], fn($x) => $x['k'] === 'fantasma'));
    igual(1, count($t));
    igual(['fantasma', false, 'file'], [$t[0]['label'], $t[0]['ativo'], $t[0]['icone']]);
    $db->executar("DELETE FROM contratos_tipos WHERE tipo = 'fantasma'");
});
t('Relatório: mês encerrado (outubro) e mês futuro (dezembro): estados e nenhum contrato; semana 1 de dezembro vem da configuração', function () use ($relatorio) {
    $out = $relatorio->closers(2026, 10);
    igual(['encerrado', 31], [$out['estado'], $out['dias_decorridos']]);
    igual(['Ana', 'Bruno', 'Eva'], array_column($out['data'], 'nome'), 'sem contratos em outubro: só os ativos (Caio desligado fica de fora)');
    igual(true, (bool) array_product(array_map(fn($l) => $l['vendas_mes'] === 0, $out['data'])));
    $dez = $relatorio->closers(2026, 12);
    igual('futuro', $dez['estado']);
    igual(['2026-12-01', '2026-12-09'], array_column($dez['weeks'], 'data_inicio'), 'semanas configuradas');
    igual(['futura', 'futura'], array_column($dez['weeks'], 'estado'));
});
t('Relatório: parâmetros inválidos (ano 1999, mês 13) caem no mês corrente', function () use ($relatorio, $rel) {
    $r = $relatorio->closers(1999, 13);
    igual([2026, 11], [$r['ano'], $r['mes']]);
    igual(json_encode($rel['data']), json_encode($r['data']), 'mesmos dados do mês corrente (objetos comparados pelo JSON)');
});

// ═══ Custo por Contrato ═════════════════════════════════════════════════════════════════════════════════════
$c = $custo->mes(2026, 11);
t('Custo por Contrato: totais do mês, CAC, meta e orçamento (meta = soma dos closers ativos: 40 + 30 + 24 = 94)', function () use ($c) {
    igual([17, 94, 1500.5, 85.0], [$c['contratos'], $c['meta_contratos'], $c['investimento'], $c['meta_cac']]);
    igual([88.26, 1.7, 3.13, 7990.0], [$c['cac'], $c['contratos_dia'], $c['meta_contratos_dia'], $c['orcamento']]);
    igual(['atual', 30, 10, 'manual'], [$c['estado'], $c['dias_mes'], $c['dias_decorridos'], $c['fonte']]);
    igual(true, $c['success']);
});
t('Custo por Contrato: série diária acumulada (até hoje) e dias futuros nulos', function () use ($c) {
    igual(30, count($c['serie']));
    $d1 = $c['serie'][0];
    igual([1, 0, 0, 150.05, null], [$d1['dia'], $d1['contratos'], $d1['ctr_acum'], $d1['inv_acum'], $d1['cac_acum']], 'sem contrato acumulado o CAC é null');
    $d2 = $c['serie'][1];
    igual(['2026-11-02', 1, 1, 300.1, 300.1], [$d2['data'], $d2['contratos'], $d2['ctr_acum'], $d2['inv_acum'], $d2['cac_acum']]);
    $d10 = $c['serie'][9];
    igual([4, 17, 1500.5, 88.26], [$d10['contratos'], $d10['ctr_acum'], $d10['inv_acum'], $d10['cac_acum']]);
    igual([null, null, null, null], [$c['serie'][10]['contratos'], $c['serie'][10]['ctr_acum'], $c['serie'][10]['inv_acum'], $c['serie'][10]['cac_acum']]);
    igual([13, 13, 13], [$c['serie'][6]['ctr_acum'], $c['serie'][7]['ctr_acum'], $c['serie'][8]['ctr_acum']], 'dias sem contrato repetem o acumulado');
});
t('Custo por Contrato: a meta "geral" da aba Metas vence a soma dos closers; a fonte é sempre manual (o Meta Ads foi removido)', function () use ($custo, $db) {
    $db->executar("INSERT INTO metas_periodo (tipo, ref_id, ano, mes, semana_num, meta) VALUES ('geral', 0, 2026, 11, 0, 120)");
    igual([120, 10200.0], [$custo->mes(2026, 11)['meta_contratos'], $custo->mes(2026, 11)['orcamento']]);
    $db->executar("INSERT INTO app_settings (setting_key, setting_value) VALUES ('meta_ads_account_id', 'act_1'), ('meta_ads_token', 'tok'), ('meta_cac', '100')");   // sobras do Meta Ads no banco: nada mais as lê
    $m = $custo->mes(2026, 11);
    igual(['manual', 100.0, 12000.0], [$m['fonte'], $m['meta_cac'], $m['orcamento']], 'mesmo com conta e token gravados, a fonte é manual');
    igual('manual', $custo->mes(2026, 10)['fonte'], 'mês encerrado é sempre manual');
    $db->executar("DELETE FROM app_settings WHERE setting_key IN ('meta_ads_account_id', 'meta_ads_token', 'meta_cac')");
    $db->executar("DELETE FROM metas_periodo WHERE tipo = 'geral'");
});
t('Custo por Contrato: mês futuro, sem investimento e sem contratos: tudo zerado e CAC null', function () use ($custo) {
    $f = $custo->mes(2027, 1);
    igual(['futuro', 0, 0, 0.0, null, 0], [$f['estado'], $f['dias_decorridos'], $f['contratos'], $f['investimento'], $f['cac'], $f['contratos_dia']]);
    igual(31, count($f['serie']));
    igual(true, array_reduce($f['serie'], fn($ok, $d) => $ok && $d['contratos'] === null, true));
});

// ═══ Visão do Time — Mês ═══════════════════════════════════════════════════════════════════════════════════════
t('Resumo mensal: só ativos, ordenados por total; metas efetivas (período > cadastro > padrão) e contratos por dia', function () use ($resumo) {
    $r = $resumo->mes(2026, 11, false);
    igual([11, 2026, 30], [$r['month'], $r['year'], $r['daysInMonth']]);
    igual(['Ana', 'Bruno', 'Eva'], array_column($r['data'], 'name'));
    $ana = $r['data'][0];
    igual([10, 10, 30], [$ana['total'], $ana['metas'], count($ana['daily'])]);
    igual([0, 1, 0, 3, 2, 0, 0, 0, 0, 4], array_slice($ana['daily'], 0, 10));
    igual(['bpc_loas' => 2, 'aux_doenca' => 1], $ana['tipo_totals']);
    igual(30, $r['data'][1]['metas'], 'Bruno: meta do período');
    igual([false, null, null], [$ana['deleted'], $ana['img'], $ana['imageUrl']]);
});
t('Resumo mensal: com desligados, entram os que têm linha de contrato no mês (Caio e Davi) depois dos ativos', function () use ($resumo) {
    $r = $resumo->mes(2026, 11, true);
    igual(['Ana', 'Bruno', 'Eva', 'Caio', 'Davi'], array_column($r['data'], 'name'));
    igual([true, true], [$r['data'][3]['deleted'], $r['data'][4]['deleted']]);
    igual([2, 0], [$r['data'][3]['total'], $r['data'][4]['total']]);
});
t('Resumo mensal: mês sem dados, e mês inválido (13) segue a regra do sistema atual (31 dias, intervalo textual)', function () use ($resumo) {
    $r = $resumo->mes(2026, 10, false);
    igual(true, array_sum(array_column($r['data'], 'total')) === 0);
    igual(31, $r['daysInMonth']);
    $inv = $resumo->mes(2026, 13, false);
    igual(31, $inv['daysInMonth'], 'mktime normaliza o mês 13 para janeiro do ano seguinte');
});

// ═══ Desempate ═════════════════════════════════════════════════════════════════════════════════════════════════════
t('DesempateService: ordem padrão, ordem salva e comparação por benefício, por grupo e por taxa de conversão', function () use ($container, $db) {
    $d = $container->obter(DesempateService::class);
    igual(DesempateService::ORDEM_PADRAO, $d->ordem());
    $db->executar("INSERT INTO app_settings (setting_key, setting_value) VALUES ('tiebreaker_order', ?)", [json_encode(['bpc_loas', ['grupo' => ['a', 'b']], 'conv_rate'])]);
    igual(['bpc_loas', ['grupo' => ['a', 'b']], 'conv_rate'], $d->ordem());
    $db->executar("DELETE FROM app_settings WHERE setting_key = 'tiebreaker_order'");
    $x = ['t' => ['bpc_loas' => 3, 'a' => 1, 'b' => 1], 'c' => 0.50];
    $y = ['t' => ['bpc_loas' => 1, 'a' => 4, 'b' => 0], 'c' => 0.80];
    igual(true, $d->comparar($x, $y, 'bpc_loas', 't', 'c') < 0, 'x tem mais BPC: x na frente');
    igual(true, $d->comparar($x, $y, ['grupo' => ['a', 'b']], 't', 'c') > 0, 'no grupo a+b o y tem 4 contra 2: y na frente');
    igual(1, $d->comparar($x, $y, 'conv_rate', 't', 'c'), 'y tem conversão maior: y na frente');
    igual(0, $d->comparar($x, ['t' => [], 'c' => 0.5004], 'conv_rate', 't', 'c'), 'diferença de até 0,001 é empate');
});

t('Relatório: empate de total desempata pelo critério do admin; sem critério salvo, vale a ordem padrão', function () use ($container, $db) {
    $d = $container->obter(DesempateService::class);
    $linhas = [                                                       // Ana 10 (BPC 2, outros 0), Bia 10 (BPC 5), Caio 12, Zoe 10 (BPC 5, outros 3)
        ['id' => 1, 'nome' => 'Ana', 'total' => 10, 'tipos' => ['bpc_loas' => 2, 'outros' => 0]],
        ['id' => 2, 'nome' => 'Bia', 'total' => 10, 'tipos' => ['bpc_loas' => 5, 'outros' => 0]],
        ['id' => 3, 'nome' => 'Caio', 'total' => 12, 'tipos' => []],
        ['id' => 4, 'nome' => 'Zoe', 'total' => 10, 'tipos' => ['bpc_loas' => 5, 'outros' => 3]],
    ];
    $db->executar("INSERT INTO app_settings (setting_key, setting_value) VALUES ('tiebreaker_order', ?)", [json_encode(['outros'])]);
    $p = $d->posicoes($linhas); ksort($p);
    igual([1 => 3, 2 => 4, 3 => 1, 4 => 2], $p, 'critério salvo "outros": Caio 1º (total 12); Zoe (outros 3) antes; Ana e Bia empatam em outros e saem pelo nome');
    $db->executar("DELETE FROM app_settings WHERE setting_key = 'tiebreaker_order'");
    $p = $d->posicoes($linhas); ksort($p);
    igual([1 => 4, 2 => 3, 3 => 1, 4 => 2], $p, 'sem critério salvo: ordem padrão (acidente, BPC, …, outros): Caio 1º; Bia e Zoe (BPC 5) antes de Ana (BPC 2); Zoe antes de Bia por outros 3 a 0');
});

// ═══ PDF ═══════════════════════════════════════════════════════════════════════════════════════════════════════════
function textoDoPdf(string $pdf): string
{
    $saida = '';
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
    foreach ($m[1] as $bruto) {
        $z = @gzuncompress($bruto);
        $saida .= $z === false ? $bruto : $z;
    }
    return $saida;
}
t('PDF: arquivo válido de 1 página, A4 paisagem, com os closers, o total e o rodapé (data do relógio do sistema)', function () use ($rel, $container) {
    $pdf = $container->obter(RelatorioClosersPdf::class)->gerar($rel, '', 'TOP CLOSERS');
    igual('%PDF-1.4', substr($pdf, 0, 8));
    verdade(substr($pdf, -6) === "%%EOF\n", 'termina com %%EOF');
    igual(1, preg_match_all('/\/Type \/Page /', $pdf), 'uma página');
    verdade(strpos($pdf, '/MediaBox [0 0 841.89 595.28]') !== false, 'A4 paisagem');
    $t = textoDoPdf($pdf);
    // o gerador grava os bytes do WinAnsi crus: "á" = 0xE1 e o travessão "—" = 0x97
    foreach (['(Ana)', '(Bruno)', '(Eva)', '(Caio)', '(Gerado em 10/11/2026 10:00', "(P\xe1gina 1 de 1)", "(TOTAL \x97 4 closers)"] as $trecho) {
        verdade(strpos($t, $trecho) !== false, "faltou $trecho no PDF");
    }
    verdade(strpos($pdf, '/Creator (PdfWriter)') !== false);
});
t('PDF: filtro de equipe ("Alpha" = Ana, Eva, Caio; "__sem" = só Bruno) e equipe sem ninguém', function () use ($rel, $container) {
    $g = $container->obter(RelatorioClosersPdf::class);
    $alpha = textoDoPdf($g->gerar($rel, 'Alpha', 'TOP'));
    verdade(strpos($alpha, '(Ana)') !== false && strpos($alpha, '(Bruno)') === false && strpos($alpha, '(Alpha)') !== false);
    $sem = textoDoPdf($g->gerar($rel, '__sem', 'TOP'));
    verdade(strpos($sem, '(Bruno)') !== false && strpos($sem, '(Ana)') === false && strpos($sem, '(Sem equipe)') !== false);
    $nada = textoDoPdf($g->gerar($rel, 'Inexistente', 'TOP'));
    verdade(strpos($nada, 'Nenhum closer com dados neste filtro.') !== false);
});
t('PDF: muitos closers quebram em várias páginas com cabeçalho repetido e "Página x de y"', function () use ($rel, $container) {
    $dados = $rel;
    $base = $dados['data'][0];
    $dados['data'] = [];
    for ($i = 1; $i <= 40; $i++) {
        $linha = $base;
        $linha['nome'] = sprintf('Closer %02d', $i);
        $linha['vendas_mes'] = 100 - $i;
        $dados['data'][] = $linha;
    }
    $pdf = $container->obter(RelatorioClosersPdf::class)->gerar($dados, '', 'TOP');
    $paginas = preg_match_all('/\/Type \/Page /', $pdf);
    verdade($paginas >= 2 && $paginas <= 4, "páginas: $paginas");
    $t = textoDoPdf($pdf);
    igual($paginas, substr_count($t, '(Closer)'), 'o cabeçalho da tabela se repete em toda página');
    verdade(strpos($t, "(P\xe1gina $paginas de $paginas)") !== false);
    verdade(strpos($t, '(Closer 01)') !== false && strpos($t, '(Closer 40)') !== false, 'nenhuma linha se perdeu na quebra');
});
t('PDF: acentos viram WinAnsi (sem mbstring) e texto fora do alfabeto latino vira "?"', function () {
    igual("Jo\xe3o \xc7a", \App\Services\PdfWriter::enc('João Ça'));
    igual('a?b', \App\Services\PdfWriter::enc('a日b'));
    igual("A\xe7\xe3o", \App\Services\PdfWriter::enc('Ação'));
    igual('AÇÃO NÃO', RelatorioClosersPdf::maiusculas('ação não'));
    igual('ok', RelatorioClosersPdf::semaforo(10, 10, 'encerrado', 1.0));
    igual('low', RelatorioClosersPdf::semaforo(1, 10, 'atual', 0.5));
    igual('mid', RelatorioClosersPdf::semaforo(5, 10, 'atual', 0.6), '5 de uma meta de 10 com 60% do tempo = 83% do ritmo');
    igual('low', RelatorioClosersPdf::semaforo(4, 10, 'atual', 0.6), '4 de 10 com 60% do tempo = 67% do ritmo (abaixo de 70%)');
    igual('futura', RelatorioClosersPdf::semaforo(0, 10, 'futura', 0.0));
    igual('none', RelatorioClosersPdf::semaforo(3, 0, 'atual', 0.5));
    igual([255, 0, 128], RelatorioClosersPdf::hex('#ff0080'));
    igual([148, 163, 184], RelatorioClosersPdf::hex('vermelho'), 'cor inválida usa o cinza padrão');
});
t('Action GerarPdfRelatorio: devolve Resultado com os bytes e o nome do arquivo (mês pedido, com fallback do mês inválido)', function () use ($container) {
    $a = $container->obter(GerarPdfRelatorio::class);
    $r = $a->executar(2026, 11, '  Alpha  ');
    verdade($r->sucesso());
    igual('relatorio-closers-2026-11.pdf', $r->dados()['nome']);
    igual('%PDF', substr($r->dados()['pdf'], 0, 4));
    igual('relatorio-closers-2026-11.pdf', $a->executar(1999, 13, '')->dados()['nome']);
    igual('relatorio-closers-2026-10.pdf', $a->executar(2026, 10, '')->dados()['nome']);
});

// ═══ Controller ═════════════════════════════════════════════════════════════════════════════════════════════════════
t('RelatorioController: JSON com success, parâmetros da query, PDF com cabeçalhos de download e valores padrão', function () use ($container) {
    $c = $container->obter(RelatorioController::class);
    $r = $c->closers(req('GET', '/x', [], ['ano' => '2026', 'mes' => '11']));
    igual(200, $r->status());
    $j = json_decode($r->corpo(), true);
    igual([true, 2026, 11, 4], [$j['success'], $j['ano'], $j['mes'], count($j['data'])]);
    igual('application/json; charset=utf-8', $r->cabecalho('Content-Type'));
    $cj = json_decode($c->custoContrato(req('GET', '/x', [], ['ano' => '2026', 'mes' => '11']))->corpo(), true);
    igual([true, 17], [$cj['success'], $cj['contratos']]);
    $pdf = $c->closersPdf(req('GET', '/x', [], ['ano' => '2026', 'mes' => '11', 'equipe' => '__sem']));
    igual(200, $pdf->status());
    igual('application/pdf', $pdf->cabecalho('Content-Type'));
    igual('attachment; filename="relatorio-closers-2026-11.pdf"', $pdf->cabecalho('Content-Disposition'));
    igual((string) strlen($pdf->corpo()), $pdf->cabecalho('Content-Length'));
    igual('private, no-store', $pdf->cabecalho('Cache-Control'));
    $rs = json_decode($c->resumoMensal(req('GET', '/x', [], ['year' => '2026', 'month' => '11', 'include_deleted' => '1']))->corpo(), true);
    igual([true, 5, 30], [$rs['success'], count($rs['data']), $rs['daysInMonth']]);
    $padrao = json_decode($c->resumoMensal(req('GET', '/x'))->corpo(), true);
    igual([11, 2026], [$padrao['month'], $padrao['year']], 'sem parâmetros: o mês corrente do relógio do sistema');
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
if ($falharam) {
    echo 'Falhas: ' . implode(' | ', $falharam) . "\n";
}
exit($falharam ? 1 : 0);
