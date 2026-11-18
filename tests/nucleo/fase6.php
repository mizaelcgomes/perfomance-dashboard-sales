<?php
declare(strict_types=1);

/**
 * Testes da fase 6 (portal do closer) com DADOS SINTÉTICOS e relógio congelado em 10/11/2026 10:00 (America/Sao_Paulo, terça-feira: a semana
 * automática é de 04/11 a 10/11).
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\nucleo\fase6.php
 * As tabelas usadas são criadas (vazias) num banco DESCARTÁVEL (nucleo_teste_fase6), apagado no fim: nada do banco de verdade é lido ou alterado.
 * Complementa o golden, que compara as respostas do portal com as do código de antes.
 */

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Core\Session;
use App\Repositories\ContaCloserRepository;
use App\Services\AcoesCloserService;
use App\Services\AutenticacaoCloserService;
use App\Services\ContratosCloserService;
use App\Services\IconesPortal;
use App\Services\LimiteDeTentativas;

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
$esquema = 'nucleo_teste_fase6';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($admin, $esquema): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
});
foreach (['vendedores', 'equipes', 'acoes_closer', 'semanas_mes', 'closer_usuarios', 'closer_convites', 'closer_remember', 'contratos_historico', 'contratos_tipos',
          'metas_periodo', 'metas_diarias_periodo', 'app_settings', 'tipos_beneficio_custom'] as $tabela) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $tabela . '` LIKE `' . DB_NAME . '`.`' . $tabela . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);
$container = new Container();
$container->instancia(Database::class, $db);

$db->executar("INSERT INTO equipes (id, nome, cor) VALUES (1,'Alpha','#111111')");
$db->executar("INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor) VALUES (1,1,'Ana',10,0,'#aaaaaa'),(2,1,'Bruno',10,0,'#bbbbbb')");
$servico = $container->obter(AcoesCloserService::class);

const ANA = 1;
const BRUNO = 2;
const TEL = '(11) 98765-4321';

t('Registrar uma ação: devolve a ação, o resumo do dia e grava só para o closer', function () use ($servico, $db) {
    $r = $servico->criar(ANA, ['telefone' => TEL, 'tipo' => 'ligacao', 'observacao' => '  voltar às 15h  ']);
    igual('Ação registrada!', $r['message']);
    igual(['ligacao', 'Ligação', '2026-11-10', '10:00', '(11) 9****-4321', 'voltar às 15h'], [$r['acao']['tipo'], $r['acao']['tipo_label'], $r['acao']['data'], $r['acao']['hora'], $r['acao']['mascarado'], $r['acao']['observacao']]);
    igual(['ligacao' => 1, 'followup' => 0, 'lead_quente' => 0, 'total' => 1], $r['resumo']);
    igual([1, 0], [(int) $db->valor('SELECT COUNT(*) FROM acoes_closer WHERE vendedor_id=1'), (int) $db->valor('SELECT COUNT(*) FROM acoes_closer WHERE vendedor_id=2')]);
});
t('Registrar: valida o telefone, o tipo e o momento, nessa ordem, e diz qual campo errou', function () use ($servico) {
    $e = $servico->criar(ANA, ['telefone' => '123', 'tipo' => 'ligacao']);
    igual(['campo' => 'telefone', 'status' => 200], ['campo' => $e['campo'], 'status' => $e['status']]);
    igual('tipo', $servico->criar(ANA, ['telefone' => TEL, 'tipo' => 'xx'])['campo']);
    igual('registrado_em', $servico->criar(ANA, ['telefone' => TEL, 'tipo' => 'ligacao', 'registrado_em' => 'ontem'])['campo']);
    igual('A data/hora não pode estar no futuro.', $servico->criar(ANA, ['telefone' => TEL, 'tipo' => 'ligacao', 'registrado_em' => '2026-11-10T11:00'])['erro']);
    igual('Só é possível registrar ações dos últimos 90 dias.', $servico->criar(ANA, ['telefone' => TEL, 'tipo' => 'ligacao', 'registrado_em' => '2026-07-01T10:00'])['erro']);
    $ok = $servico->criar(ANA, ['telefone' => TEL, 'tipo' => 'followup', 'registrado_em' => '2026-11-09T14:30']);
    igual(['2026-11-09', '14:30'], [$ok['acao']['data'], $ok['acao']['hora']], 'um momento passado dentro dos 90 dias vale');
});
t('Isolamento: um closer não lê, edita nem apaga a ação de outro (404), e a ação continua lá', function () use ($servico, $db) {
    $id = (int) $db->valor('SELECT id FROM acoes_closer WHERE vendedor_id=1 ORDER BY id LIMIT 1');
    igual(404, $servico->obter(BRUNO, $id)['status']);
    igual(404, $servico->editar(BRUNO, ['id' => $id, 'telefone' => TEL, 'tipo' => 'ligacao', 'registrado_em' => '2026-11-10 09:00'])['status']);
    igual(404, $servico->excluir(BRUNO, $id)['status']);
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM acoes_closer WHERE id=?', [$id]));
    $a = $servico->obter(ANA, $id)['acao'];
    igual(['(11) 98765-4321', '2026-11-10T10:00'], [$a['telefone'], $a['dt_local']], 'o dono vê o telefone inteiro e o momento no formato do campo');
});
t('Editar e excluir a própria ação: atualiza, devolve o resumo do dia e apaga de verdade', function () use ($servico, $db) {
    $id = (int) $db->valor('SELECT id FROM acoes_closer WHERE vendedor_id=1 ORDER BY id LIMIT 1');
    $r = $servico->editar(ANA, ['id' => $id, 'telefone' => '11 91234-5678', 'tipo' => 'lead_quente', 'observacao' => '', 'registrado_em' => '2026-11-10T09:15']);
    igual('Ação atualizada.', $r['message']);
    igual(['lead_quente', '11912345678', null, '2026-11-10 09:15:00'], array_values($db->linha('SELECT tipo, telefone, observacao, registrado_em FROM acoes_closer WHERE id=?', [$id])));
    igual('Data/hora inválida.', $servico->editar(ANA, ['id' => $id, 'telefone' => TEL, 'tipo' => 'ligacao', 'registrado_em' => ''])['erro'], 'editar exige o momento');
    $x = $servico->excluir(ANA, $id);
    igual(['Ação excluída.', 0], [$x['message'], (int) $db->valor('SELECT COUNT(*) FROM acoes_closer WHERE id=?', [$id])]);
});
t('Histórico: filtros de tipo, período e busca; página com "tem mais"; só as ações do closer', function () use ($servico, $db) {
    $db->executar('DELETE FROM acoes_closer');
    foreach ([[1, 'ligacao', '11987654321', 'pediu proposta', '2026-11-10 08:00:00'], [1, 'followup', '11987654321', null, '2026-11-10 09:00:00'], [1, 'ligacao', '21999998888', 'retornar', '2026-11-09 16:00:00'],
              [1, 'lead_quente', '31977776666', null, '2026-11-03 10:00:00'], [2, 'ligacao', '11955554444', null, '2026-11-10 08:30:00']] as $l) {
        $db->executar('INSERT INTO acoes_closer (vendedor_id, tipo, telefone, observacao, registrado_em) VALUES (?,?,?,?,?)', $l);
    }
    $hoje = $servico->listar(ANA, []);
    igual([2, false, '2026-11-10'], [$hoje['total'], $hoje['has_more'], $hoje['hoje']], 'sem período vale hoje; Bruno não entra');
    igual(['09:00', '08:00'], [$hoje['acoes'][0]['hora'], $hoje['acoes'][1]['hora']], 'do mais novo ao mais antigo');
    igual(3, $servico->listar(ANA, ['periodo' => '7d'])['total'], '7 dias: de 04 a 10 (a de 03/11 fica fora)');
    igual(4, $servico->listar(ANA, ['periodo' => 'mes'])['total'], 'mês inteiro');
    igual(1, $servico->listar(ANA, ['periodo' => 'ontem'])['total']);
    igual(1, $servico->listar(ANA, ['periodo' => 'data', 'data' => '2026-11-03'])['total']);
    igual(3, $servico->listar(ANA, ['periodo' => 'semana'])['total'], 'a semana automática vai de 04/11 a 10/11');
    igual(2, $servico->listar(ANA, ['periodo' => 'mes', 'tipo' => 'ligacao'])['total']);
    igual(1, $servico->listar(ANA, ['periodo' => 'mes', 'q' => '2199'])['total'], 'busca por dígitos (3 ou mais) olha o telefone');
    igual(1, $servico->listar(ANA, ['periodo' => 'mes', 'q' => 'prop'])['total'], 'busca por texto olha a observação');
    $pag = $servico->listar(ANA, ['periodo' => 'mes', 'limit' => 3]);
    igual([4, true, 3], [$pag['total'], $pag['has_more'], count($pag['acoes'])]);
    igual(1, count($servico->listar(ANA, ['periodo' => 'mes', 'limit' => 3, 'offset' => 3])['acoes']));
});
t('Resumo e evolução: contagem do dia por tipo; 7 dias com os valores de cada dia e o total dos 7 dias anteriores', function () use ($servico) {
    igual(['ligacao' => 1, 'followup' => 1, 'lead_quente' => 0, 'total' => 2], $servico->resumoDoDia(ANA));
    igual(['hoje' => '2026-11-10', 'resumo' => ['ligacao' => 0, 'followup' => 0, 'lead_quente' => 0, 'total' => 0]], ['hoje' => $servico->resumo(3)['hoje'], 'resumo' => $servico->resumo(3)['resumo']], 'closer sem ações');
    $e = $servico->evolucao(ANA);
    igual(['2026-11-04', '2026-11-10'], [$e['dias'][0]['data'], $e['dias'][6]['data']]);
    igual([7, 2, 1, 1], [count($e['dias']), $e['dias'][6]['total'], $e['dias'][5]['total'], $e['anterior']], 'hoje 2, ontem 1, e 1 nos 7 dias anteriores (03/11)');
    igual(3, $e['total']);
});

// ═══ Etapas 2 a 5 da fase 6: login, cadastro, recuperação de senha, limite de tentativas e "Meus contratos" ═══════════════════════════════
$db->executar('UPDATE equipes SET oculta_tv = 0');
$tmpLimite = sys_get_temp_dir() . '/nucleo-limite-' . bin2hex(random_bytes(4)) . '.json';
register_shutdown_function(static function () use ($tmpLimite): void {
    @unlink($tmpLimite);
});
$limite = new LimiteDeTentativas($tmpLimite);
$contas = new ContaCloserRepository($db);
$auth = new AutenticacaoCloserService($contas, $limite);
$novaAuth = static fn (): AutenticacaoCloserService => new AutenticacaoCloserService($contas, $limite);
$IP = '203.0.113.7';
$SENHA = 'senha-boa-123';

t('Limite de tentativas: só as falhas contam, bloqueia no máximo, libera com limpar() e guarda só o hash do identificador', function () use ($limite, $tmpLimite) {
    igual(0, $limite->bloqueado('x', 'a@b.com', 3, 900));
    for ($i = 0; $i < 3; $i++) {
        $limite->falhou('x', 'a@b.com', 900);
    }
    verdade($limite->bloqueado('x', 'a@b.com', 3, 900) > 0, 'bloqueou depois de 3 falhas');
    igual(0, $limite->bloqueado('x', 'outro@b.com', 3, 900), 'outro identificador não é afetado');
    verdade(strpos((string) file_get_contents($tmpLimite), 'a@b.com') === false, 'o e-mail não fica em texto puro no arquivo');
    $limite->limpar('x', 'a@b.com');
    igual(0, $limite->bloqueado('x', 'a@b.com', 3, 900));
    igual('Muitas tentativas. Tente novamente em 15 min.', LimiteDeTentativas::mensagem(900));
    igual('Muitas tentativas. Tente novamente em 1 min.', LimiteDeTentativas::mensagem(5));
});
t('Limite de tentativas: o arquivo padrão fica em storage/rate-limit, fora da pasta pública', function () {
    $r = new ReflectionMethod(LimiteDeTentativas::class, 'caminho');
    $caminho = str_replace('\\', '/', (string) $r->invoke(new LimiteDeTentativas()));
    verdade(strpos($caminho, '/storage/rate-limit/closer.json') !== false, $caminho);
    verdade(strpos($caminho, '/public/') === false && strpos($caminho, '/uploads/') === false, 'fora de public/ e de uploads/');
});
t('Cadastro: valida nome, e-mail, senha e confirmação, na ordem, e diz o campo', function () use ($auth, $IP, $SENHA) {
    igual('nome', $auth->registrar(['nome' => 'Jo', 'email' => 'a@b.com', 'senha' => $SENHA, 'senha2' => $SENHA, 'equipe_id' => 1], $IP)['campo']);
    igual('email', $auth->registrar(['nome' => 'Joana Souza', 'email' => 'sem-arroba', 'senha' => $SENHA, 'senha2' => $SENHA, 'equipe_id' => 1], $IP)['campo']);
    igual('senha', $auth->registrar(['nome' => 'Joana Souza', 'email' => 'j@b.com', 'senha' => 'curta', 'senha2' => 'curta', 'equipe_id' => 1], $IP)['campo']);
    igual('senha2', $auth->registrar(['nome' => 'Joana Souza', 'email' => 'j@b.com', 'senha' => $SENHA, 'senha2' => 'diferente-123', 'equipe_id' => 1], $IP)['campo']);
    igual('equipe_id', $auth->registrar(['nome' => 'Joana Souza', 'email' => 'j@b.com', 'senha' => $SENHA, 'senha2' => $SENHA, 'equipe_id' => 99], $IP)['campo']);
    igual('A senha pode ter no máximo 72 caracteres.', $auth->registrar(['nome' => 'Joana Souza', 'email' => 'j@b.com', 'senha' => str_repeat('a', 73), 'senha2' => str_repeat('a', 73), 'equipe_id' => 1], $IP)['erro']);
});
t('Cadastro: vincula pelo nome (sem acento ou caixa) quando há UM candidato, nasce PENDENTE e o e-mail repetido é 409', function () use ($auth, $db, $IP, $SENHA) {
    $r = $auth->registrar(['nome' => '  ANA ', 'email' => ' ANA@Teste.com ', 'senha' => $SENHA, 'senha2' => $SENHA, 'equipe_id' => 1], $IP);
    igual('nome', $r['vinculo'] ?? null, 'o nome "ANA" (caixa diferente) acha a Ana: ' . json_encode($r));
    $c = $db->linha("SELECT vendedor_id, equipe_id, status, email FROM closer_usuarios WHERE email='ana@teste.com'");
    igual([1, 1, 'pendente'], [(int) $c['vendedor_id'], (int) $c['equipe_id'], $c['status']]);
    $dup = $auth->registrar(['nome' => 'Outra Pessoa', 'email' => 'ana@teste.com', 'senha' => $SENHA, 'senha2' => $SENHA, 'equipe_id' => 1], $IP);
    igual([409, 'email', 'email_existe'], [$dup['status'], $dup['campo'], $dup['code']]);
    $sem = $auth->registrar(['nome' => 'Fulano Sem Cadastro', 'email' => 'fulano@teste.com', 'senha' => $SENHA, 'senha2' => $SENHA, 'equipe_id' => 1], $IP);
    igual('nenhum', $sem['vinculo'], 'sem candidato com esse nome, não vincula');
    igual(null, $db->valor("SELECT vendedor_id FROM closer_usuarios WHERE email='fulano@teste.com'"));
});
t('Cadastro por convite: vincula ao closer do convite, usa a equipe dele e marca o convite como usado (convite usado ou inválido é recusado)', function () use ($auth, $db, $IP, $SENHA) {
    $tok = str_repeat('ab', 20);
    $db->executar('INSERT INTO closer_convites (vendedor_id, token_hash, expira_em) VALUES (2, ?, ?)', [hash('sha256', $tok), '2026-11-17 10:00:00']);
    $pagina = $auth->paginaDeCadastro($tok);
    igual(['Bruno', false], [$pagina['convite']['nome'], $pagina['conviteInvalido']]);
    igual(true, $auth->paginaDeCadastro(str_repeat('cd', 20))['conviteInvalido']);
    $r = $auth->registrar(['nome' => 'Nome Qualquer', 'email' => 'bruno@teste.com', 'senha' => $SENHA, 'senha2' => $SENHA, 'convite' => $tok, 'equipe_id' => 99], $IP);
    igual('convite', $r['vinculo']);
    igual([2, 1], [(int) $db->valor("SELECT vendedor_id FROM closer_usuarios WHERE email='bruno@teste.com'"), (int) $db->valor("SELECT equipe_id FROM closer_usuarios WHERE email='bruno@teste.com'")]);
    verdade($db->valor('SELECT usado_em FROM closer_convites WHERE vendedor_id=2') !== null, 'convite marcado como usado');
    igual('convite', $auth->registrar(['nome' => 'Nome Qualquer', 'email' => 'outro@teste.com', 'senha' => $SENHA, 'senha2' => $SENHA, 'convite' => $tok], $IP)['campo']);
});
t('Cadastro: no máximo 10 por hora por endereço (as que passaram da validação contam)', function () use ($auth, $db, $limite, $SENHA) {
    $ip = '198.51.100.9';
    for ($i = 0; $i < 10; $i++) {
        $auth->registrar(['nome' => 'Pessoa Numero ' . $i, 'email' => "p$i@teste.com", 'senha' => $SENHA, 'senha2' => $SENHA, 'equipe_id' => 1], $ip);
    }
    $r = $auth->registrar(['nome' => 'Pessoa Onze', 'email' => 'p11@teste.com', 'senha' => $SENHA, 'senha2' => $SENHA, 'equipe_id' => 1], $ip);
    igual([429, 'Muitos cadastros deste computador. Tente novamente mais tarde.'], [$r['status'], $r['erro']]);
    igual(0, (int) $db->valor("SELECT COUNT(*) FROM closer_usuarios WHERE email='p11@teste.com'"));
    $limite->limpar('reg', $ip);
});
t('Login: e-mail inexistente e senha errada dão a MESMA mensagem; pendente e rejeitado têm aviso próprio e não entram', function () use ($auth, $db, $IP, $SENHA) {
    Session::simular([]);
    igual('E-mail ou senha incorretos.', $auth->entrar(['email' => 'ninguem@teste.com', 'senha' => $SENHA], $IP)['erro']);
    igual('E-mail ou senha incorretos.', $auth->entrar(['email' => 'ana@teste.com', 'senha' => 'senha-errada-1'], $IP . 'x')['erro']);
    igual('Informe e-mail e senha.', $auth->entrar(['email' => '', 'senha' => ''], $IP . 'y')['erro']);
    igual('pendente', $auth->entrar(['email' => 'ana@teste.com', 'senha' => $SENHA], $IP . 'z')['code']);
    $db->executar("UPDATE closer_usuarios SET status='rejeitado' WHERE email='ana@teste.com'");
    igual('rejeitado', $auth->entrar(['email' => 'ana@teste.com', 'senha' => $SENHA], $IP . 'z')['code']);
    igual(null, Session::obter('closer_id'), 'nada disso abre a sessão');
});
t('Login ativo: abre a sessão (closer_id), registra o último login, entra em closer.php e só cria "lembrar de mim" se pedido', function () use ($db, $novaAuth, $SENHA) {
    $db->executar("UPDATE closer_usuarios SET status='ativo' WHERE email='ana@teste.com'");
    Session::simular(['cr_csrf' => 'velho']);
    $r = $novaAuth()->entrar(['email' => 'ANA@teste.com', 'senha' => $SENHA], '10.0.0.1');
    igual('closer.php', $r['redirect']);
    $id = (int) $db->valor("SELECT id FROM closer_usuarios WHERE email='ana@teste.com'");
    igual($id, Session::obter('closer_id'));
    igual(null, Session::obter('cr_csrf'), 'o token CSRF é novo depois do login');
    verdade($db->valor('SELECT ultimo_login FROM closer_usuarios WHERE id=?', [$id]) !== null);
    igual(0, (int) $db->valor('SELECT COUNT(*) FROM closer_remember WHERE usuario_id=?', [$id]));
    Session::simular([]);
    $novaAuth()->entrar(['email' => 'ana@teste.com', 'senha' => $SENHA, 'lembrar' => true], '10.0.0.1');
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM closer_remember WHERE usuario_id=?', [$id]));
});
t('Login: equipe oculta da TV não entra (code equipe_oculta), nem com "lembrar"; o closer arquivado também não', function () use ($db, $novaAuth, $SENHA) {
    Session::simular([]);
    $db->executar('UPDATE equipes SET oculta_tv = 1 WHERE id = 1');
    $r = $novaAuth()->entrar(['email' => 'ana@teste.com', 'senha' => $SENHA, 'lembrar' => true], '10.0.0.2');
    igual('equipe_oculta', $r['code']);
    igual(null, Session::obter('closer_id'));
    $db->executar('UPDATE equipes SET oculta_tv = 0 WHERE id = 1');
    $db->executar('UPDATE vendedores SET deleted_at = NOW() WHERE id = 1');
    igual('desativado', $novaAuth()->entrar(['email' => 'ana@teste.com', 'senha' => $SENHA], '10.0.0.2')['code']);
    $db->executar('UPDATE vendedores SET deleted_at = NULL WHERE id = 1');
});
t('Login: depois de 5 falhas o mesmo e-mail e IP é bloqueado (inclusive com a senha certa); um sucesso zera a contagem', function () use ($novaAuth, $limite, $SENHA) {
    Session::simular([]);
    $ip = '10.0.0.3';
    for ($i = 0; $i < 5; $i++) {
        igual('E-mail ou senha incorretos.', $novaAuth()->entrar(['email' => 'ana@teste.com', 'senha' => 'errada-' . $i . '-xx'], $ip)['erro']);
    }
    $r = $novaAuth()->entrar(['email' => 'ana@teste.com', 'senha' => $SENHA], $ip);
    igual(429, $r['status']);
    verdade(strpos($r['erro'], 'Muitas tentativas. Tente novamente em') === 0, $r['erro']);
    igual(null, Session::obter('closer_id'), 'bloqueado: nem a senha certa entra');
    $limite->limpar('login', $ip . '|ana@teste.com');
    $limite->limpar('login_ip', $ip);
    $novaAuth()->entrar(['email' => 'ana@teste.com', 'senha' => 'errada-1-xx'], $ip);
    igual('closer.php', $novaAuth()->entrar(['email' => 'ana@teste.com', 'senha' => $SENHA], $ip)['redirect']);
    igual(0, $limite->bloqueado('login', $ip . '|ana@teste.com', 1, 900), 'o login certo zera a contagem do e-mail');
});
t('Conta logada: vale na hora o que o admin muda — equipe oculta, closer arquivado ou conta revogada derrubam a sessão', function () use ($db, $novaAuth) {
    $id = (int) $db->valor("SELECT id FROM closer_usuarios WHERE email='ana@teste.com'");
    Session::simular(['closer_id' => $id]);
    igual('Ana', $novaAuth()->contaLogada()['v_nome']);
    $db->executar('UPDATE equipes SET oculta_tv = 1 WHERE id = 1');
    igual(null, $novaAuth()->contaLogada(), 'equipe oculta');
    igual(null, Session::obter('closer_id'), 'a sessão é limpa');
    $db->executar('UPDATE equipes SET oculta_tv = 0 WHERE id = 1');
    Session::simular(['closer_id' => $id]);
    $db->executar('UPDATE vendedores SET deleted_at = NOW() WHERE id = 1');
    igual(null, $novaAuth()->contaLogada(), 'closer arquivado');
    $db->executar('UPDATE vendedores SET deleted_at = NULL WHERE id = 1');
    Session::simular(['closer_id' => $id]);
    $db->executar("UPDATE closer_usuarios SET status='rejeitado' WHERE id = $id");
    igual(null, $novaAuth()->contaLogada(), 'conta revogada');
    $db->executar("UPDATE closer_usuarios SET status='ativo' WHERE id = $id");
    Session::simular([]);
    igual(null, $novaAuth()->contaLogada(), 'sem sessão e sem cookie');
});
t('"Lembrar de mim": o cookie certo entra sem sessão e GIRA o token; cookie errado, expirado ou de equipe oculta não entra', function () use ($db, $contas, $novaAuth) {
    $id = (int) $db->valor("SELECT id FROM closer_usuarios WHERE email='ana@teste.com'");
    $db->executar('DELETE FROM closer_remember');
    $sel = str_repeat('a1', 12);
    $val = str_repeat('b2', 32);
    $contas->guardarLembrete($id, $sel, hash('sha256', $val), '2099-01-01 00:00:00', '2026-11-10 10:00:00');
    Session::simular([]);
    $_COOKIE['closer_rm'] = $sel . '.' . $val;
    igual('Ana', $novaAuth()->contaLogada()['v_nome']);
    igual($id, Session::obter('closer_id'));
    igual(0, (int) $db->valor('SELECT COUNT(*) FROM closer_remember WHERE selector=?', [$sel]), 'o token usado é descartado');
    igual(1, (int) $db->valor('SELECT COUNT(*) FROM closer_remember WHERE usuario_id=?', [$id]), 'e um novo é emitido');
    // validator errado: nega e derruba os "lembrar" da conta
    $contas->guardarLembrete($id, $sel, hash('sha256', $val), '2099-01-01 00:00:00', '2026-11-10 10:00:00');
    Session::simular([]);
    $_COOKIE['closer_rm'] = $sel . '.' . str_repeat('c3', 32);
    igual(null, $novaAuth()->contaLogada());
    igual(0, (int) $db->valor('SELECT COUNT(*) FROM closer_remember WHERE usuario_id=?', [$id]));
    // expirado
    $contas->guardarLembrete($id, $sel, hash('sha256', $val), '2026-11-01 00:00:00', '2026-11-10 10:00:00');
    $_COOKIE['closer_rm'] = $sel . '.' . $val;
    igual(null, $novaAuth()->contaLogada());
    // cookie com formato inválido
    $_COOKIE['closer_rm'] = 'qualquer-coisa';
    igual(null, $novaAuth()->contaLogada());
    // equipe oculta
    $db->executar('DELETE FROM closer_remember');
    $contas->guardarLembrete($id, $sel, hash('sha256', $val), '2099-01-01 00:00:00', '2026-11-10 10:00:00');
    $db->executar('UPDATE equipes SET oculta_tv = 1 WHERE id = 1');
    $_COOKIE['closer_rm'] = $sel . '.' . $val;
    Session::simular([]);
    igual(null, $novaAuth()->contaLogada());
    $db->executar('UPDATE equipes SET oculta_tv = 0 WHERE id = 1');
    unset($_COOKIE['closer_rm']);
});
t('Sair: apaga a sessão, o token CSRF e os "lembrar de mim" da conta', function () use ($db, $contas, $novaAuth) {
    $id = (int) $db->valor("SELECT id FROM closer_usuarios WHERE email='ana@teste.com'");
    $contas->guardarLembrete($id, str_repeat('d4', 12), hash('sha256', 'x'), '2099-01-01 00:00:00', '2026-11-10 10:00:00');
    Session::simular(['closer_id' => $id, 'cr_csrf' => 'tok']);
    igual('closer-login.php', $novaAuth()->sair()['redirect']);
    igual([null, null, 0], [Session::obter('closer_id'), Session::obter('cr_csrf'), (int) $db->valor('SELECT COUNT(*) FROM closer_remember WHERE usuario_id=?', [$id])]);
});
t('Token CSRF do portal: é criado uma vez e se repete na mesma sessão', function () use ($novaAuth) {
    Session::simular([]);
    $t1 = $novaAuth()->tokenCsrf();
    verdade(strlen($t1) === 64 && ctype_xdigit($t1));
    igual($t1, $novaAuth()->tokenCsrf());
    igual($t1, Session::obter('cr_csrf'));
});
t('Recuperação: o pedido só avisa o admin (resposta igual para e-mail que não existe), no máximo 5 por hora', function () use ($novaAuth, $db, $limite) {
    $ip = '10.0.0.4';
    $r = $novaAuth()->pedirRedefinicao(['email' => 'ana@teste.com'], $ip);
    verdade(strpos($r['message'], 'Pedido enviado ao administrador') === 0);
    verdade($db->valor("SELECT reset_solicitado_em FROM closer_usuarios WHERE email='ana@teste.com'") !== null);
    igual($r, $novaAuth()->pedirRedefinicao(['email' => 'ninguem@teste.com'], $ip), 'mesma resposta, não revela quem tem cadastro');
    igual('email', $novaAuth()->pedirRedefinicao(['email' => 'nao-e-email'], $ip)['campo']);
    $novaAuth()->pedirRedefinicao(['email' => 'ana@teste.com'], $ip);
    $novaAuth()->pedirRedefinicao(['email' => 'ana@teste.com'], $ip);
    igual(429, $novaAuth()->pedirRedefinicao(['email' => 'ana@teste.com'], $ip)['status']);
    $limite->limpar('forgot', $ip);
});
t('Recuperação: token certo troca a senha, derruba os "lembrar" e some; errado, vencido ou repetido é recusado e o 5º erro bloqueia', function () use ($novaAuth, $db, $contas, $limite) {
    $id = (int) $db->valor("SELECT id FROM closer_usuarios WHERE email='ana@teste.com'");
    $token = 'ABCDE23456';
    $db->executar('UPDATE closer_usuarios SET reset_token_hash=?, reset_expira_em=? WHERE id=?', [hash('sha256', $token), '2026-11-11 10:00:00', $id]);
    $contas->guardarLembrete($id, str_repeat('e5', 12), hash('sha256', 'x'), '2099-01-01 00:00:00', '2026-11-10 10:00:00');
    $base = ['email' => 'ana@teste.com', 'senha' => 'nova-senha-456', 'senha2' => 'nova-senha-456'];
    igual('token', $novaAuth()->redefinir($base + ['token' => 'ZZZZZZZZZZ'])['campo']);
    igual('token', $novaAuth()->redefinir($base + ['token' => ''])['campo']);
    igual('senha2', $novaAuth()->redefinir(['senha2' => 'outra-coisa-1'] + $base + ['token' => $token])['campo']);
    $ok = $novaAuth()->redefinir($base + ['token' => 'abcde-23456']);
    igual(['Senha redefinida! Entre com a nova senha.', 'closer-login.php?ok=senha'], [$ok['message'], $ok['redirect']], 'o token vale com hífen e minúsculas');
    verdade(password_verify('nova-senha-456', (string) $db->valor('SELECT senha_hash FROM closer_usuarios WHERE id=?', [$id])));
    igual([null, null, 0], [$db->valor('SELECT reset_token_hash FROM closer_usuarios WHERE id=?', [$id]), $db->valor('SELECT reset_expira_em FROM closer_usuarios WHERE id=?', [$id]), (int) $db->valor('SELECT COUNT(*) FROM closer_remember WHERE usuario_id=?', [$id])]);
    igual('token', $novaAuth()->redefinir($base + ['token' => $token])['campo'], 'o token só vale uma vez');
    // vencido
    $db->executar('UPDATE closer_usuarios SET reset_token_hash=?, reset_expira_em=? WHERE id=?', [hash('sha256', $token), '2026-11-09 10:00:00', $id]);
    igual('Token inválido ou expirado. Peça um novo ao administrador.', $novaAuth()->redefinir($base + ['token' => $token])['erro']);
    // bloqueio por tentativas no e-mail
    $limite->limpar('reset', 'ana@teste.com');
    for ($i = 0; $i < 5; $i++) {
        $novaAuth()->redefinir($base + ['token' => 'ZZZZZZZZZZ']);
    }
    igual(429, $novaAuth()->redefinir($base + ['token' => $token])['status']);
    $limite->limpar('reset', 'ana@teste.com');
});
t('Equipes do cadastro: só id, nome e cor (lista pública) e o nome do líder para a página Configurações', function () use ($contas, $db) {
    igual(['id', 'nome', 'cor'], array_keys($contas->equipesPublicas()[0]));
    igual('', $contas->nomeDoLider(1), 'sem líder definido');
    $db->executar('UPDATE equipes SET lider_id = 2 WHERE id = 1');
    igual('Bruno', $contas->nomeDoLider(1));
    $db->executar('UPDATE equipes SET lider_id = NULL WHERE id = 1');
});

// ── "Meus contratos" ────────────────────────────────────────────────────────────────────────────────────────────────
$contratos = $container->obter(ContratosCloserService::class);

t('Contratos, período: semana automática de 04/11 a 10/11 (hoje 10/11), comparada com a semana de antes (mesmo nº de dias)', function () use ($contratos) {
    $r = $contratos->intervalo('semana');
    igual(['semana', '2026-11-04', '2026-11-10', '2026-11-10', 7, '2026-10-28', '2026-11-03'], [$r['periodo'], $r['start'], $r['end'], $r['eff_end'], $r['dias'], $r['prev_start'], $r['prev_end']]);
    igual(1, $r['semana'], 'a 1ª semana automática de novembro (a 1ª quarta é 04/11)');
    igual([1, 2, 3, 4], array_column($r['semanas'], 'semana_num'), 'novembro de 2026 tem 4 quartas-feiras');
});
t('Contratos, período: mês conta só até hoje e compara com os mesmos dias do mês anterior; "hoje" compara com ontem; valor estranho vira semana', function () use ($contratos) {
    $m = $contratos->intervalo('mes');
    igual(['2026-11-01', '2026-11-30', '2026-11-10', 10, '2026-10-01', '2026-10-10'], [$m['start'], $m['end'], $m['eff_end'], $m['dias'], $m['prev_start'], $m['prev_end']]);
    $h = $contratos->intervalo('hoje');
    igual(['2026-11-10', '2026-11-10', 1, '2026-11-09', '2026-11-09'], [$h['start'], $h['end'], $h['dias'], $h['prev_start'], $h['prev_end']]);
    igual('semana', $contratos->intervalo('ano')['periodo']);
    $futuro = $contratos->intervalo('mes', 2027, 1);
    igual([2026, 11], [$futuro['ano'], $futuro['mes']], 'mês futuro volta para o atual');
    igual('2026-10-07', $contratos->intervalo('semana', 2026, 10)['start'], 'mês passado: a 1ª semana (a que começa na 1ª quarta)');
    igual('2026-10-31', $contratos->intervalo('mes', 2026, 10)['eff_end'], 'mês fechado conta o mês inteiro');
});
t('Contratos: total, série dia a dia (futuro vazio), melhor dia, média e a comparação com o período anterior', function () use ($contratos, $db) {
    foreach ([[1, '2026-11-04', 2], [1, '2026-11-06', 5], [1, '2026-11-10', 1], [1, '2026-10-30', 3], [2, '2026-11-06', 9]] as $l) {
        $db->executar('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?,?,?)', $l);
    }
    $r = $contratos->contratos(ANA, ['periodo' => 'semana']);
    igual([8, 1.1, 3], [$r['total'], $r['media'], $r['anterior']], 'media = 8 / 7 dias; anterior = 3 (30/10)');
    igual(['2026-11-06', 5], [$r['melhor']['data'], $r['melhor']['qtd']]);
    igual([7, 2, 0], [count($r['serie']), $r['serie'][0]['qtd'], $r['serie'][1]['qtd']], 'a série tem um ponto por dia da semana');
    $m = $contratos->contratos(ANA, ['periodo' => 'mes']);
    igual([8, '2026-10', '2026-11'], [$m['total'], $m['mes_min'], $m['mes_max']], 'a navegação por mês começa no 1º mês com contrato (outubro) e vai até o atual');
    igual(30, count($m['serie']));
    igual(null, $m['serie'][29]['qtd'], 'dia que ainda não chegou fica vazio (null), não zero');
    igual(0, $contratos->contratos(3, ['periodo' => 'mes'])['total'], 'closer sem contratos');
});
t('Contratos: nunca mostra o contrato de closer de equipe oculta da TV', function () use ($contratos, $db) {
    $db->executar('UPDATE equipes SET oculta_tv = 1 WHERE id = 1');
    $r = $contratos->contratos(ANA, ['periodo' => 'semana']);
    igual([0, null], [$r['total'], $r['melhor']]);
    $db->executar('UPDATE equipes SET oculta_tv = 0 WHERE id = 1');
});
t('Contratos, benefícios: "Outros" com descrição vira um item por descrição, em ordem de quantidade, "Outros" por último, com cor, ícone e %', function () use ($contratos, $db) {
    foreach ([['bpc_loas', '', 3], ['aux_doenca', '', 1], ['outros', 'Revisão da vida toda', 2], ['outros', 'Pensão alimentícia', 1]] as [$tipo, $desc, $q]) {
        $db->executar('INSERT INTO contratos_tipos (vendedor_id, data_registro, tipo, tipo_descricao, quantidade) VALUES (1, ?, ?, ?, ?)', ['2026-11-06', $tipo, $desc, $q]);
    }
    $r = $contratos->contratos(ANA, ['periodo' => 'semana']);
    $b = $r['beneficios'];
    igual(['bpc_loas', 'aux_doenca', 'outros:Revisão da vida toda', 'outros:Pensão alimentícia'], array_column($b, 'chave'));
    igual([3, 1, 2, 1], array_column($b, 'qtd'));
    igual('Outros: Revisão da vida toda', $b[2]['label']);
    verdade(preg_match('/^#[0-9a-f]{6}$/i', $b[0]['cor']) === 1 && $b[0]['icone'] !== '', 'cor e ícone do admin');
    igual('more', $b[2]['icone'], 'o "Outros" tem o ícone dele');
    igual([7, 1], [$r['com_tipo'], $r['sem_tipo']], '8 contratos: 7 têm tipo, 1 ficou sem tipo');
    igual(37.5, $b[0]['pct'], '3 de 8');
});
t('Contratos, meta: semana (do período ou a base do closer), mês (do período, a mensal ou base × semanas) e o dia (só se houver meta diária)', function () use ($contratos, $db) {
    igual(10, $contratos->contratos(ANA, ['periodo' => 'semana'])['meta'], 'sem meta por período: a meta base (10)');
    $db->executar("INSERT INTO metas_periodo (tipo, ref_id, ano, mes, semana_num, meta) VALUES ('closer', 1, 2026, 11, 1, 25), ('closer', 1, 2026, 11, 0, 90)");
    igual(25, $contratos->contratos(ANA, ['periodo' => 'semana'])['meta'], 'meta da semana 1');
    igual(90, $contratos->contratos(ANA, ['periodo' => 'mes'])['meta'], 'meta do mês');
    igual(null, $contratos->contratos(ANA, ['periodo' => 'hoje'])['meta'], 'sem meta diária, o dia não tem meta');
    $db->executar('INSERT INTO metas_diarias_periodo (closer_id, ano, mes, dia, meta) VALUES (1, 2026, 11, 10, 4)');
    igual(4, $contratos->contratos(ANA, ['periodo' => 'hoje'])['meta']);
    $db->executar('DELETE FROM metas_periodo');
    $db->executar('UPDATE vendedores SET meta_mensal = 70 WHERE id = 1');
    igual(70, $contratos->contratos(ANA, ['periodo' => 'mes'])['meta'], 'sem meta do período: a mensal do closer');
    $db->executar('UPDATE vendedores SET meta_mensal = 0 WHERE id = 1');
    igual(40, $contratos->contratos(ANA, ['periodo' => 'mes'])['meta'], 'sem nenhuma: base (10) × as 4 semanas automáticas de novembro');
});
t('Ícones do portal: o desenho existe, nome desconhecido vira vazio e o conjunto traz só o que foi pedido', function () {
    verdade(strpos(IconesPortal::svg('check'), '<svg class="ic"') === 0);
    igual('<svg class="ic big"', substr(IconesPortal::svg('phone', 'big'), 0, 19));
    igual('', IconesPortal::svg('nao-existe'));
    igual(['alert', 'x'], array_keys(IconesPortal::conjunto(['alert', 'x'])));
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
exit($falharam ? 1 : 0);
