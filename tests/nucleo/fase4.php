<?php
declare(strict_types=1);

/**
 * Testes da fase 4 (Metas, Calendário e Investimento) com DADOS SINTÉTICOS e relógio congelado em 10/11/2026 10:00 (America/Sao_Paulo).
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\nucleo\fase4.php
 * As tabelas usadas são criadas (vazias) num banco DESCARTÁVEL (nucleo_teste_fase4), apagado no fim: nada do banco de verdade é lido ou alterado.
 * Complementa o golden/diferencial, que comparam o código novo com o antigo ao vivo. O Meta Ads foi removido: o investimento é só o digitado à mão.
 */

use App\Actions\Calendario\ExcluirSemanasMes;
use App\Actions\Calendario\SalvarDiasDaSemana;
use App\Actions\Calendario\SalvarSemanasMes;
use App\Actions\Investimento\ExcluirInvestimentoMensal;
use App\Actions\Investimento\SalvarInvestimentoDoMes;
use App\Actions\Investimento\SalvarInvestimentoMensal;
use App\Actions\Meta\AplicarMetaDiaria;
use App\Actions\Meta\RemoverMetaDiariaExcecao;
use App\Actions\Meta\RemoverMetaExcecao;
use App\Actions\Meta\RemoverMetaExcecaoGrupo;
use App\Actions\Meta\SalvarMetaCac;
use App\Actions\Meta\SalvarMetaDiariaCloser;
use App\Actions\Meta\SalvarMetaExcecao;
use App\Actions\Meta\SalvarMetaExcecaoGrupo;
use App\Actions\Meta\SalvarMetasDiariasMes;
use App\Actions\Meta\SalvarMetasPeriodo;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Services\CalendarioService;
use App\Services\InvestimentoService;
use App\Services\MetaDiaria;
use App\Services\MetasService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');
require __DIR__ . '/harness.php';

Relogio::fixar('2026-11-10 10:00:00');   // terça-feira: a semana automática de 04/11 a 10/11

$real = new Database();
$real->testar();
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este teste só roda contra o MySQL local de teste.\n");
    exit(2);
}
$esquema = 'nucleo_teste_fase4';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
foreach (['vendedores', 'equipes', 'contratos_historico', 'metas_periodo', 'metas_diarias_periodo', 'semanas_mes', 'investimento_mensal', 'app_settings'] as $tabela) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $tabela . '` LIKE `' . DB_NAME . '`.`' . $tabela . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);
$container = new Container();
$container->instancia(Database::class, $db);

// ── cenário: Alpha (Ana 10/sem, Bruno 10/sem, Caio 12/sem) e Beta (Davi 10/sem, suspenso: férias) ──────────────────
$db->executar("INSERT INTO equipes (id, nome, cor, meta_semanal, meta_mensal) VALUES (1,'Alpha','#111111',0,0),(2,'Beta','#222222',0,0)");
$vend = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor, suspended_at) VALUES (?,?,?,?,?,?,?)');
foreach ([[1, 1, 'Ana', 10, 0, '#aaaaaa', null], [2, 1, 'Bruno', 10, 0, '#bbbbbb', null], [3, 1, 'Caio', 12, 0, '#cccccc', null], [4, 2, 'Davi', 10, 0, '#dddddd', '2026-11-09 10:00:00']] as $l) {
    $vend->execute($l);
}
// semanas do mês: 4 automáticas (quartas de novembro/2026: 4, 11, 18, 25)
$metas = $container->obter(MetasService::class);
$cfg = fn(string $k): ?string => $db->linha('SELECT setting_value FROM app_settings WHERE setting_key=?', [$k])['setting_value'] ?? null;

// ═══ Meta diária (regras puras) ══════════════════════════════════════════════════════════════════════
t('MetaDiaria::gerar: uniforme soma exatamente a meta (a sobra vai do último dia para trás); crescente respeita os pesos e a soma', function () {
    $u = MetaDiaria::gerar(100, 30, 'uniforme');
    igual([30, 100], [count($u), array_sum($u)]);
    igual([3, 4], [$u[1], $u[30]], 'sobra de 10 nos 10 últimos dias');
    $c = MetaDiaria::gerar(200, 30, 'crescente');
    igual(200, array_sum($c));
    verdade($c[1] < $c[10] && $c[10] < $c[20] && $c[20] < $c[30], 'cresce ao longo do mês');
    igual(array_fill(1, 5, 0), MetaDiaria::gerar(0, 5, 'uniforme'), 'meta zero: tudo zero');
});
t('MetaDiaria::gerar personalizado ignora a meta do mês e usa só os intervalos (dentro dos dias do mês)', function () {
    igual([1 => 2, 2 => 2, 3 => 5, 4 => 5], MetaDiaria::gerar(999, 4, 'personalizado', [['de' => 1, 'ate' => 2, 'valor' => 2], ['de' => 3, 'ate' => 9, 'valor' => 5]]));
});
t('MetaDiaria::validarIntervalos: pede intervalo, recusa fim antes do início, dia além do mês, valor fora de 0 a 999 e sobreposição', function () {
    igual('Defina ao menos um intervalo de dias.', MetaDiaria::validarIntervalos([], 30));
    verdade(strpos(MetaDiaria::validarIntervalos([['de' => 5, 'ate' => 2, 'valor' => 1]], 30), 'Intervalo inválido') === 0);
    verdade(strpos(MetaDiaria::validarIntervalos([['de' => 1, 'ate' => 31, 'valor' => 1]], 30), 'Intervalo inválido') === 0);
    igual('Informe a meta por dia (0 a 999) em cada intervalo.', MetaDiaria::validarIntervalos([['de' => 1, 'ate' => 2, 'valor' => 1000]], 30));
    igual('Os intervalos não podem se sobrepor (dia 2).', MetaDiaria::validarIntervalos([['de' => 1, 'ate' => 2, 'valor' => 1], ['de' => 2, 'ate' => 3, 'valor' => 1]], 30));
    igual('', MetaDiaria::validarIntervalos([['de' => 1, 'ate' => 2, 'valor' => 0], ['de' => 3, 'ate' => 3, 'valor' => 999]], 30));
});
t('MetaDiaria::comum: a distribuição que 2 ou mais closers têm igual; 1 só closer não é "comum"', function () {
    igual([[1 => 3, 2 => 3], 2], MetaDiaria::comum([1 => [1 => 3, 2 => 3], 2 => [2 => 3, 1 => 3], 3 => [1 => 9]], [1, 2, 3]));
    igual([null, 0], MetaDiaria::comum([1 => [1 => 3], 2 => [1 => 9]], [1, 2]));
});

// ═══ Metas dos closers: padrão × exceção ═════════════════════════════════════════════════════════════
t('Metas: o padrão de cada célula é o valor da maioria (empate: o maior); a meta do mês sem cadastro é meta semanal × nº de semanas', function () use ($metas) {
    $e = $metas->estadoPublico(2026, 11);
    igual([1, 2, 3, 4], array_column($e['semanas'], 'num'));
    igual([10, 10, 10, 10, 40], [$e['padrao'][1], $e['padrao'][2], $e['padrao'][3], $e['padrao'][4], $e['padrao'][0]], 'Ana, Bruno e Davi têm 10: vence o 10');
    igual([1, 3], [$e['excecoes_n'], $e['seguem']], 'só o Caio (12) é exceção entre os 4');
    igual(10, $e['padrao_semanal']);
    verdade(!isset($e['_bases']), 'a resposta pública não traz a chave interna');
    $caio = array_values(array_filter($e['closers'], fn($c) => $c['nome'] === 'Caio'))[0];
    igual([['cel' => 1, 'valor' => 12, 'padrao' => 10], ['cel' => 2, 'valor' => 12, 'padrao' => 10], ['cel' => 3, 'valor' => 12, 'padrao' => 10], ['cel' => 4, 'valor' => 12, 'padrao' => 10], ['cel' => 0, 'valor' => 48, 'padrao' => 40]], $caio['diffs']);
});
t('Metas: salvar exceção de UM closer grava só o que difere do que ele teria; valor igual ao dele apaga a linha; semana inexistente e número ruim são recusados', function () use ($container, $db) {
    $a = $container->obter(SalvarMetaExcecao::class);
    $r = $a->executar(2026, 11, '1', json_encode([1 => 33, 0 => 40]));
    verdade($r->sucesso());
    igual('Exceção salva', $r->mensagem());
    igual([1, 33], [(int) $db->valor("SELECT COUNT(*) FROM metas_periodo WHERE tipo='closer' AND ref_id=1"), (int) $db->valor("SELECT meta FROM metas_periodo WHERE tipo='closer' AND ref_id=1 AND semana_num=1")], 'a meta do mês (40) é igual à dele sem exceção: sem linha');
    igual('Semana inexistente neste mês: S9', $a->executar(2026, 11, '1', json_encode([9 => 5]))->mensagem());
    igual('Use números inteiros maiores que zero nas metas.', $a->executar(2026, 11, '1', json_encode([1 => 'x']))->mensagem());
    igual('Preencha ao menos uma meta.', $a->executar(2026, 11, '1', json_encode([1 => '']))->mensagem());
    igual('Closer não encontrado', $a->executar(2026, 11, '999', json_encode([1 => 5]))->mensagem());
    igual('Dados inválidos', $a->executar(1999, 11, '1', '{}')->mensagem());
});
t('Metas: "todos" aplica a meta a todos os closers (inclusive suspensos) e responde com o estado novo e a contagem', function () use ($container, $db) {
    $r = $container->obter(SalvarMetaExcecao::class)->executar(2026, 11, 'todos', json_encode([2 => 15]));
    igual(['Meta aplicada a todos os closers', 4], [$r->mensagem(), $r->dados()['afetados']]);
    igual(15, (int) $db->valor("SELECT meta FROM metas_periodo WHERE tipo='closer' AND ref_id=4 AND semana_num=2"));
    igual(15, $r->dados()['padrao'][2]);
});
t('Metas: remover a exceção devolve o closer ao padrão (calculado SEM ele) e apaga as linhas que viraram desnecessárias', function () use ($container, $db) {
    $r = $container->obter(RemoverMetaExcecao::class)->executar(2026, 11, 1);
    verdade($r->sucesso());
    igual('Exceção removida', $r->mensagem());
    igual(0, (int) $db->valor("SELECT meta FROM metas_periodo WHERE tipo='closer' AND ref_id=1 AND semana_num=1"), 'a semana 1 volta ao padrão: 10 = meta base dele, sem linha');
    igual('Closer não encontrado', $container->obter(RemoverMetaExcecao::class)->executar(2026, 11, 999)->mensagem());
    igual('Dados inválidos', $container->obter(RemoverMetaExcecao::class)->executar(2026, 11, 0)->mensagem());
    $db->executar('DELETE FROM metas_periodo');
});
t('Metas por período: ignora entrada sem closer/equipe, "geral" vale ref 0, tipo desconhecido vira closer, meta negativa vira 0', function () use ($container, $db) {
    $r = $container->obter(SalvarMetasPeriodo::class)->executar(2026, 11, [
        ['tipo' => 'closer', 'ref_id' => 1, 'semana_num' => 1, 'meta' => 8], ['tipo' => 'closer', 'semana_num' => 1, 'meta' => 4],
        ['tipo' => 'geral', 'ref_id' => 55, 'semana_num' => 0, 'meta' => 900], ['tipo' => 'xx', 'ref_id' => 2, 'semana_num' => '', 'meta' => -4], 'lixo',
    ]);
    verdade($r->sucesso());
    $linhas = $db->consultar('SELECT tipo, ref_id, semana_num, meta FROM metas_periodo ORDER BY tipo, ref_id');
    igual([['closer', 1, 1, 8], ['closer', 2, 0, 0], ['geral', 0, 0, 900]], array_map(fn($l) => array_values($l), $linhas));
    igual('Dados inválidos', $container->obter(SalvarMetasPeriodo::class)->executar(2026, 13, [])->mensagem());
    igual('Dados inválidos', $container->obter(SalvarMetasPeriodo::class)->executar(2026, 11, 'x')->mensagem());
    $db->executar('DELETE FROM metas_periodo');
});

// ═══ Metas das equipes e do Comercial ════════════════════════════════════════════════════════════════
t('Metas de grupo: a soma automática da equipe ignora os suspensos; meta fixa diferente da soma é exceção; valor igual à soma não grava', function () use ($container, $metas, $db) {
    $g = $metas->estadoGrupo('equipe', 2026, 11);
    igual([32, 128], [((array) $g['itens'][0]['auto'])[1], ((array) $g['itens'][0]['auto'])[0]], 'Alpha soma 10+10+12 = 32 por semana e 40+40+48 = 128 no mês');
    igual([3, 0], [$g['itens'][0]['closers'], $g['itens'][1]['closers']], 'Beta só tem o Davi, suspenso: 0 closers ativos');
    $a = $container->obter(SalvarMetaExcecaoGrupo::class);
    $r = $a->executar('equipe', 2026, 11, '1', json_encode([1 => 50, 2 => 32]));
    verdade($r->sucesso());
    igual(1, (int) $db->valor("SELECT COUNT(*) FROM metas_periodo WHERE tipo='equipe' AND ref_id=1"), 'a semana 2 (32) é igual à soma automática: sem linha');
    igual(1, $r->dados()['excecoes_n']);
    $r2 = $a->executar('equipe', 2026, 11, '1', json_encode([1 => null]));
    igual(0, (int) $db->valor("SELECT COUNT(*) FROM metas_periodo WHERE tipo='equipe' AND ref_id=1"), 'null volta ao automático');
    igual('Equipe não encontrada', $a->executar('equipe', 2026, 11, '99', json_encode([1 => 5]))->mensagem());
    igual('Dados inválidos', $a->executar('x', 2026, 11, '1', '{}')->mensagem());
    igual('Altere ao menos um valor.', $a->executar('geral', 2026, 11, '0', '{}')->mensagem());
});
t('Metas de grupo: a meta fixa antiga do cadastro da equipe vira meta fixa deste mês (e o cadastro zera); remover volta tudo ao automático', function () use ($container, $metas, $db) {
    $db->executar('UPDATE equipes SET meta_semanal=40, meta_mensal=150 WHERE id=1');
    $g = $metas->estadoGrupo('equipe', 2026, 11);
    igual([40, 150], [((array) $g['itens'][0]['fixas'])[1], ((array) $g['itens'][0]['fixas'])[0]], 'o legado conta como meta fixa em todos os meses');
    $container->obter(SalvarMetaExcecaoGrupo::class)->executar('equipe', 2026, 11, '1', json_encode([1 => 60]));
    igual([0, 0], [(int) $db->valor('SELECT meta_semanal FROM equipes WHERE id=1'), (int) $db->valor('SELECT meta_mensal FROM equipes WHERE id=1')], 'o cadastro foi zerado');
    igual(60, (int) $db->valor("SELECT meta FROM metas_periodo WHERE tipo='equipe' AND ref_id=1 AND semana_num=1"));
    igual(40, (int) $db->valor("SELECT meta FROM metas_periodo WHERE tipo='equipe' AND ref_id=1 AND semana_num=2"), 'as outras células do legado foram gravadas neste mês');
    $r = $container->obter(RemoverMetaExcecaoGrupo::class)->executar('equipe', 2026, 11, 1);
    verdade($r->sucesso());
    igual(0, (int) $db->valor("SELECT COUNT(*) FROM metas_periodo WHERE tipo='equipe' AND ref_id=1"));
    igual('Equipe não encontrada', $container->obter(RemoverMetaExcecaoGrupo::class)->executar('equipe', 2026, 11, 99)->mensagem());
    $container->obter(SalvarMetaExcecaoGrupo::class)->executar('geral', 2026, 11, '0', json_encode([0 => 900]));
    igual(900, (int) $db->valor("SELECT meta FROM metas_periodo WHERE tipo='geral' AND ref_id=0 AND semana_num=0"));
    $container->obter(RemoverMetaExcecaoGrupo::class)->executar('geral', 2026, 11, 0);
    igual(0, (int) $db->valor("SELECT COUNT(*) FROM metas_periodo WHERE tipo='geral'"));
});

// ═══ Meta diária: templates e exceções ═══════════════════════════════════════════════════════════════
t('Meta diária: aplicar o template substitui a série de TODOS os closers, guarda o que foi aplicado e conta as exceções substituídas', function () use ($container, $db, $cfg) {
    $db->executar("INSERT INTO metas_diarias_periodo (closer_id, ano, mes, dia, meta) VALUES (1, 2026, 11, 3, 9)");
    $r = $container->obter(AplicarMetaDiaria::class)->executar(2026, 11, 'uniforme', '');
    verdade($r->sucesso());
    igual(['Template aplicado a 4 closers', 4, 1], [$r->mensagem(), $r->dados()['aplicados'], $r->dados()['excecoes_substituidas']]);
    igual(30, (int) $db->valor('SELECT COUNT(*) FROM metas_diarias_periodo WHERE closer_id=1 AND mes=11'));
    igual(40, (int) $db->valor('SELECT SUM(meta) FROM metas_diarias_periodo WHERE closer_id=1 AND mes=11'), 'a soma é a meta do mês do closer (40)');
    $j = json_decode((string) $cfg('meta_diaria_aplicada_2026_11'), true);
    igual(['uniforme', ['1' => 40, '2' => 40, '3' => 48, '4' => 40]], [$j['tipo'], $j['base']]);
    $e = $container->obter(MetasService::class)->estadoDiario(2026, 11);
    igual([0, 4], [$e['excecoes_n'], $e['seguem']], 'todos seguem o template');
    igual(30, $e['dias']);
});
t('Meta diária: editar a série de um closer vira exceção; remover a exceção volta ao template; entradas ruins são recusadas', function () use ($container) {
    $s = $container->obter(SalvarMetaDiariaCloser::class);
    $r = $s->executar(2026, 11, 1, json_encode([1 => 9, 2 => '', 3 => 0, 99 => 5]));
    igual([true, 2], [$r->sucesso(), $r->dados()['dias_com_meta']], 'dia vazio e dia fora do mês são ignorados');
    $e = $container->obter(MetasService::class)->estadoDiario(2026, 11);
    $ana = $e['closers'][0];
    igual([true, 'diferente'], [$ana['excecao'], $ana['motivo']]);
    igual('Use números de 0 a 999 nas metas diárias.', $s->executar(2026, 11, 1, json_encode([1 => 5000]))->mensagem());
    igual('Closer não encontrado', $s->executar(2026, 11, 999, '{}')->mensagem());
    igual('Dados inválidos', $s->executar(2026, 11, 0, '{}')->mensagem());
    $container->obter(RemoverMetaDiariaExcecao::class)->executar(2026, 11, 1);
    igual(0, $container->obter(MetasService::class)->estadoDiario(2026, 11)['excecoes_n'], 'de volta ao template');
    igual('Closer não encontrado', $container->obter(RemoverMetaDiariaExcecao::class)->executar(2026, 11, 999)->mensagem());
});
t('Meta diária: o template personalizado valida os intervalos antes de gravar qualquer coisa; as metas diárias do mês aceitam JSON e ignoram dia fora do mês', function () use ($container, $db) {
    $a = $container->obter(AplicarMetaDiaria::class);
    $antes = (int) $db->valor('SELECT COUNT(*) FROM metas_diarias_periodo');
    igual('Defina ao menos um intervalo de dias.', $a->executar(2026, 11, 'personalizado', '[]')->mensagem());
    igual($antes, (int) $db->valor('SELECT COUNT(*) FROM metas_diarias_periodo'), 'recusado: nada foi gravado');
    igual('Dados inválidos', $a->executar(2026, 11, 'xx', '')->mensagem());
    verdade($a->executar(2026, 11, 'personalizado', json_encode([['de' => 1, 'ate' => 10, 'valor' => 2]]))->sucesso());
    igual(10, (int) $db->valor('SELECT COUNT(*) FROM metas_diarias_periodo WHERE closer_id=2'));
    $m = $container->obter(SalvarMetasDiariasMes::class);
    verdade($m->executar(2026, 11, json_encode([['closer_id' => 2, 'dia' => 1, 'meta' => 7], ['closer_id' => 2, 'dia' => 40, 'meta' => 1], ['closer_id' => 0, 'dia' => 2, 'meta' => 1], ['closer_id' => 2, 'dia' => 11, 'meta' => -3]]))->sucesso());
    igual([7, 0], [(int) $db->valor('SELECT meta FROM metas_diarias_periodo WHERE closer_id=2 AND dia=1'), (int) $db->valor('SELECT meta FROM metas_diarias_periodo WHERE closer_id=2 AND dia=11')], 'meta negativa vira 0');
    igual('Dados inválidos', $m->executar(2026, 13, '[]')->mensagem());
});

// ═══ Calendário ══════════════════════════════════════════════════════════════════════════════════════
t('Calendário: sem semanas configuradas valem as automáticas (quartas do mês) com as datas de cada dia', function () use ($container) {
    $c = $container->obter(CalendarioService::class)->doMes(2026, 11);
    igual(false, $c['configured']);
    igual([4, 11, 18, 25], array_map(fn($w) => (int) substr($w['data_inicio'], 8, 2), $c['data']));
    igual(['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10'], $c['data'][0]['dates']);
    igual([false], array_unique(array_column($c['data'], 'configured')));
});
t('Calendário: salvar semanas troca todas as do mês e ignora a que não tem datas válidas ou número; excluir volta ao automático', function () use ($container, $db) {
    $a = $container->obter(SalvarSemanasMes::class);
    $r = $a->executar(2026, 11, json_encode([
        ['semana_num' => 1, 'data_inicio' => '2026-11-02', 'data_fim' => '2026-11-08'], ['semana_num' => 2, 'data_inicio' => 'sem-formato', 'data_fim' => '2026-11-15'],
        ['data_inicio' => '2026-11-16', 'data_fim' => '2026-11-22'], ['semana_num' => 0, 'data_inicio' => '2026-11-23', 'data_fim' => '2026-11-29'], ['semana_num' => 5, 'data_inicio' => '', 'data_fim' => '2026-11-30'],
    ]));
    igual(['Semanas salvas para 11/2026'], [$r->mensagem()]);
    igual([[1, '2026-11-02'], [3, '2026-11-16']], array_map(fn($l) => [(int) $l['semana_num'], $l['data_inicio']], $db->consultar('SELECT semana_num, data_inicio FROM semanas_mes ORDER BY semana_num')), 'sem número usa a posição (3); 2, 0 e 5 saem');
    $c = $container->obter(CalendarioService::class);
    igual(true, $c->doMes(2026, 11)['configured']);
    igual([['ano' => 2026, 'mes' => 11, 'total_semanas' => 2, 'primeiro_dia' => '2026-11-02', 'ultimo_dia' => '2026-11-22']], $c->historico());
    igual('Dados inválidos', $a->executar(0, 11, '[]')->mensagem());
    igual('Dados inválidos', $a->executar(2026, 11, '{{')->mensagem());
    verdade($container->obter(ExcluirSemanasMes::class)->executar(2026, 11)->sucesso());
    igual(0, (int) $db->valor('SELECT COUNT(*) FROM semanas_mes'));
    igual('Dados inválidos', $container->obter(ExcluirSemanasMes::class)->executar(2026, 0)->mensagem());
});
t('Calendário: os dias do ciclo vão de 1 a 14, começam na quarta da semana atual e repetem os rótulos', function () use ($container, $cfg) {
    $a = $container->obter(SalvarDiasDaSemana::class);
    $r = $a->executar(9);
    igual(['Qua', 'Qui', 'Sex', 'Sáb', 'Dom', 'Seg', 'Ter', 'Qua', 'Qui'], $r->dados()['weekLabels']);
    igual(['04', '05', '06', '07', '08', '09', '10', '11', '12'], $r->dados()['weekDates']);
    igual('9', $cfg('week_days'));
    igual(14, $a->executar(99)->dados()['weekDays']);
    igual(1, $a->executar(0)->dados()['weekDays']);
    igual(7, $a->executar(null)->dados()['weekDays'], 'sem valor vale 7');
});

// ═══ Investimento (só o digitado à mão) ══════════════════════════════════════════════════════════════
t('Investimento: salvar o mês corrente grava o mês e o valor avulso "investimento_mes"; CPM e frequência saem das impressões e do alcance', function () use ($container, $db, $cfg) {
    $r = $container->obter(SalvarInvestimentoMensal::class)->executar(2026, 11, '1234,56', '50000', '120000', '', '', '  campanha  ');
    igual(['Investimento salvo', 10.29, 2.4], [$r->mensagem(), $r->dados()['cpm'], $r->dados()['frequencia']]);
    igual('1234.56', $cfg('investimento_mes'));
    igual(['campanha', 1234.56], [$db->valor('SELECT obs FROM investimento_mensal WHERE ano=2026 AND mes=11'), (float) $db->valor('SELECT investimento FROM investimento_mensal WHERE ano=2026 AND mes=11')]);
    $container->obter(SalvarInvestimentoMensal::class)->executar(2026, 10, '900', 0, 0, '7,5', '2', '0');
    igual('1234.56', $cfg('investimento_mes'), 'mês passado não mexe no valor avulso');
    igual([null, 7.5], [$db->valor('SELECT obs FROM investimento_mensal WHERE ano=2026 AND mes=10'), (float) $db->valor('SELECT cpm FROM investimento_mensal WHERE ano=2026 AND mes=10')], 'obs "0" vira null; CPM informado vale');
    igual('Mês inválido', $container->obter(SalvarInvestimentoMensal::class)->executar(2026, 13, '1', 0, 0, 0, 0, '')->mensagem());
    igual('Mês inválido', $container->obter(SalvarInvestimentoMensal::class)->executar(0, 5, '1', 0, 0, 0, 0, '')->mensagem());
});
t('Investimento: o histórico traz meses com contrato OU investimento, CAC só com os dois, e o mês corrente conta só os dias decorridos', function () use ($container, $db) {
    $db->executar("INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (1, '2026-11-03', 4), (1, '2026-11-05', 6), (1, '2026-09-10', 3)");
    $h = $container->obter(InvestimentoService::class)->historico();
    igual(['2026-09', '2026-10', '2026-11'], array_column($h['data'], 'ym'));
    igual(85.0, $h['meta_cac'], 'padrão 85');
    $set = $h['data'][0];
    igual([3, 0.0, null], [$set['contratos'], $set['investimento'], $set['cac']], 'só contrato: sem CAC');
    $out = $h['data'][1];
    igual([0, 900.0, null, 7.5], [$out['contratos'], $out['investimento'], $out['cac'], $out['cpm']], 'só investimento: sem CAC');
    $nov = $h['data'][2];
    igual([true, 10, 10, 1234.56, 123.46, 1.0], [$nov['parcial'], $nov['dias'], $nov['contratos'], $nov['investimento'], $nov['cac'], $nov['contratos_dia']], '10 contratos em 10 dias decorridos (e não em 30)');
    igual(['2026-11', 50000, 120000, 'campanha'], [$nov['ym'], $nov['alcance'], $nov['impressoes'], $nov['obs']]);
});
t('Investimento: a TV e o Painel leem o valor manual; o Painel lê o histórico nos meses passados; excluir o mês corrente zera o valor avulso', function () use ($container, $cfg) {
    $svc = $container->obter(InvestimentoService::class);
    igual(['investimento' => 1234.56, 'fonte' => 'manual'], $svc->paraTv(2026, 11));
    igual(['investimento' => 1234.56, 'fonte' => 'manual'], $svc->paraPainel(2026, 11, true));
    igual(['investimento' => 900.0, 'fonte' => 'manual'], $svc->paraPainel(2026, 10, false));
    verdade($container->obter(ExcluirInvestimentoMensal::class)->executar(2026, 11)->sucesso());
    igual('0', $cfg('investimento_mes'));
    igual(['investimento' => 0.0, 'fonte' => 'manual'], $svc->paraTv(2026, 11));
    igual('Mês inválido', $container->obter(ExcluirInvestimentoMensal::class)->executar(2026, 0)->mensagem());
    $container->obter(SalvarInvestimentoDoMes::class)->executar('2000.75');
    igual('2000.75', $cfg('investimento_mes'));
    $container->obter(SalvarInvestimentoDoMes::class)->executar('-3');
    igual('0', $cfg('investimento_mes'), 'negativo vira 0');
});
t('Meta de CAC: aceita vírgula, negativo vira 0 e o histórico passa a mostrar o valor', function () use ($container) {
    $a = $container->obter(SalvarMetaCac::class);
    igual('Meta de CAC salva', $a->executar('92,5')->mensagem());
    igual(92.5, $container->obter(InvestimentoService::class)->historico()['meta_cac']);
    $a->executar('-5');
    igual(0.0, $container->obter(InvestimentoService::class)->historico()['meta_cac']);
});
t('Meta Ads removido: as classes não existem mais e nenhum arquivo do núcleo menciona "meta_ads"', function () use ($RAIZ) {
    verdade(!class_exists('App\\Services\\MetaAdsService') && !class_exists('App\\Services\\ClienteHttp'));
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($RAIZ . '/app', FilesystemIterator::SKIP_DOTS));
    $achou = [];
    foreach ($it as $arq) {
        if ($arq->isFile() && substr($arq->getFilename(), -4) === '.php' && preg_match('/meta_ads|MetaAds|graph\.facebook/i', (string) file_get_contents($arq->getPathname()))) {
            $achou[] = substr($arq->getPathname(), strlen($RAIZ));
        }
    }
    igual([], $achou, 'arquivos que ainda citam o Meta Ads');
});

echo "\n" . $passaram . ' passaram, ' . count($falharam) . " falharam\n";
exit(count($falharam) > 0 ? 1 : 0);
