<?php
declare(strict_types=1);

use App\Controllers\AcessoCloserController;
use App\Controllers\ApiPorAcaoController;
use App\Controllers\PaginasController;
use App\Controllers\AtendimentoController;
use App\Controllers\AtividadeLiderController;
use App\Controllers\CalendarioController;
use App\Controllers\CerimoniaController;
use App\Controllers\ContratoController;
use App\Controllers\EquipeController;
use App\Controllers\HealthController;
use App\Controllers\InvestimentoController;
use App\Controllers\LiderAdminController;
use App\Controllers\LiderController;
use App\Controllers\LiderPortalController;
use App\Controllers\LiderRegistrosController;
use App\Controllers\LiderVisaoController;
use App\Controllers\MetaController;
use App\Controllers\MeusFeedbacksController;
use App\Controllers\MusicaController;
use App\Controllers\PainelController;
use App\Controllers\PopupController;
use App\Controllers\ProdutividadeController;
use App\Controllers\PropagandaController;
use App\Controllers\RelatorioController;
use App\Controllers\RetrospectivaController;
use App\Controllers\RotacaoController;
use App\Controllers\TesteController;
use App\Controllers\TipoBeneficioController;
use App\Controllers\TvController;
use App\Controllers\VendedorController;
use App\Core\Config;
use App\Core\Router;

/**
 * Mapa de rotas do núcleo. Cada fase da migração acrescenta as suas aqui (Relatórios na fase 2, TV na 3, ...).
 * O front controller faz: $rotas = require 'app/routes.php'; $rotas($router);
 */
return static function (Router $r): void {
    // ── Fase 9b: as páginas do site (antes index.php, login.php, admin.php, closer*.php e lider.php na raiz).
    $r->get('/', [TvController::class, 'pagina']);
    $r->get('/login', [PaginasController::class, 'adminEntrar']);
    $r->get('/logout', [PaginasController::class, 'adminSair']);
    $r->get('/admin', [PaginasController::class, 'admin']);
    $r->get('/closer', [PaginasController::class, 'closerPortal']);
    $r->get('/closer/login', [PaginasController::class, 'closerEntrar']);
    $r->get('/closer/cadastro', [PaginasController::class, 'closerCadastrar']);
    $r->get('/closer/recuperar', [PaginasController::class, 'closerRecuperar']);
    $r->get('/lider', [PaginasController::class, 'liderPortal']);

    // ── Fase 9a: a porta única por "action" (o que o api.php e o closer-api.php faziam). Login, CSRF e regras ficam no controller.
    $r->get('/api', [ApiPorAcaoController::class, 'admin']);
    $r->post('/api', [ApiPorAcaoController::class, 'admin']);
    $r->post('/api/closer', [ApiPorAcaoController::class, 'closer']);

    // ── Fase 2: Relatórios (todas exigem o login do admin) ──────────────────────────────────────
    // As URLs antigas (api.php?action=get_relatorio_closers, get_custo_contrato, relatorio_closers_pdf, get_monthly_summary) continuam
    // funcionando: o api.php checa o login e chama os mesmos métodos destes Controllers pela ponte (nucleo-ponte.php).
    $r->get('/api/relatorios/closers', [RelatorioController::class, 'closers'], ['auth:admin']);
    $r->get('/api/relatorios/closers.pdf', [RelatorioController::class, 'closersPdf'], ['auth:admin']);
    $r->get('/api/relatorios/custo-contrato', [RelatorioController::class, 'custoContrato'], ['auth:admin']);
    $r->get('/api/relatorios/resumo-mensal', [RelatorioController::class, 'resumoMensal'], ['auth:admin']);

    // ── Fase 3 (TV): propaganda, pop-up, cerimônia, música de fundo, rotação e Retrospectiva ─────────────
    // As leituras são PÚBLICAS (a TV não tem login); toda escrita exige o login do admin e o token CSRF. As URLs antigas do api.php
    // (get_propagandas, add_propaganda, save_rotation, get_retrospectiva...) continuam funcionando: o api.php confere o login e chama os
    // mesmos métodos destes Controllers pela ponte (nucleo-ponte.php). Os campos são os mesmos de sempre (titulo, conteudo_html, id...).
    $escrita = ['auth:admin', 'csrf'];
    $r->get('/api/propagandas', [PropagandaController::class, 'listar']);
    $r->post('/api/propagandas', [PropagandaController::class, 'adicionar'], $escrita);
    $r->post('/api/propagandas/atualizar', [PropagandaController::class, 'atualizar'], $escrita);
    $r->post('/api/propagandas/excluir', [PropagandaController::class, 'excluir'], $escrita);
    $r->post('/api/propagandas/alternar', [PropagandaController::class, 'alternar'], $escrita);

    $r->get('/api/popup', [PopupController::class, 'ver']);
    $r->post('/api/popup', [PopupController::class, 'salvar'], $escrita);
    $r->post('/api/popup/disparar', [PopupController::class, 'disparar'], $escrita);

    $r->get('/api/cerimonia', [CerimoniaController::class, 'ver']);
    $r->get('/api/cerimonia/previa', [CerimoniaController::class, 'previa'], ['auth:admin']);
    $r->post('/api/cerimonia/configuracao', [CerimoniaController::class, 'configurar'], $escrita);
    $r->post('/api/cerimonia/disparar', [CerimoniaController::class, 'disparar'], $escrita);

    $r->get('/api/musica-fundo', [MusicaController::class, 'listar']);
    $r->post('/api/musica-fundo', [MusicaController::class, 'enviar'], $escrita);
    $r->post('/api/musica-fundo/excluir', [MusicaController::class, 'excluir'], $escrita);
    $r->post('/api/musica-fundo/proxima', [MusicaController::class, 'definirProxima'], $escrita);

    $r->get('/api/rotacao', [RotacaoController::class, 'ver']);
    $r->post('/api/rotacao', [RotacaoController::class, 'salvar'], $escrita);

    $r->get('/api/retrospectiva', [RetrospectivaController::class, 'ver']);

    // ── Fase 3 (TV e Painel): os rankings. 'ferias' liga/desliga as férias agendadas antes de ler (o api.php já faz isso no começo de
    // toda chamada, então pela ponte o middleware não é preciso). A leitura da TV é pública; as do Painel exigem o login do admin.
    $r->get('/api/tv/dados', [TvController::class, 'dados'], ['ferias']);
    $r->get('/api/tv/contratos-total', [TvController::class, 'contratosTotal']);
    $r->get('/api/painel', [PainelController::class, 'painel'], ['auth:admin', 'ferias']);
    $r->get('/api/painel/rankings', [PainelController::class, 'rankings'], ['auth:admin', 'ferias']);
    $r->get('/api/painel/graficos', [PainelController::class, 'grafico'], ['auth:admin', 'ferias']);
    $r->get('/api/painel/resumo-semana', [PainelController::class, 'resumoSemana'], ['auth:admin', 'ferias']);

    // ── Fase 4: Metas, Calendário e Investimento (o Meta Ads foi removido: o investimento é só o digitado à mão) ───────────────────
    // As URLs antigas do api.php (get_metas_periodo, save_meta_excecao, get_semanas_mes, save_investimento_mensal...) continuam funcionando: o api.php
    // confere o login e chama os mesmos métodos destes Controllers pela ponte (nucleo-ponte.php). Os campos são os de sempre (ano, mes, metas, alvo...).
    // As leituras do Calendário são PÚBLICAS (como sempre foram); todo o resto exige o login do admin, e as gravações também o token CSRF.
    $r->get('/api/calendario/semanas', [CalendarioController::class, 'semanasMes']);
    $r->get('/api/calendario/mes', [CalendarioController::class, 'semanasDoMes']);
    $r->get('/api/calendario/historico', [CalendarioController::class, 'historico']);
    $r->post('/api/calendario/semanas', [CalendarioController::class, 'salvarSemanas'], $escrita);
    $r->post('/api/calendario/semanas/excluir', [CalendarioController::class, 'excluirSemanas'], $escrita);
    $r->post('/api/calendario/dias-da-semana', [CalendarioController::class, 'salvarDias'], $escrita);

    $r->get('/api/investimento/historico', [InvestimentoController::class, 'historico'], ['auth:admin']);
    $r->post('/api/investimento/mensal', [InvestimentoController::class, 'salvarMensal'], $escrita);
    $r->post('/api/investimento/mensal/excluir', [InvestimentoController::class, 'excluirMensal'], $escrita);
    $r->post('/api/investimento/mes', [InvestimentoController::class, 'salvarDoMes'], $escrita);

    $r->get('/api/metas/periodo', [MetaController::class, 'periodo'], ['auth:admin']);
    $r->post('/api/metas/periodo', [MetaController::class, 'salvarPeriodo'], $escrita);
    $r->get('/api/metas/diarias', [MetaController::class, 'diariasDoMes'], ['auth:admin']);
    $r->post('/api/metas/diarias', [MetaController::class, 'salvarDiariasDoMes'], $escrita);
    $r->get('/api/metas/excecoes', [MetaController::class, 'excecoes'], ['auth:admin']);
    $r->post('/api/metas/excecoes', [MetaController::class, 'salvarExcecao'], $escrita);
    $r->post('/api/metas/excecoes/remover', [MetaController::class, 'removerExcecao'], $escrita);
    $r->get('/api/metas/excecoes-grupo', [MetaController::class, 'excecoesGrupo'], ['auth:admin']);
    $r->post('/api/metas/excecoes-grupo', [MetaController::class, 'salvarExcecaoGrupo'], $escrita);
    $r->post('/api/metas/excecoes-grupo/remover', [MetaController::class, 'removerExcecaoGrupo'], $escrita);
    $r->get('/api/metas/diarias/estado', [MetaController::class, 'estadoDiario'], ['auth:admin']);
    $r->post('/api/metas/diarias/aplicar', [MetaController::class, 'aplicarDiaria'], $escrita);
    $r->post('/api/metas/diarias/closer', [MetaController::class, 'salvarDiariaCloser'], $escrita);
    $r->post('/api/metas/diarias/excecao/remover', [MetaController::class, 'removerDiariaExcecao'], $escrita);
    $r->post('/api/metas/cac', [MetaController::class, 'salvarCac'], $escrita);

    // ── Fase 5: closers, equipes, contratos, tipos de benefício, atendimentos e produtividade ───────────────────────
    // As URLs antigas do api.php (add_vendedor, move_vendedor, get_matriz, feed_add, save_tipo_custom, get_produtividade...) continuam funcionando: o
    // api.php confere o login e chama os mesmos métodos destes Controllers pela ponte (nucleo-ponte.php). Os campos são os de sempre (nome, equipe_id,
    // vendedor_id, data_registro...). Tudo exige o login do admin (a lista crua de tipos personalizados é pública, como sempre foi); as gravações também
    // exigem o token CSRF.
    $r->get('/api/equipes', [EquipeController::class, 'listar'], ['auth:admin']);
    $r->post('/api/equipes', [EquipeController::class, 'adicionar'], $escrita);
    $r->post('/api/equipes/atualizar', [EquipeController::class, 'atualizar'], $escrita);
    $r->post('/api/equipes/excluir', [EquipeController::class, 'excluir'], $escrita);
    $r->post('/api/equipes/lider', [EquipeController::class, 'definirLider'], $escrita);

    $r->get('/api/vendedores/cadastro', [VendedorController::class, 'ativosCadastro'], ['auth:admin']);
    $r->get('/api/vendedores/ferias', [VendedorController::class, 'suspensos'], ['auth:admin']);
    $r->get('/api/vendedores/arquivados', [VendedorController::class, 'arquivados'], ['auth:admin']);
    $r->get('/api/vendedores/elenco', [VendedorController::class, 'elenco'], ['auth:admin']);
    $r->get('/api/vendedores/visao-do-time', [VendedorController::class, 'visaoDoTime'], ['auth:admin']);
    $r->post('/api/vendedores', [VendedorController::class, 'adicionar'], $escrita);
    $r->post('/api/vendedores/atualizar', [VendedorController::class, 'atualizar'], $escrita);
    $r->post('/api/vendedores/arquivar', [VendedorController::class, 'arquivar'], $escrita);
    $r->post('/api/vendedores/restaurar', [VendedorController::class, 'restaurar'], $escrita);
    $r->post('/api/vendedores/suspender', [VendedorController::class, 'suspender'], $escrita);
    $r->post('/api/vendedores/reativar', [VendedorController::class, 'reativar'], $escrita);
    $r->post('/api/vendedores/ferias', [VendedorController::class, 'ferias'], $escrita);
    $r->post('/api/vendedores/mover', [VendedorController::class, 'mover'], $escrita);
    $r->post('/api/vendedores/lote', [VendedorController::class, 'emLote'], $escrita);

    $r->post('/api/contratos', [ContratoController::class, 'salvar'], $escrita);
    $r->get('/api/contratos/tipos-do-dia', [ContratoController::class, 'tiposDoDia'], ['auth:admin']);
    $r->post('/api/contratos/tipos-do-dia', [ContratoController::class, 'salvarTipos'], $escrita);
    $r->get('/api/contratos/feed', [ContratoController::class, 'feed'], ['auth:admin']);
    $r->post('/api/contratos/feed', [ContratoController::class, 'adicionarVenda'], $escrita);
    $r->post('/api/contratos/feed/atualizar', [ContratoController::class, 'atualizarVenda'], $escrita);
    $r->post('/api/contratos/feed/excluir', [ContratoController::class, 'excluirVenda'], $escrita);
    $r->get('/api/contratos/matriz', [ContratoController::class, 'matriz'], ['auth:admin']);
    $r->get('/api/contratos/desempate', [ContratoController::class, 'desempate'], ['auth:admin']);
    $r->post('/api/contratos/desempate', [ContratoController::class, 'salvarDesempate'], $escrita);

    $r->get('/api/tipos-beneficio', [TipoBeneficioController::class, 'personalizados']);
    $r->get('/api/tipos-beneficio/configuracao', [TipoBeneficioController::class, 'configuracao'], ['auth:admin']);
    $r->post('/api/tipos-beneficio/personalizados', [TipoBeneficioController::class, 'salvarCustom'], $escrita);
    $r->post('/api/tipos-beneficio/personalizados/excluir', [TipoBeneficioController::class, 'excluirCustom'], $escrita);
    $r->post('/api/tipos-beneficio/fixos', [TipoBeneficioController::class, 'salvarFixo'], $escrita);

    // Fase 7: gestão dos acessos ao portal do closer (aba Equipes). O admin.php usa as ações get_acessos e acesso_* do api.php, que chamam estes métodos.
    $r->get('/api/acessos', [AcessoCloserController::class, 'listar'], ['auth:admin']);
    $r->post('/api/acessos/aprovar', [AcessoCloserController::class, 'aprovar'], $escrita);
    $r->post('/api/acessos/editar', [AcessoCloserController::class, 'editar'], $escrita);
    $r->post('/api/acessos/rejeitar', [AcessoCloserController::class, 'rejeitar'], $escrita);
    $r->post('/api/acessos/revogar', [AcessoCloserController::class, 'revogar'], $escrita);
    $r->post('/api/acessos/reconsiderar', [AcessoCloserController::class, 'reconsiderar'], $escrita);
    $r->post('/api/acessos/excluir', [AcessoCloserController::class, 'excluir'], $escrita);
    $r->post('/api/acessos/convite', [AcessoCloserController::class, 'convite'], $escrita);
    $r->post('/api/acessos/token-de-senha', [AcessoCloserController::class, 'tokenDeSenha'], $escrita);

    $r->get('/api/atendimentos', [AtendimentoController::class, 'listar'], ['auth:admin']);
    $r->post('/api/atendimentos', [AtendimentoController::class, 'salvar'], $escrita);
    $r->post('/api/atendimentos/lote', [AtendimentoController::class, 'salvarEmLote'], $escrita);

    $r->get('/api/produtividade', [ProdutividadeController::class, 'dados'], ['auth:admin']);
    $r->get('/api/produtividade/closer', [ProdutividadeController::class, 'closer'], ['auth:admin']);
    $r->get('/api/produtividade/acoes', [ProdutividadeController::class, 'acoes'], ['auth:admin']);

    // ── Módulo do líder, fase L1: acesso e dados ────────────────────────────────────────────
    // O líder é um closer com liderança de equipe; a checagem é feita a cada requisição (AuthLider). Esta rota só confirma o escopo.
    $r->get('/api/lider/escopo', [LiderController::class, 'escopo'], ['lider']);
    // Fase L2: o papel do usuário (closer ou líder), a visão do time (só da equipe dele) e a seção Líderes do admin.
    $r->get('/api/lider/papel', [LiderController::class, 'papel'], ['auth:closer']);
    $r->get('/api/lider/visao', [LiderVisaoController::class, 'visao'], ['lider']);
    $r->get('/api/admin/lideres', [LiderAdminController::class, 'lideres'], ['auth:admin']);
    // Fase L3: "Atividades dos líderes" da Produtividade do admin (só leitura; o api.php usa get_atividades_lideres e get_atividade_lider_detalhe).
    $r->get('/api/admin/atividades-lideres', [AtividadeLiderController::class, 'resumo'], ['auth:admin']);
    $r->get('/api/admin/atividades-lideres/{equipe:\d+}', [AtividadeLiderController::class, 'detalhe'], ['auth:admin']);
    // Fase L3: registros da liderança (reuniões, feedbacks, cobranças, revisões e treinamentos). A equipe vem do middleware 'lider'; toda
    // escrita exige o token CSRF do portal ('csrf:closer'). Não há rota de exclusão. O portal usa as ações lider_registros, lider_registro_salvar
    // e lider_registro_acao do closer-api.php, que chamam estes mesmos métodos pela ponte.
    $registro = '/api/lider/registros/{entidade:[a-z]+}';
    $escritaLider = ['lider', 'csrf:closer'];
    $r->get($registro, [LiderRegistrosController::class, 'listar'], ['lider']);
    $r->post($registro, [LiderRegistrosController::class, 'criar'], $escritaLider);
    $r->post($registro . '/{id:\d+}', [LiderRegistrosController::class, 'editar'], $escritaLider);
    $r->post($registro . '/{id:\d+}/{acao:[a-z]+}', [LiderRegistrosController::class, 'acao'], $escritaLider);
    // Fase L4: o portal do líder (Dashboard, Equipe, Agenda, busca e Configurações). A equipe vem do middleware 'lider'. O portal usa as ações
    // lider_dashboard, lider_equipe, lider_closer, lider_agenda, lider_busca, lider_preferencias e lider_preferencias_salvar do closer-api.php.
    $r->get('/api/lider/dashboard', [LiderPortalController::class, 'dashboardGeral'], ['lider']);
    $r->get('/api/lider/equipe', [LiderPortalController::class, 'equipe'], ['lider']);
    $r->get('/api/lider/closer/{id:\d+}', [LiderPortalController::class, 'closer'], ['lider']);
    $r->get('/api/lider/agenda', [LiderPortalController::class, 'agenda'], ['lider']);
    $r->get('/api/lider/busca', [LiderPortalController::class, 'busca'], ['lider']);
    $r->get('/api/lider/preferencias', [LiderPortalController::class, 'preferencias'], ['lider']);
    $r->post('/api/lider/preferencias', [LiderPortalController::class, 'preferenciasSalvar'], $escritaLider);
    // "Meus feedbacks" do closer: o que o líder registrou para ele (leitura e o "visto"). O closer vem da sessão.
    $r->get('/api/closer/feedbacks', [MeusFeedbacksController::class, 'listar'], ['auth:closer']);
    $r->get('/api/closer/feedbacks/novos', [MeusFeedbacksController::class, 'novos'], ['auth:closer']);
    $r->post('/api/closer/feedbacks/vistos', [MeusFeedbacksController::class, 'vistos'], ['auth:closer', 'csrf:closer']);

    // ── Rotas de teste da fase 1 (temporárias) ───────────────────────────────────────────────
    // Todas atrás da chave (?k=). Para removê-las: config/app.php => 'rotas_teste' => false (ou apague este bloco).
    if (Config::obter('app.rotas_teste', false) === true) {
        $r->get('/health', [HealthController::class, 'status'], ['chave']);

        $r->get('/_teste/eco', [TesteController::class, 'eco'], ['chave']);
        $r->post('/_teste/eco', [TesteController::class, 'eco'], ['chave']);
        $r->get('/_teste/parametro/{id:\d+}', [TesteController::class, 'parametro'], ['chave']);
        $r->get('/_teste/visao', [TesteController::class, 'visao'], ['chave']);
        $r->post('/_teste/csrf', [TesteController::class, 'csrf'], ['chave', 'csrf']);
        $r->get('/_teste/admin', [TesteController::class, 'admin'], ['chave', 'auth:admin']);
        $r->get('/_teste/limite', [TesteController::class, 'limite'], ['chave', 'ratelimit:teste,3,60']);
    }
};
