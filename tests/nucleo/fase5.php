<?php
declare(strict_types=1);

/**
 * Testes da fase 5 (closers, equipes, contratos, tipos de benefício, atendimentos e produtividade) com DADOS SINTÉTICOS e relógio congelado em
 * 10/11/2026 10:00 (America/Sao_Paulo, terça-feira: a semana automática é de 04/11 a 10/11).
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\nucleo\fase5.php
 * As tabelas usadas são criadas (vazias) num banco DESCARTÁVEL (nucleo_teste_fase5), apagado no fim: nada do banco de verdade é lido ou alterado.
 * Complementa o golden/diferencial, que comparam o código novo com o antigo ao vivo.
 */

use App\Actions\Atendimento\SalvarAtendimento;
use App\Actions\Atendimento\SalvarAtendimentosEmLote;
use App\Actions\Contrato\AdicionarVenda;
use App\Actions\Contrato\AtualizarVenda;
use App\Actions\Contrato\ExcluirVenda;
use App\Actions\Contrato\SalvarContratos;
use App\Actions\Contrato\SalvarDesempate;
use App\Actions\Contrato\SalvarTiposDoDia;
use App\Actions\Equipe\AtualizarEquipe;
use App\Actions\Equipe\DefinirLiderEquipe;
use App\Actions\Equipe\ExcluirEquipe;
use App\Actions\Tipo\ExcluirTipoCustom;
use App\Actions\Tipo\SalvarTipoCustom;
use App\Actions\Tipo\SalvarTipoFixo;
use App\Actions\Vendedor\AdicionarVendedor;
use App\Actions\Vendedor\ArquivarVendedor;
use App\Actions\Vendedor\AtualizarVendedor;
use App\Actions\Vendedor\AtualizarVendedoresEmLote;
use App\Actions\Vendedor\DefinirFerias;
use App\Actions\Vendedor\MoverVendedor;
use App\Actions\Vendedor\RestaurarVendedor;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Services\ContratoService;
use App\Services\FeedService;
use App\Services\MatrizService;
use App\Services\ProdutividadeService;
use App\Services\Telefone;
use App\Services\Texto;
use App\Services\TiposBeneficioService;

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
$esquema = 'nucleo_teste_fase5';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
foreach (['vendedores', 'equipes', 'contratos', 'contratos_historico', 'contratos_tipos', 'contratos_feed', 'atendimentos', 'tipos_beneficio_custom', 'app_settings',
          'semanas_mes', 'metas_periodo', 'acoes_closer'] as $tabela) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $tabela . '` LIKE `' . DB_NAME . '`.`' . $tabela . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);
$container = new Container();
$container->instancia(Database::class, $db);

// cenário: Alpha (Ana 10/sem, Bruno 10/sem) e Beta (Caio 12/sem)
$db->executar("INSERT INTO equipes (id, nome, cor) VALUES (1,'Alpha','#111111'),(2,'Beta','#222222')");
$ins = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor) VALUES (?,?,?,?,0,?)');
foreach ([[1, 1, 'Ana', 10, '#aaaaaa'], [2, 1, 'Bruno', 10, '#bbbbbb'], [3, 2, 'Caio', 12, '#cccccc']] as $l) {
    $ins->execute($l);
}
$lider = fn(int $eq) => $db->valor('SELECT lider_id FROM equipes WHERE id=?', [$eq]);
$linha = fn(int $id) => $db->linha('SELECT * FROM vendedores WHERE id=?', [$id]);
$total = fn(int $v, string $d) => (int) $db->valor('SELECT COALESCE(SUM(quantidade),0) FROM contratos_historico WHERE vendedor_id=? AND data_registro=?', [$v, $d]);
$HOJE = '2026-11-10';
$ONTEM = '2026-11-09';

// ═══ Texto e telefone ═══════════════════════════════════════════════════════════════════════════════
t('Texto: cortar e tamanho contam letras (UTF-8); limpar tira controle e espaços e limita o tamanho', function () {
    igual('Pensão', Texto::cortar('Pensão Rural', 0, 6));
    igual(12, Texto::tamanho('Pensão Rural'));
    igual('Pensão', Texto::limpar("  Pens\x01ão  ", 6));
    igual('', Texto::limpar("\xff\xfe", 5), 'texto inválido em UTF-8 vira vazio');
    igual('Pe', Texto::limpar('Pensão', 2));
});
t('Telefone::mascarar nunca mostra o número inteiro (celular e fixo)', function () {
    igual('(11) 9****-4321', Telefone::mascarar('11987654321'));
    igual('(11) ****-4321', Telefone::mascarar('1187654321'));
});

// ═══ Closers ═════════════════════════════════════════════════════════════════════════════════════════
t('Cadastro: o closer novo nasce NO PADRÃO (meta que a maioria tem), com um dia de contratos zerado por dia da semana; recusa nome vazio e repetido', function () use ($container, $db, $linha) {
    $a = $container->obter(AdicionarVendedor::class);
    igual('Nome obrigatório', $a->executar('  ', null, 0, '#fff', 1, null)->mensagem());
    $r = $a->executar('  Duda  ', null, 0, '#abcdef', '1', null);
    igual(['Closer adicionado com sucesso', true], [$r->mensagem(), $r->sucesso()]);
    $id = (int) $db->valor("SELECT id FROM vendedores WHERE nome='Duda'");
    $v = $linha($id);
    igual([10, 0, 1, '#abcdef'], [(int) $v['metas'], (int) $v['meta_mensal'], (int) $v['equipe_id'], $v['cor']], 'padrão: moda de 10, 10 e 12');
    verdade($v['img_pravatar'] >= 1 && $v['img_pravatar'] <= 70, 'sem foto: sorteia o avatar');
    igual(7, (int) $db->valor('SELECT COUNT(*) FROM contratos WHERE vendedor_id=?', [$id]));
    igual('Vendedor já existe', $a->executar('DUDA', null, 0, '#fff', null, null)->mensagem());
    $r2 = $a->executar('Edu', '7', '33', '#fff', '0', null);
    $e = $linha((int) $db->valor("SELECT id FROM vendedores WHERE nome='Edu'"));
    igual([7, 33, null], [(int) $e['metas'], (int) $e['meta_mensal'], $e['equipe_id']], 'metas no envio valem; equipe 0 = sem equipe');
});
t('Edição: valida id, nome e repetição; as metas só mudam se vierem no envio', function () use ($container, $linha) {
    $a = $container->obter(AtualizarVendedor::class);
    igual('Dados inválidos', $a->executar(0, 'x', '#fff', null, null, null, null)->mensagem());
    igual('Closer não encontrado', $a->executar(99, 'x', '#fff', null, null, null, null)->mensagem());
    igual('Nome já em uso', $a->executar(1, 'bruno', '#fff', null, null, null, null)->mensagem());
    $a->executar(1, 'Ana Maria', '#123456', '2', '', '', null);
    $v = $linha(1);
    igual(['Ana Maria', '#123456', 2, 10, 0], [$v['nome'], $v['cor'], (int) $v['equipe_id'], (int) $v['metas'], (int) $v['meta_mensal']]);
    $a->executar(1, 'Ana Maria', '#123456', '1', '15', '60', null);
    igual([15, 60], [(int) $linha(1)['metas'], (int) $linha(1)['meta_mensal']]);
});
t('Mover: quem era líder perde a liderança ao sair da equipe; destino inexistente é recusado; ids repetidos contam uma vez', function () use ($container, $db, $lider) {
    $db->executar('UPDATE equipes SET lider_id=2 WHERE id=1');
    $m = $container->obter(MoverVendedor::class);
    igual('Nenhum closer informado', $m->executar('', '1')->mensagem());
    igual('Equipe não encontrada', $m->executar('2', '99')->mensagem());
    $r = $m->executar('2,2,x', '2');
    igual(['Closer transferido', 1], [$r->mensagem(), $r->dados()['moved']]);
    igual(null, $lider(1), 'a liderança da equipe de origem foi removida');
    $r = $m->executar('1,2', '');
    igual('2 closers transferidos', $r->mensagem());
    $m->executar('1,2', '1');
});
t('Ação em lote: precisa de ids e de ao menos uma meta; meta semanal mínima 1, mensal mínima 0', function () use ($container, $linha) {
    $a = $container->obter(AtualizarVendedoresEmLote::class);
    igual('Nenhum closer selecionado', $a->executar('', '5', '5')->mensagem());
    igual('Nada para atualizar', $a->executar('1,2', '', null)->mensagem());
    $r = $a->executar('1,2,99', '-3', '0');
    igual(['Meta atualizada (3 closers)', 2], [$r->mensagem(), $r->dados()['updated']]);
    igual([1, 0], [(int) $linha(2)['metas'], (int) $linha(2)['meta_mensal']]);
    $a->executar('1,2', '', '10');
    igual('Meta atualizada (2 closers)', $a->executar('1,2', '', '10')->mensagem());
});
t('Férias: datas obrigatórias, fim não pode ser anterior nem passado; início hoje entra já, futuro fica agendado (mensagens em dd/mm)', function () use ($container, $db, $linha) {
    $f = $container->obter(DefinirFerias::class);
    igual('Datas inválidas', $f->executar(2, '2026-13-01', '')->mensagem());
    igual('A data final não pode ser anterior à inicial', $f->executar(2, '2026-11-10', '2026-11-09')->mensagem());
    igual('A data final já passou', $f->executar(2, '2026-11-01', '2026-11-09')->mensagem());
    igual('Closer não encontrado', $f->executar(99, '2026-11-10', '')->mensagem());
    igual('Closer em férias — removido do ranking', $f->executar(2, '2026-11-10', '')->mensagem());
    verdade($linha(2)['suspended_at'] !== null);
    igual('Férias registradas — fora do ranking até 20/11', $f->executar(2, '2026-11-01', '2026-11-20')->mensagem());
    igual('Férias agendadas a partir de 15/11', $f->executar(2, '2026-11-15', '2026-11-20')->mensagem());
    igual(null, $linha(2)['suspended_at'], 'início futuro: ainda não está suspenso');
    $db->executar('UPDATE vendedores SET suspended_at=NULL, ferias_inicio=NULL, ferias_fim=NULL WHERE id=2');
});
t('Arquivar: motivo inválido é recusado; guarda o motivo, limpa férias e tira a liderança; reativar volta ativo (com ou sem trocar a equipe)', function () use ($container, $db, $linha, $lider) {
    $db->executar("UPDATE vendedores SET suspended_at=NOW(), ferias_inicio='2026-11-01' WHERE id=2");
    $db->executar('UPDATE equipes SET lider_id=2 WHERE id=1');
    $a = $container->obter(ArquivarVendedor::class);
    igual('Motivo de desligamento inválido', $a->executar(2, 'xx')->mensagem());
    igual('Closer arquivado (histórico preservado)', $a->executar(2, 'demissao')->mensagem());
    $v = $linha(2);
    igual(['demissao', null, null], [$v['desligamento_motivo'], $v['suspended_at'], $v['ferias_inicio']]);
    verdade($v['deleted_at'] !== null && $v['equipe_id'] !== null, 'a equipe continua (atribuição dos contratos)');
    igual(null, $lider(1));
    $r = $container->obter(RestaurarVendedor::class);
    igual('ID inválido', $r->executar(0, null)->mensagem());
    $r->executar(2, '2');
    igual([null, null, 2], [$linha(2)['deleted_at'], $linha(2)['desligamento_motivo'], (int) $linha(2)['equipe_id']]);
    $a->executar(2, '');
    $r->executar(2, '0');
    igual([null, null], [$linha(2)['deleted_at'], $linha(2)['equipe_id']], 'equipe 0 = sem equipe');
    $r->executar(2, '1');
});

// ═══ Equipes ═══════════════════════════════════════════════════════════════════════════════════════════
t('Equipe: o líder precisa ser closer ATIVO da própria equipe; 0 remove; na edição, líder de fora vira "sem líder"', function () use ($container, $db, $lider) {
    $d = $container->obter(DefinirLiderEquipe::class);
    igual('Equipe não encontrada', $d->executar(99, 1)->mensagem());
    igual('O líder precisa ser um closer ativo desta equipe', $d->executar(2, 1)->mensagem());
    igual('Líder definido', $d->executar(1, 1)->mensagem());
    igual(1, (int) $lider(1));
    $e = $container->obter(AtualizarEquipe::class);
    igual('Dados inválidos', $e->executar(0, 'x', '#fff', null, null, null, null, null)->mensagem());
    igual('Equipe não encontrada', $e->executar(99, 'x', '#fff', null, null, null, null, null)->mensagem());
    $e->executar(1, 'Alpha 2', '#999999', null, null, 3, null, null);
    igual(null, $lider(1), 'o Caio é da Beta: o líder vira nulo');
    $e->executar(1, 'Alpha', '#111111', '5', '20', 1, null, null);
    igual([5, 20, 1], [(int) $db->valor('SELECT meta_semanal FROM equipes WHERE id=1'), (int) $db->valor('SELECT meta_mensal FROM equipes WHERE id=1'), (int) $lider(1)]);
    igual('Líder removido', $d->executar(1, 0)->mensagem());
});
t('Equipe oculta da TV: a marca é gravada; null mantém o valor; 0 volta a mostrar (quem disputa o ranking acompanha)', function () use ($container, $db) {
    $e = $container->obter(AtualizarEquipe::class);
    $naTv = fn () => [
        array_map('intval', array_column($container->obter(\App\Repositories\EquipeRepository::class)->todasParaTv(), 'id')),
        array_map('intval', array_column($container->obter(\App\Repositories\VendedorRepository::class)->ativosParaRanking(), 'equipe_id')),
    ];
    [$eq0, $cl0] = $naTv();
    igual(true, in_array(1, $eq0, true) && in_array(1, $cl0, true), 'ponto de partida: a equipe 1 está na TV');
    $e->executar(1, 'Alpha', '#111111', '5', '20', 1, '1', null);
    [$eq1, $cl1] = $naTv();
    igual(1, (int) $db->valor('SELECT oculta_tv FROM equipes WHERE id=1'));
    igual(true, !in_array(1, $eq1, true) && !in_array(1, $cl1, true), 'oculta: a equipe sai das equipes que disputam e os closers dela saem do ranking');
    $e->executar(1, 'Alpha', '#111111', '5', '20', 1, null, null);   // null = não mexe
    igual(1, (int) $db->valor('SELECT oculta_tv FROM equipes WHERE id=1'));
    $e->executar(1, 'Alpha', '#111111', '5', '20', 1, '0', null);
    [$eq2, $cl2] = $naTv();
    igual(true, in_array(1, $eq2, true) && in_array(1, $cl2, true), 'voltou a aparecer');
});
t('Excluir equipe: os closers dela ficam sem equipe e continuam existindo',function () use ($container, $db) {
    $db->executar("INSERT INTO equipes (id, nome, cor) VALUES (9,'Temp','#000000')");
    $db->executar("INSERT INTO vendedores (id, equipe_id, nome, metas, cor) VALUES (50, 9, 'Temp Closer', 5, '#fff')");
    igual('Equipe excluída', $container->obter(ExcluirEquipe::class)->executar(9)->mensagem());
    igual([0, null], [(int) $db->valor('SELECT COUNT(*) FROM equipes WHERE id=9'), $db->valor('SELECT equipe_id FROM vendedores WHERE id=50')]);
});

// ═══ Contratos ═════════════════════════════════════════════════════════════════════════════════════════
t('Matriz (SalvarTiposDoDia): grava só os benefícios ativos, atualiza o total do dia, a semana (vendas/faltam/score) e o feed; benefício desativado fica intacto', function () use ($container, $db, $linha, $total, $HOJE) {
    $db->executar("INSERT INTO tipos_beneficio_custom (chave,label,ativo,sort_order) VALUES ('custom_rural','Rural',1,0),('custom_velho','Velho',0,1)");
    $db->executar("INSERT INTO contratos_tipos (vendedor_id,data_registro,tipo,tipo_descricao,quantidade) VALUES (3,'$HOJE','custom_velho','',4)");
    $a = $container->obter(SalvarTiposDoDia::class);
    igual('Dados inválidos', $a->executar(3, '', [], '')->mensagem());
    $r = $a->executar(3, $HOJE, ['bpc_loas' => '2', 'custom_rural' => '1', 'outros' => '3', 'custom_velho' => '9', 'inexistente' => '7'], '  Pensão  ');
    igual(['Tipos salvos', 10, 10], [$r->mensagem(), $r->dados()['day_total'], $r->dados()['week_total']], '2 + 1 + 3 + 4 do benefício desativado (intacto)');
    igual(4, (int) $db->valor("SELECT quantidade FROM contratos_tipos WHERE vendedor_id=3 AND tipo='custom_velho'"));
    igual('Pensão', $db->valor("SELECT tipo_descricao FROM contratos_tipos WHERE vendedor_id=3 AND tipo='outros'"));
    $v = $linha(3);
    igual([10, 2, 83], [(int) $v['vendas'], (int) $v['faltam'], (int) $v['score']], 'meta semanal 12: faltam 2, score 83');
    igual(10, $total(3, $HOJE));
    igual(10, (int) $db->valor('SELECT SUM(quantidade) FROM contratos_feed WHERE vendedor_id=3 AND data_registro=?', [$HOJE]), 'o feed reflete a matriz');
    igual(10, (int) $db->valor('SELECT quantidade FROM contratos WHERE vendedor_id=3 AND dia_semana=6'), 'espelho legado: 10/11 é o 7º dia (índice 6) da semana de 04/11, com o total do dia');
    $db->executar("DELETE FROM contratos_tipos WHERE tipo='custom_velho'");
    $a->executar(3, $HOJE, [], '');
    $db->executar('DELETE FROM contratos_feed');
});
t('Contratos da semana (SalvarContratos): recusa closer inexistente; grava dia a dia e o total vira vendas/faltam/score', function () use ($container, $linha, $total) {
    $a = $container->obter(SalvarContratos::class);
    igual('Closer não encontrado', $a->executar(99, [0 => 1])->mensagem());
    $r = $a->executar(1, ['0' => '2', '1' => '3', '5' => '1', 'x' => '9']);
    igual(['Contratos salvos', 13], [$r->mensagem(), $r->dados()['total']], 'a chave "x" vira o dia 0 e, por vir depois, vence o 2: 9 + 3 + 1');
    igual(0, $total(1, '2026-11-10'), 'só os dias da semana configurada recebem data');
});
t('Venda rápida: soma ao benefício, registra no feed e valida closer, data, benefício e quantidade (1 a 99)', function () use ($container, $db, $total, $HOJE, $ONTEM) {
    $a = $container->obter(AdicionarVenda::class);
    igual('Closer não encontrado.', $a->executar(99, $HOJE, 'bpc_loas', 1, '')->mensagem());
    igual('Data inválida.', $a->executar(3, '2026-02-31', 'bpc_loas', 1, '')->mensagem());
    igual('Não é possível registrar vendas em data futura.', $a->executar(3, '2026-11-11', 'bpc_loas', 1, '')->mensagem());
    igual('Tipo de benefício inválido.', $a->executar(3, $HOJE, 'nada', 1, '')->mensagem());
    igual('A quantidade deve ser de 1 a 99.', $a->executar(3, $HOJE, 'bpc_loas', 100, '')->mensagem());
    $r = $a->executar(3, $HOJE, 'bpc_loas', 2, '');
    igual(['Venda registrada', 2], [$r->mensagem(), $r->dados()['total_dia']]);
    $a->executar(3, $HOJE, 'outros', 1, "  Rural \x01 X ");
    $r = $a->executar(3, $HOJE, 'outros', 2, 'Maternidade');
    igual(5, $r->dados()['total_dia']);
    igual('Rural  X / Maternidade', $db->valor("SELECT tipo_descricao FROM contratos_tipos WHERE vendedor_id=3 AND tipo='outros' AND data_registro=?", [$HOJE]), 'a descrição de "outros" se acumula (até 100 letras)');
    $id = (int) $db->valor("SELECT id FROM contratos_feed WHERE tipo='bpc_loas' AND data_registro=?", [$HOJE]);
    $u = $container->obter(AtualizarVenda::class);
    igual('Lançamento não encontrado.', $u->executar(999, 3, $HOJE, 'bpc_loas', 1, '')->mensagem());
    $r = $u->executar($id, 3, $ONTEM, 'aux_doenca', 4, '');
    igual(['Venda atualizada', 4], [$r->mensagem(), $r->dados()['total_dia']], 'devolve o total do dia NOVO do lançamento (ontem: 4)');
    igual([3, 4], [$total(3, $HOJE), $total(3, $ONTEM)]);
    $e = $container->obter(ExcluirVenda::class);
    igual('Lançamento não encontrado.', $e->executar(999)->mensagem());
    $r = $e->executar($id);
    igual(['Venda excluída', 0], [$r->mensagem(), $r->dados()['total_dia']]);
    igual(0, $total(3, $ONTEM));
});
t('Feed: lançamento antigo (sem eventos) aparece como "legado", sem hora, e o total por benefício bate com o do dia', function () use ($container, $db, $HOJE) {
    foreach (['contratos_feed', 'contratos_tipos', 'contratos_historico'] as $tabela) {
        $db->executar("DELETE FROM $tabela");
    }
    $db->executar("INSERT INTO contratos_historico (vendedor_id,data_registro,quantidade) VALUES (1,'$HOJE',6)");
    $db->executar("INSERT INTO contratos_tipos (vendedor_id,data_registro,tipo,tipo_descricao,quantidade) VALUES (1,'$HOJE','aux_doenca','',4)");
    $f = $container->obter(FeedService::class)->dia($HOJE);
    igual(1, count($f['items']));
    igual(['legado', true, null], [$f['items'][0]['origem'], array_key_exists('hora', $f['items'][0]), $f['items'][0]['hora']]);
    igual([4, 6, 2], [$f['total_feed'], $f['total_dia'], $f['sem_tipo']], '6 contratos no dia, 4 com benefício: 2 lançados direto, sem benefício');
    igual($HOJE, $container->obter(FeedService::class)->dia('ontem')['data'], 'data inválida vale hoje');
});
t('Matriz de auditoria: período inválido ou maior que 14 dias é recusado; mostra contratos lançados sem benefício e benefício desativado com contratos', function () use ($container, $HOJE) {
    $m = $container->obter(MatrizService::class);
    igual(['success' => false, 'message' => 'Período inválido'], $m->matriz('x', 'y', 0));
    igual('Período muito longo (máx. 14 dias)', $m->matriz('2026-10-01', '2026-11-10', 0)['message']);
    $r = $m->matriz('2026-11-04', $HOJE, 0);
    igual(true, $r['success']);
    igual(7, count($r['dates']));
    verdade(isset($r['sem_tipo']->{$HOJE}), 'dia com contratos lançados direto, sem benefício');
});
t('Desempate: grava a lista como veio (JSON) e recusa o que não for lista', function () use ($container, $db) {
    $a = $container->obter(SalvarDesempate::class);
    igual('Dados inválidos', $a->executar('"texto"')->mensagem());
    igual('Critérios salvos', $a->executar(json_encode(['bpc_loas', ['grupo' => ['aux_doenca']], 'conv_rate'], JSON_UNESCAPED_UNICODE))->mensagem());
    igual('["bpc_loas",{"grupo":["aux_doenca"]},"conv_rate"]', $db->valor("SELECT setting_value FROM app_settings WHERE setting_key='tiebreaker_order'"));
});

// ═══ Tipos de benefício ═══════════════════════════════════════════════════════════════════════════
t('Tipo personalizado: valida nome, tamanho, cor e ícone; cria com chave "custom_..." (sem acento); chave repetida é recusada; ordem 1 põe na frente', function () use ($container, $db) {
    $a = $container->obter(SalvarTipoCustom::class);
    igual('Nome obrigatório', $a->executar(['ativo' => '1'])->mensagem());
    igual('O nome pode ter no máximo 100 caracteres', $a->executar(['label' => str_repeat('x', 101)])->mensagem());
    igual('Cor inválida (use o seletor de cor)', $a->executar(['label' => 'T', 'cor' => 'azul'])->mensagem());
    igual('Ícone inválido', $a->executar(['label' => 'T', 'icone' => 'nao-existe'])->mensagem());
    igual('Tipo não encontrado', $a->executar(['id' => '99', 'label' => 'T'])->mensagem());
    $r = $a->executar(['label' => 'Pensao Morte', 'cor' => '#A1B2C3', 'icone' => 'home']);   // sem acento: a conversão de acentos (iconv) depende do sistema operacional
    igual(['Tipo criado', 'custom_pensao_morte'], [$r->mensagem(), $r->dados()['chave']]);
    igual('#a1b2c3', $db->valor("SELECT cor FROM tipos_beneficio_custom WHERE chave='custom_pensao_morte'"));
    igual('Já existe um tipo com essa chave', $a->executar(['label' => 'Pensao Morte'])->mensagem());
    $a->executar(['label' => 'Primeiro', 'ordem' => '1']);
    $ordem = array_keys($container->obter(TiposBeneficioService::class)->ativos());
    igual('custom_primeiro', $ordem[0]);
    igual('outros', end($ordem), '"Outros" é sempre o último');
    $id = (int) $db->valor("SELECT id FROM tipos_beneficio_custom WHERE chave='custom_pensao_morte'");
    $a->executar(['id' => (string) $id, 'label' => 'Pensao Morte', 'cor' => '', 'icone' => '']);
    igual([null, null], [$db->valor('SELECT cor FROM tipos_beneficio_custom WHERE id=?', [$id]), $db->valor('SELECT icone FROM tipos_beneficio_custom WHERE id=?', [$id])], 'cor e ícone vazios voltam ao automático');
});
t('Benefício fixo: só os do sistema; guarda cor/ícone/posição; vazio limpa; "outros" não muda de posição. Excluir um personalizado o tira da ordem', function () use ($container, $db) {
    $f = $container->obter(SalvarTipoFixo::class);
    igual('Benefício não encontrado', $f->executar(['chave' => 'nada'])->mensagem());
    igual('Cor inválida (use o seletor de cor)', $f->executar(['chave' => 'bpc_loas', 'cor' => '#12'])->mensagem());
    $f->executar(['chave' => 'bpc_loas', 'cor' => '#FF8800', 'icone' => 'star', 'ordem' => '2']);
    igual(['cor' => '#ff8800', 'icone' => 'star'], json_decode((string) $db->valor("SELECT setting_value FROM app_settings WHERE setting_key='tipos_fixos_estilo'"), true)['bpc_loas']);
    $f->executar(['chave' => 'bpc_loas', 'cor' => '', 'icone' => '']);
    igual('{}', $db->valor("SELECT setting_value FROM app_settings WHERE setting_key='tipos_fixos_estilo'"));
    $f->executar(['chave' => 'outros', 'ordem' => '1']);
    igual('outros', array_key_last($container->obter(TiposBeneficioService::class)->ativos()));
    $id = (int) $db->valor("SELECT id FROM tipos_beneficio_custom WHERE chave='custom_primeiro'");
    $x = $container->obter(ExcluirTipoCustom::class);
    igual('ID inválido', $x->executar(0)->mensagem());
    igual('Tipo removido', $x->executar($id)->mensagem());
    verdade(!in_array('custom_primeiro', json_decode((string) $db->valor("SELECT setting_value FROM app_settings WHERE setting_key='tipos_ordem'"), true), true));
});

// ═══ Atendimentos e produtividade ═══════════════════════════════════════════════════════════════════
t('Atendimentos: quantidade 0 apaga o dia; negativa vira 0; em lote ignora datas fora do formato e recusa JSON ruim', function () use ($container, $db, $HOJE, $ONTEM) {
    $a = $container->obter(SalvarAtendimento::class);
    igual('Dados inválidos', $a->executar(1, 'ontem', 3)->mensagem());
    $a->executar(1, $HOJE, 5);
    igual(5, (int) $db->valor('SELECT quantidade FROM atendimentos WHERE vendedor_id=1 AND data_registro=?', [$HOJE]));
    $a->executar(1, $HOJE, -2);
    igual(0, (int) $db->valor('SELECT COUNT(*) FROM atendimentos WHERE vendedor_id=1'), 'negativa vira 0 e apaga');
    $l = $container->obter(SalvarAtendimentosEmLote::class);
    igual('Dados inválidos', $l->executar(1, '{{')->mensagem());
    $l->executar(1, json_encode([$HOJE => 4, $ONTEM => 6, 'ruim' => 9, '2026-11-08' => 0]));
    igual(2, (int) $db->valor('SELECT COUNT(*) FROM atendimentos WHERE vendedor_id=1'));
});
t('Produtividade: o detalhe do closer traz a evolução do mês, a semana em andamento "parcial" e as ações mascaradas; mês inválido é recusado', function () use ($container, $db, $HOJE) {
    $db->executar("INSERT INTO acoes_closer (vendedor_id,tipo,telefone,observacao,registrado_em) VALUES (1,'ligacao','11987654321','x','$HOJE 09:00:00'),(1,'followup','11987654321','y','$HOJE 09:30:00')");
    $p = $container->obter(ProdutividadeService::class);
    $d = $p->detalheDoCloser(1, null, null);
    igual([true, 'Ana Maria', 2026, 11], [$d['success'], $d['closer']['nome'], $d['ano'], $d['mes']]);
    igual(30, count($d['dias']));
    $hoje = array_values(array_filter($d['dias'], fn($x) => $x['data'] === $HOJE))[0];
    igual([1, 1], [$hoje['ligacoes'], $hoje['followups']]);
    igual(['(11) 9****-4321', 'Ligação'], [$d['ultimas'][1]['mascarado'] ?? $d['ultimas'][0]['mascarado'], 'Ligação']);
    igual('Mês inválido', $p->detalheDoCloser(1, 2026, 13)['message']);
    igual('Closer não encontrado', $p->detalheDoCloser(99, null, null)['message']);
    igual(0, $p->detalheDoCloser(0, null, null)['dias'][9]['contratos'], 'closer 0: vale o primeiro por nome, sem contratos próprios (como o api.php)');
    $h = $p->historicoDeAcoes(1, ['tipo' => 'followup']);
    igual([1, false, 'Follow-up'], [$h['total'], $h['has_more'], $h['acoes'][0]['tipo_label']]);
    $h = $p->historicoDeAcoes(1, ['limit' => '1']);
    igual([2, true, 1], [$h['total'], $h['has_more'], count($h['acoes'])]);
});

echo "\n" . $passaram . ' passaram, ' . count($falharam) . " falharam\n";
exit(count($falharam) > 0 ? 1 : 0);
