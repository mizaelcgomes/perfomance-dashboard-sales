<?php
declare(strict_types=1);

/**
 * Testes da TV no núcleo (fase 3): calendário da semana, propaganda, rotação, pop-up, cerimônia, música de fundo e Retrospectiva,
 * com DADOS SINTÉTICOS e relógio congelado em 10/11/2026 10:00 (terça, America/Sao_Paulo).
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\nucleo\tv.php
 * As tabelas são criadas (vazias) num banco DESCARTÁVEL (nucleo_teste_tv), apagado no fim; as músicas vão para uma pasta temporária.
 * Complementa o golden/diferencial, que comparam o código novo com o antigo nos dados reais.
 */

use App\Actions\Cerimonia\DispararCerimonia;
use App\Actions\Cerimonia\SalvarConfiguracaoCerimonia;
use App\Actions\Musica\DefinirProximaMusica;
use App\Actions\Musica\EnviarMusica;
use App\Actions\Musica\ExcluirMusica;
use App\Actions\Popup\DispararPopup;
use App\Actions\Popup\SalvarPopup;
use App\Actions\Propaganda\AdicionarPropaganda;
use App\Actions\Propaganda\AlternarPropaganda;
use App\Actions\Propaganda\AtualizarPropaganda;
use App\Actions\Propaganda\ExcluirPropaganda;
use App\Actions\Rotacao\SalvarRotacao;
use App\Controllers\PropagandaController;
use App\Controllers\RotacaoController;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Relogio;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\RotacaoRepository;
use App\Services\Calendario;
use App\Services\CerimoniaService;
use App\Services\EnvioDeArquivo;
use App\Services\ImagemService;
use App\Services\MusicaService;
use App\Services\RetrospectivaService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');
require __DIR__ . '/harness.php';

Relogio::fixar('2026-11-10 10:00:00');

// ── banco DESCARTÁVEL (mesmo cuidado do relatorios.php: só 127.0.0.1, root sem senha do MySQL portátil de teste) ──────
$real = new Database();
$real->testar();
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este teste só roda contra o MySQL local de teste.\n");
    exit(2);
}
$esquema = 'nucleo_teste_tv';
$admin = new Database(['host' => '127.0.0.1', 'banco' => 'mysql', 'usuario' => 'root', 'senha' => '']);
$admin->executar("DROP DATABASE IF EXISTS `$esquema`");
$admin->executar("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
$tmp = pastaTemp('nucleo-tv-');
register_shutdown_function(static function () use ($admin, $esquema, $tmp): void {
    $admin->executar("DROP DATABASE IF EXISTS `$esquema`");
    apagar($tmp);
});
foreach (['vendedores', 'equipes', 'contratos_historico', 'contratos_tipos', 'metas_periodo', 'atendimentos', 'app_settings', 'semanas_mes', 'propagandas', 'custom_popup', 'goal_ceremony'] as $tabela) {
    $admin->executar('CREATE TABLE `' . $esquema . '`.`' . $tabela . '` LIKE `' . DB_NAME . '`.`' . $tabela . '`');
}
$db = new Database(['host' => '127.0.0.1', 'banco' => $esquema, 'usuario' => 'root', 'senha' => '']);

// ── dados: a semana anterior (28/10 a 03/11) e o mês anterior (outubro) ──────────────────────────────
$db->executar("INSERT INTO equipes (id, nome, cor, meta_semanal, meta_mensal) VALUES (1, 'Alpha', '#112233', 0, 0), (2, 'Beta', '#445566', 0, 0)");
$vend = $db->pdo()->prepare('INSERT INTO vendedores (id, equipe_id, nome, metas, meta_mensal, cor, deleted_at, suspended_at) VALUES (?,?,?,?,?,?,?,?)');
foreach ([
    [1, 1, 'Ana', 10, 0, '#abcdef', null, null],
    [2, 2, 'Bruno', 5, 0, '#aaaaaa', null, null],
    [3, 1, 'Caio', 8, 0, '#123456', '2026-11-08 10:00:00', null],      // desligado: não aparece, mas os contratos somam na Alpha
    [4, 1, 'Eva', 6, 0, '#0a0b0c', null, '2026-11-02 10:00:00'],       // suspensa: idem
    [5, 2, 'Fabio', 4, 0, '#bbbbbb', null, null],
] as $linha) {
    $vend->execute($linha);
}
$hist = $db->pdo()->prepare('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?,?,?)');
foreach ([[1, '2026-10-10', 3], [1, '2026-10-29', 6], [1, '2026-11-02', 6], [1, '2026-11-05', 9], [2, '2026-10-30', 5], [5, '2026-11-01', 4], [3, '2026-10-31', 3], [4, '2026-11-02', 4]] as $linha) {
    $hist->execute($linha);
}
$tp = $db->pdo()->prepare('INSERT INTO contratos_tipos (vendedor_id, data_registro, tipo, quantidade) VALUES (?,?,?,?)');
foreach ([[1, '2026-10-29', 'aux_acidente', 1], [2, '2026-10-30', 'bpc_loas', 1], [5, '2026-11-01', 'aux_acidente', 2]] as $linha) {
    $tp->execute($linha);
}
$db->executar("INSERT INTO atendimentos (vendedor_id, data_registro, quantidade) VALUES (1, '2026-10-29', 24)");
$db->executar("INSERT INTO custom_popup (id, titulo, foto1, foto2) VALUES (1, '', NULL, NULL)");
$db->executar("INSERT INTO goal_ceremony (id, musica, final_duration) VALUES (1, NULL, 15)");

// ── serviços (o contêiner liga as dependências); o envio de arquivo é trocado por uma cópia comum (move_uploaded_file só aceita upload HTTP) ──
$container = new Container();
$container->instancia(Database::class, $db);
$container->instancia(EnvioDeArquivo::class, new class extends EnvioDeArquivo {
    public function mover(string $de, string $para): bool
    {
        return copy($de, $para);
    }
});
Config::definir('app.raiz_site', $tmp);      // music/bg e music/ceremony vão para a pasta temporária
$calendario = $container->obter(Calendario::class);

function semana(Database $db, int $ano, int $mes, int $n, string $ini, string $fim): void
{
    $db->executar('INSERT INTO semanas_mes (ano, mes, semana_num, data_inicio, data_fim) VALUES (?,?,?,?,?)', [$ano, $mes, $n, $ini, $fim]);
}
function arquivoEnviado(string $tmp, string $nome, int $tamanho = 203, int $erro = UPLOAD_ERR_OK): array
{
    $caminho = $tmp . '/up_' . bin2hex(random_bytes(3));
    file_put_contents($caminho, 'ID3' . str_repeat('x', 200));
    return ['name' => $nome, 'tmp_name' => $caminho, 'size' => $tamanho, 'error' => $erro, 'type' => 'audio/mpeg'];
}

// ═══ Calendário: a semana (quarta a terça) ══════════════════════════════════════════════════════════
t('Calendario::limitesAutomaticos: a semana começa na quarta e termina na terça, em qualquer dia', function () use ($calendario) {
    $esperado = ['2026-11-04' => '2026-11-04', '2026-11-05' => '2026-11-04', '2026-11-08' => '2026-11-04', '2026-11-09' => '2026-11-04', '2026-11-10' => '2026-11-04', '2026-11-11' => '2026-11-11'];
    foreach ($esperado as $dia => $inicio) {
        Relogio::fixar($dia . ' 10:00:00');
        $l = $calendario->limitesAutomaticos();
        igual($inicio, $l['start'], "início em $dia");
        igual((new DateTime($inicio))->modify('+6 days')->format('Y-m-d'), $l['end'], "fim em $dia");
    }
    Relogio::fixar('2026-11-10 10:00:00');
});
t('Calendario::semanaAtual: sem semana configurada vale a automática; com configuração, a configurada', function () use ($calendario, $db) {
    igual(['start' => '2026-11-04', 'end' => '2026-11-10', 'configured' => false], $calendario->semanaAtual());
    semana($db, 2026, 11, 2, '2026-11-06', '2026-11-12');
    igual(['start' => '2026-11-06', 'end' => '2026-11-12', 'configured' => true], $calendario->semanaAtual());
    $db->executar('DELETE FROM semanas_mes');
});
t('Calendario::semanaAtual: a semana configurada no mês ANTERIOR que atravessa a virada de mês (e de ano) é achada', function () use ($calendario, $db) {
    semana($db, 2026, 10, 4, '2026-10-28', '2026-11-03');
    Relogio::fixar('2026-11-02 10:00:00');
    igual(['start' => '2026-10-28', 'end' => '2026-11-03', 'configured' => true], $calendario->semanaAtual());
    $db->executar('DELETE FROM semanas_mes');
    semana($db, 2026, 12, 5, '2026-12-30', '2027-01-05');
    Relogio::fixar('2027-01-02 10:00:00');
    igual(['start' => '2026-12-30', 'end' => '2027-01-05', 'configured' => true], $calendario->semanaAtual());
    $db->executar('DELETE FROM semanas_mes');
    Relogio::fixar('2026-11-10 10:00:00');
});
t('Calendario::semanaAnterior: automática (sem semana configurada) e configurada (com número, ano e mês)', function () use ($calendario, $db) {
    igual(['start' => '2026-10-28', 'end' => '2026-11-03', 'semana_num' => null, 'ano' => null, 'mes' => null, 'configured' => false], $calendario->semanaAnterior());
    semana($db, 2026, 10, 4, '2026-10-28', '2026-11-03');
    igual(['start' => '2026-10-28', 'end' => '2026-11-03', 'semana_num' => 4, 'ano' => 2026, 'mes' => 10, 'configured' => true], $calendario->semanaAnterior());
    $db->executar('DELETE FROM semanas_mes');
});

// ═══ Propaganda ═══════════════════════════════════════════════════════════════════════════════════════
t('Propaganda: cadastrar valida o conteúdo ("0" também conta como vazio), guarda o título sem espaços e devolve o id', function () use ($container) {
    $add = $container->obter(AdicionarPropaganda::class);
    $r = $add->executar('T', '', 1);
    igual(['success' => false, 'message' => 'Conteúdo HTML obrigatório'], $r->paraArray());
    igual(false, $add->executar('T', '0', 1)->sucesso());
    $ok = $add->executar('  Golden  ', '<p>x</p>', 1);
    igual(true, $ok->sucesso());
    igual('Propaganda adicionada', $ok->mensagem());
    igual(1, $ok->dados()['id']);
});
t('Propaganda: atualizar, ligar/desligar (inclusive id que não existe) e excluir', function () use ($container, $db) {
    $upd = $container->obter(AtualizarPropaganda::class);
    $alt = $container->obter(AlternarPropaganda::class);
    $exc = $container->obter(ExcluirPropaganda::class);
    igual('Dados inválidos', $upd->executar(0, 'x', '<p>x</p>', 1)->mensagem());
    igual('Dados inválidos', $upd->executar(1, 'x', '', 1)->mensagem());
    igual(true, $upd->executar(1, ' Novo ', '<p>novo</p>', 0)->sucesso());
    $linha = $db->linha('SELECT titulo, conteudo_html, ativo FROM propagandas WHERE id=1');
    igual(['titulo' => 'Novo', 'conteudo_html' => '<p>novo</p>', 'ativo' => 0], $linha);
    $a = $alt->executar(1);
    igual([true, 'Ativada'], [$a->dados()['ativo'], $a->mensagem()]);
    $b = $alt->executar(1);
    igual([false, 'Desativada'], [$b->dados()['ativo'], $b->mensagem()]);
    $c = $alt->executar(999);
    igual([true, false, 'Desativada'], [$c->sucesso(), $c->dados()['ativo'], $c->mensagem()], 'id inexistente: sucesso e "Desativada", como sempre foi');
    igual('ID inválido', $alt->executar(0)->mensagem());
    igual('ID inválido', $exc->executar(0)->mensagem());
    igual('Propaganda removida', $exc->executar(1)->mensagem());
    igual(0, (int) $db->valor('SELECT COUNT(*) FROM propagandas'));
});
t('PropagandaController::listar: ?ativo= segue o (bool) do PHP ("0" e vazio = todas; qualquer outro texto = só as ativas) e as falhas voltam com HTTP 200', function () use ($container, $db) {
    $db->executar("INSERT INTO propagandas (id, titulo, conteudo_html, ativo) VALUES (1, 'a', '<p>a</p>', 1), (2, 'b', '<p>b</p>', 0)");
    $c = $container->obter(PropagandaController::class);
    $ids = fn(array $q) => array_column(json_decode($c->listar(req('GET', '/api/propagandas', [], $q))->corpo(), true)['data'], 'id');
    igual([2, 1], $ids([]));
    igual([2, 1], $ids(['ativo' => '0']));
    igual([2, 1], $ids(['ativo' => '']));
    igual([1], $ids(['ativo' => '1']));
    igual([1], $ids(['ativo' => 'false']));
    $falha = $c->excluir(req('POST', '/api/propagandas/excluir', [], [], []));
    igual(200, $falha->status());
    igual('ID inválido', json_decode($falha->corpo(), true)['message']);
    $db->executar('DELETE FROM propagandas');
});

// ═══ Rotação ═══════════════════════════════════════════════════════════════════════════════════════════
t('Rotação: só entram pódio de modo conhecido e propaganda com id; duração mínima de 5 s; o resto é descartado', function () use ($container) {
    $salvar = $container->obter(SalvarRotacao::class);
    $rotacao = $container->obter(RotacaoRepository::class);
    $itens = [['type' => 'podium', 'mode' => 'semanal', 'duration' => 3], ['type' => 'podium', 'mode' => 'xx'], ['type' => 'ad', 'propaganda_id' => '12abc', 'duration' => 1], ['type' => 'ad'],
        ['mode' => 'geral'], ['type' => 'podium', 'mode' => 'retro_mes'], 'texto', 7];
    igual(true, $salvar->executar((string) json_encode($itens))->sucesso());
    igual([['type' => 'podium', 'mode' => 'semanal', 'duration' => 5], ['type' => 'ad', 'propaganda_id' => 12, 'duration' => 5], ['type' => 'podium', 'mode' => 'retro_mes', 'duration' => 60]], $rotacao->itens());
});
t('Rotação: JSON inválido ou que não é lista é recusado e não apaga a rotação salva', function () use ($container) {
    $salvar = $container->obter(SalvarRotacao::class);
    $rotacao = $container->obter(RotacaoRepository::class);
    $antes = $rotacao->itens();
    foreach (['{{', '"texto"', '5', ''] as $ruim) {
        igual('Dados inválidos', $salvar->executar($ruim)->mensagem(), "entrada «{$ruim}»");
    }
    igual($antes, $rotacao->itens());
    igual(true, $salvar->executar('[]')->sucesso());
    igual([], $rotacao->itens());
});
t('RotacaoController::ver: devolve [] quando o valor guardado não é uma lista', function () use ($container, $db) {
    $db->executar("INSERT INTO app_settings (setting_key, setting_value) VALUES ('rotation', '\"lixo\"') ON DUPLICATE KEY UPDATE setting_value='\"lixo\"'");
    $c = $container->obter(RotacaoController::class);
    igual(['success' => true, 'data' => []], json_decode($c->ver(req('GET', '/api/rotacao'))->corpo(), true));
    $db->executar("DELETE FROM app_settings WHERE setting_key='rotation'");
});

// ═══ Pop-up avulso ═════════════════════════════════════════════════════════════════════════════════════
t('Pop-up: sem título nem fotos não dispara; com título dispara; salvar sem foto nova mantém as fotos', function () use ($container, $db) {
    $disparar = $container->obter(DispararPopup::class);
    $salvar = $container->obter(SalvarPopup::class);
    igual('Cadastre o título/fotos antes de disparar', $disparar->executar()->mensagem());
    $r = $salvar->executar('  Meu popup  ', null, null);
    igual([true, 'Pop-up salvo', null, null], [$r->sucesso(), $r->mensagem(), $r->dados()['foto1'], $r->dados()['foto2']]);
    igual('Meu popup', $db->valor('SELECT titulo FROM custom_popup WHERE id=1'));
    igual(true, $disparar->executar()->sucesso());
    verdade($db->valor('SELECT triggered_at FROM custom_popup WHERE id=1') !== null, 'triggered_at preenchido');
    $db->executar("UPDATE custom_popup SET foto1='a.webp' WHERE id=1");
    $salvar->executar('Outro', null, ['name' => 'x.png', 'tmp_name' => '', 'size' => 1, 'error' => UPLOAD_ERR_NO_FILE]);
    igual('a.webp', $db->valor('SELECT foto1 FROM custom_popup WHERE id=1'), 'a foto atual fica quando nenhuma nova foi enviada');
});
t('ImagemService::enviar: foto grande demais, não imagem e tipo não permitido viram a mensagem certa (precisa de GD)', function () use ($container, $tmp) {
    if (!function_exists('imagewebp')) {
        echo "     (GD ausente neste PHP: rode com -d extension=gd para este teste)\n";
        return;
    }
    $img = $container->obter(ImagemService::class);
    Config::definir('app.uploads', $tmp . '/uploads');
    mkdir($tmp . '/uploads');
    $grande = ['name' => 'a.png', 'tmp_name' => $tmp . '/x', 'size' => 600 * 1024, 'error' => UPLOAD_ERR_OK];
    lanca(App\Services\ImagemInvalidaException::class, fn() => $img->enviar($grande, null));
    file_put_contents($tmp . '/texto', 'isto não é imagem');
    try {
        $img->enviar(['name' => 'a.png', 'tmp_name' => $tmp . '/texto', 'size' => 20, 'error' => UPLOAD_ERR_OK], null);
        throw new Exception('devia recusar');
    } catch (App\Services\ImagemInvalidaException $e) {
        igual('Arquivo não é uma imagem válida', $e->getMessage());
    }
    $png = imagecreatetruecolor(4, 4);
    imagepng($png, $tmp . '/ok.png');
    $nome = $img->enviar(['name' => 'ok.png', 'tmp_name' => $tmp . '/ok.png', 'size' => 100, 'error' => UPLOAD_ERR_OK], null);
    verdade(is_string($nome) && preg_match('/^vendedor_\d+_[0-9a-f]{8}\.webp$/', $nome) === 1, 'nome gerado: ' . var_export($nome, true));
    verdade(is_file($tmp . '/uploads/' . $nome), 'arquivo gravado');
    $outro = $img->enviar(['name' => 'ok.png', 'tmp_name' => $tmp . '/ok.png', 'size' => 100, 'error' => UPLOAD_ERR_OK], $nome);
    verdade(!is_file($tmp . '/uploads/' . $nome) && is_file($tmp . '/uploads/' . $outro), 'a foto antiga é apagada ao trocar');
    igual('uploads/' . $outro . '?v=' . filemtime($tmp . '/uploads/' . $outro), $img->url($outro));
    Config::definir('app.uploads', '');
});

// ═══ Música de fundo e da cerimônia ═══════════════════════════════════════════════════════════════════
t('Música de fundo: enviar valida erro, extensão e tamanho, limpa o nome e cria a pasta; a lista vem em ordem alfabética', function () use ($container, $tmp) {
    $enviar = $container->obter(EnviarMusica::class);
    $musicas = $container->obter(MusicaService::class);
    igual('Arquivo inválido', $enviar->executar(null)->mensagem());
    igual('Arquivo inválido', $enviar->executar(arquivoEnviado($tmp, 'a.mp3', 203, UPLOAD_ERR_NO_FILE))->mensagem());
    igual('Somente arquivos MP3 são aceitos', $enviar->executar(arquivoEnviado($tmp, 'a.txt'))->mensagem());
    igual('Arquivo muito grande (máx 25 MB)', $enviar->executar(arquivoEnviado($tmp, 'a.mp3', 26 * 1024 * 1024))->mensagem());
    verdade(!is_dir($tmp . '/music/bg'), 'nada foi criado pelas recusas');
    $r = $enviar->executar(arquivoEnviado($tmp, 'minha música (1).MP3'));
    igual([true, 'minha_m__sica__1_.MP3'], [$r->sucesso(), $r->dados()['name']], 'cada byte fora de [A-Za-z0-9_.-] vira _ (o ú tem 2 bytes)');
    igual('Música "minha_m__sica__1_.MP3" adicionada', $r->mensagem());
    $enviar->executar(arquivoEnviado($tmp, 'a_primeira.mp3'));
    $nomes = array_column($musicas->listarFundo(), 'name');
    verdade(in_array('a_primeira.mp3', $nomes, true), 'a música enviada aparece na lista');
    $ordenados = $nomes;
    sort($ordenados, SORT_STRING);
    igual($ordenados, $nomes, 'em ordem alfabética');
});
t('Música de fundo: "próxima" exige nome .mp3 que exista; apagar limpa a "próxima" se era ela; nome com pasta é reduzido ao nome do arquivo', function () use ($container, $tmp) {
    $proxima = $container->obter(DefinirProximaMusica::class);
    $excluir = $container->obter(ExcluirMusica::class);
    $rotacao = $container->obter(RotacaoRepository::class);
    igual('Arquivo inválido', $proxima->executar('')->mensagem());
    igual('Arquivo inválido', $proxima->executar('a.txt')->mensagem());
    igual('Arquivo não encontrado na pasta', $proxima->executar('nao_existe.mp3')->mensagem());
    igual('Música enviada para o painel', $proxima->executar('../../a_primeira.mp3')->mensagem());
    igual('a_primeira.mp3', $rotacao->proximaMusica());
    igual('Arquivo inválido', $excluir->executar('a.txt')->mensagem());
    igual('Música removida', $excluir->executar('outra.mp3')->mensagem());
    igual('a_primeira.mp3', $rotacao->proximaMusica(), 'apagar OUTRA música não mexe na próxima');
    igual('Música removida', $excluir->executar('../a_primeira.mp3')->mensagem());
    igual('', $rotacao->proximaMusica());
    verdade(!is_file($tmp . '/music/bg/a_primeira.mp3'), 'arquivo apagado');
});
t('Cerimônia: a duração fica entre 3 e 300 s; a trilha MP3 nova vem com prefixo, e a antiga é apagada', function () use ($container, $tmp, $db) {
    $cfg = $container->obter(SalvarConfiguracaoCerimonia::class);
    igual(3, $cfg->executar(1, null)->dados()['final_duration']);
    igual(300, $cfg->executar(9999, null)->dados()['final_duration']);
    igual('Somente arquivos MP3 são aceitos', $cfg->executar(20, arquivoEnviado($tmp, 'x.wav'))->mensagem());
    $a = $cfg->executar(25, arquivoEnviado($tmp, 'trilha 1.mp3'));
    verdade(preg_match('#^music/ceremony/ceremony_\d+_trilha_1\.mp3$#', (string) $a->dados()['musica']) === 1, 'caminho: ' . var_export($a->dados()['musica'], true));
    $primeira = $tmp . '/' . $a->dados()['musica'];
    verdade(is_file($primeira), 'trilha gravada');
    Relogio::fixar('2026-11-10 10:00:05');
    $b = $cfg->executar(30, arquivoEnviado($tmp, 'trilha2.mp3'));
    verdade(!is_file($primeira) && is_file($tmp . '/' . $b->dados()['musica']), 'a trilha antiga some quando entra a nova');
    $c = $cfg->executar(12, null);
    igual($b->dados()['musica'], $c->dados()['musica'], 'sem arquivo novo a trilha continua');
    igual(12, (int) $db->valor('SELECT final_duration FROM goal_ceremony WHERE id=1'));
    Relogio::fixar('2026-11-10 10:00:00');
});
t('Cerimônia: quem bateu a meta na semana anterior, do 1º ao último (pontuação, desempate pelo benefício, nome)', function () use ($container) {
    $v = $container->obter(CerimoniaService::class)->vencedoresDaSemanaAnterior();
    igual(['2026-10-28', '2026-11-03'], [$v['weekStart'], $v['weekEnd']]);
    igual(['Ana', 'Fabio', 'Bruno'], array_column($v['winners'], 'name'));
    igual([1, 2, 3], array_column($v['winners'], 'pos'));
    igual([120, 100, 100], array_column($v['winners'], 'score'));
    $ana = $v['winners'][0];
    igual([12, 10, 'Alpha', '#112233', ['aux_acidente' => 1]], [$ana['vendas'], $ana['meta'], $ana['equipeNome'], $ana['equipeCor'], $ana['tipo_totals']]);
    igual(1 / 12, $ana['conv_rate']);
});
t('Cerimônia: a meta do período (semana configurada) vence a do cadastro; sem ninguém na meta, não dispara', function () use ($container, $db) {
    semana($db, 2026, 10, 4, '2026-10-28', '2026-11-03');
    $db->executar("INSERT INTO metas_periodo (tipo, ref_id, ano, mes, semana_num, meta) VALUES ('closer', 1, 2026, 10, 4, 13), ('closer', 5, 2026, 10, 4, 3)");
    $v = $container->obter(CerimoniaService::class)->vencedoresDaSemanaAnterior();
    igual(['Fabio', 'Bruno'], array_column($v['winners'], 'name'), 'Ana (12 de 13) saiu; Fabio (4 de 3) subiu para 133');
    igual(133, $v['winners'][0]['score']);
    $db->executar('DELETE FROM semanas_mes');
    $db->executar('DELETE FROM metas_periodo');
    Relogio::fixar('2027-03-10 10:00:00');
    $r = $container->obter(DispararCerimonia::class)->executar();
    igual([false, 'Ninguém bateu a meta na semana anterior'], [$r->sucesso(), $r->mensagem()]);
    Relogio::fixar('2026-11-10 10:00:00');
});
t('Cerimônia: disparar guarda os homenageados e o horário; get_ceremony e a prévia devolvem o que a TV precisa', function () use ($container, $db) {
    $r = $container->obter(DispararCerimonia::class)->executar();
    igual([true, 3, 'Cerimônia disparada'], [$r->sucesso(), $r->dados()['count'], $r->mensagem()]);
    $cer = $container->obter(CerimoniaService::class);
    $estado = $cer->estado();
    igual(['Ana', 'Fabio', 'Bruno'], array_column($estado['winners'], 'name'));
    igual(['2026-10-28', '2026-11-03', 12], [$estado['weekStart'], $estado['weekEnd'], $estado['final_duration']]);
    verdade($estado['triggered_at'] !== null && preg_match('#^music/ceremony/ceremony_\d+_trilha2\.mp3$#', (string) $estado['musica']) === 1, 'horário e música');
    igual(3, count($cer->previa()['winners']));
    $db->executar('UPDATE goal_ceremony SET payload=NULL, triggered_at=NULL WHERE id=1');
    igual([[], null, null], [$cer->estado()['winners'], $cer->estado()['weekStart'], $cer->estado()['triggered_at']]);
});

t('Cerimônia: a semana pode ser escolhida entre as últimas 12 completas; a prévia e o disparo seguem a escolha', function () use ($container, $db) {
    Relogio::fixar('2026-11-10 10:00:00');
    $db->executar('DELETE FROM semanas_mes');
    $cer = $container->obter(CerimoniaService::class);
    $opcoes = $cer->opcoesDeSemana();
    igual([12, '2026-10-28', '2026-10-14'], [count($opcoes), $opcoes[0]['start'], $opcoes[2]['start']], 'semanas automáticas, da mais recente para a mais antiga');
    igual(null, $cer->semanaEscolhida('2026-11-01'), 'um dia que não começa uma semana completa não é aceito');
    igual('2026-10-28', $cer->semanaEscolhida(null)['start'], 'sem escolha, vale a semana anterior');
    // de 21/10 a 27/10 só a Ana tem contrato: 10 de 10, bate a meta
    $db->executar("INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (1, '2026-10-22', 10)");
    $p = $cer->previa('2026-10-21');
    igual(['2026-10-21', '2026-10-27', ['Ana'], 12], [$p['weekStart'], $p['weekEnd'], array_column($p['winners'], 'name'), count($p['semanas'])], 'a prévia é da semana escolhida, com as opções');
    igual(null, $cer->previa('2026-11-01'), 'prévia de semana inválida não existe');
    $antes = $db->linha('SELECT payload, triggered_at FROM goal_ceremony WHERE id=1');
    $r = $container->obter(DispararCerimonia::class)->executar('2026-10-21');
    igual([true, 1, '2026-10-21'], [$r->sucesso(), $r->dados()['count'], $cer->estado()['weekStart']], 'o disparo grava a semana escolhida para a TV');
    $r = $container->obter(DispararCerimonia::class)->executar('2026-11-01');
    igual([false, 'Semana inválida'], [$r->sucesso(), $r->mensagem()]);
    $r = $container->obter(DispararCerimonia::class)->executar('2026-10-14');
    igual([false, 'Ninguém bateu a meta nessa semana'], [$r->sucesso(), $r->mensagem()], 'semana escolhida sem ninguém na meta');
    $db->executar('UPDATE goal_ceremony SET payload=?, triggered_at=? WHERE id=1', [$antes['payload'], $antes['triggered_at']]);
    $db->executar("DELETE FROM contratos_historico WHERE vendedor_id = 1 AND data_registro = '2026-10-22'");
});

t('Página da TV: as músicas de comemoração (MP3 na raiz e em music/) e de fundo (music/bg); sem nenhuma, a TV procura celebration.mp3', function () use ($container, $tmp) {
    $musicas = $container->obter(MusicaService::class);
    $vazia = pastaTemp('nucleo-tv-vazia-');
    Config::definir('app.raiz_site', $vazia);
    igual(['celebracao' => ['celebration.mp3'], 'fundo' => []], $musicas->trilhasDaTv());
    mkdir($vazia . '/music/bg', 0777, true);
    foreach (['abertura.mp3', 'leia-me.txt', 'music/musica1.mp3', 'music/capa.jpg', 'music/bg/fundo1.mp3', 'music/bg/fundo2.mp3', 'music/bg/anotacao.txt'] as $arq) {
        file_put_contents($vazia . '/' . $arq, 'x');
    }
    igual(['celebracao' => ['abertura.mp3', 'music/musica1.mp3'], 'fundo' => ['music/bg/fundo1.mp3', 'music/bg/fundo2.mp3']], $musicas->trilhasDaTv());
    apagar($vazia);
    Config::definir('app.raiz_site', $tmp);
});

// ═══ Retrospectiva ═════════════════════════════════════════════════════════════════════════════════════
t('Retrospectiva (semana): ranking, quem bateu, melhor equipe (ex-membros somam) e vice', function () use ($container) {
    $r = $container->obter(RetrospectivaService::class)->retrospectiva()['semana'];
    igual('Semana anterior · 28/10 a 03/11', $r['label']);
    igual(['28', '29', '30', '31', '01', '02', '03'], $r['dias']);
    igual(['2026-10-28', '2026-11-03', 28, 3], [$r['inicio'], $r['fim'], $r['total'], $r['bateram']]);
    igual(['Ana', 'Fabio', 'Bruno'], array_column($r['closers'], 'name'));
    igual([1, 2, 3], array_column($r['closers'], 'pos'));
    $ana = $r['closers'][0];
    igual([12, 10, 120, 0, true, 50.0], [$ana['vendas'], $ana['metas'], $ana['score'], $ana['faltam'], $ana['bateu'], $ana['conv_rate']]);
    igual([0, 6, 0, 0, 0, 6, 0], $ana['daily']);
    igual(['Alpha', 'Beta'], array_column($r['equipes'], 'nome'));
    $alpha = $r['equipes'][0];
    igual([19, 1, 10, 190, true], [$alpha['vendas'], $alpha['members'], $alpha['metas'], $alpha['score'], $alpha['bateu']], 'Alpha = Ana 12 + Caio (desligado) 3 + Eva (suspensa) 4');
    igual([0, 6, 0, 3, 0, 10, 0], $alpha['daily']);
    igual('Alpha', $r['melhor_equipe']['nome']);
    igual(['nome' => 'Beta', 'score' => 100, 'vendas' => 9], $r['vice']);
    verdade(!isset($r['semanas']), 'só o mês tem a lista de semanas');
});
t('Retrospectiva (mês): rótulo, semanas do calendário, metas do mês pela soma das semanas e contratos fora do mês que caem numa semana do mês', function () use ($container) {
    $r = $container->obter(RetrospectivaService::class)->retrospectiva()['mes'];
    igual('Mês anterior · Outubro 2026', $r['label']);
    igual(['S1', 'S2', 'S3', 'S4'], $r['semanas']);
    igual(['2026-10-01', '2026-10-31', 17], [$r['inicio'], $r['fim'], $r['total']]);
    igual(['Bruno', 'Ana', 'Fabio'], array_column($r['closers'], 'name'), 'Bruno 25%, Ana 23% (9 de 40), Fabio 0%');
    igual([25, 23, 0], array_column($r['closers'], 'score'));
    $ana = $r['closers'][1];
    igual([9, 40, [3, 0, 0, 12]], [$ana['vendas'], $ana['metas'], $ana['week_scores']], 'a S4 (28/10 a 03/11) conta os contratos de novembro');
    $alpha = $r['equipes'][0];
    igual(['Alpha', 12, 40, 30], [$alpha['nome'], $alpha['vendas'], $alpha['metas'], $alpha['score']]);
    igual('Alpha', $r['melhor_equipe']['nome']);
});
t('Retrospectiva: dezembro olhando para novembro e janeiro olhando para dezembro (virada de ano)', function () use ($container) {
    Relogio::fixar('2027-01-05 10:00:00');
    $r = $container->obter(RetrospectivaService::class)->retrospectiva();
    igual('Mês anterior · Dezembro 2026', $r['mes']['label']);
    igual(['2026-12-01', '2026-12-31'], [$r['mes']['inicio'], $r['mes']['fim']]);
    igual(5, count($r['mes']['semanas']), 'dezembro/2026 tem 5 quartas-feiras (2, 9, 16, 23 e 30)');
    Relogio::fixar('2026-11-10 10:00:00');
});

// ═══ Request: arquivos ═══════════════════════════════════════════════════════════════════════════════
t('Request::arquivo: devolve o item do $_FILES ou null', function () {
    $r = req('POST', '/x')->comArquivos(['music' => ['name' => 'a.mp3', 'error' => 0], 'lixo' => 'texto']);
    igual('a.mp3', $r->arquivo('music')['name']);
    igual(null, $r->arquivo('foto1'));
    igual(null, $r->arquivo('lixo'));
});

echo "\n$passaram passaram, " . count($falharam) . " falharam\n";
exit($falharam ? 1 : 0);
