<?php
/**
 * Portal do Líder (lider.php): a página inteira, no mesmo estilo do admin (barra lateral com a marca e menu por grupos, barra superior com
 * migalhas, busca e sino, cabeçalho de página e cartões iguais). A barra lateral tem dois blocos de navegação: LIDERANÇA (Dashboard, Equipe,
 * Agenda) e MEU PORTAL (CLOSER) (Meu dia e Meus contratos), porque o líder também é closer e faz tudo num lugar só. Mais Configurações.
 * Não há tela de Registros: reuniões, treinamentos, feedbacks, cobranças e revisões são as ATIVIDADES da Agenda ("Nova atividade"), e o
 * Registro de Ação Rápida é um cartão no topo do Meu dia. A barra lateral recolhe para 64px (desktop) e vira um menu deslizante (celular).
 * O JavaScript (assets/js/lider/*.js) preenche tudo a partir do closer-api.php.
 * @var \App\Core\View $v
 * @var array<string, mixed> $boot
 * @var string $bootJson
 */
$ico = static function (string $caminhos): string {
    return '<svg class="lp-ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $caminhos . '</svg>';
};
$icones = [
    'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    'equipe' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'agenda' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'meu-dia' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>',
    'contratos' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15h6"/>',
    'config' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
    'buscar' => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
    'sino' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'menu' => '<path d="M3 12h18M3 6h18M3 18h18"/>',
    'sair' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
    'recolher' => '<path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/>',
    'fechar' => '<path d="M6 6l12 12M18 6L6 18"/>',
    'perfil' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'calendario' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
];
/** O menu: grupos de navegação, cada um com [chave da aba, rótulo]. */
$menu = [
    'Liderança' => [['dashboard', 'Dashboard'], ['equipe', 'Equipe'], ['agenda', 'Agenda']],
    'Meu portal (closer)' => [['meu-dia', 'Meu dia'], ['contratos', 'Meus contratos']],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<?= $v->parcial('partials/api') ?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf" content="<?= $v->e($boot['csrf']) ?>">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#050505">
<title>Portal do Líder – TOP CLOSERS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/tokens.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/componentes.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/portal-lider.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/shell-admin.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/meu-portal.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/rail.css')) ?>">
</head>
<body class="lp">
<a class="lp-skip" href="#lpMain">Pular para o conteúdo</a>

<div class="lp-shell ld" id="lpShell">

  <!-- ═══ BARRA LATERAL (256px; recolhe para 64px; no celular vira menu deslizante) ═══ -->
  <aside class="lp-side" id="lpSide" aria-label="Menu do líder">
    <div class="sb-top">
      <button type="button" class="sb-logo" id="lpLogo" aria-label="TOP CLOSERS: ir ao Dashboard"><span class="sb-name">Top<br>Lendários</span></button>
      <button type="button" class="sb-x" id="lpMenuX" aria-label="Fechar o menu"><?= $ico($icones['fechar']) ?></button>
    </div>

    <nav class="lp-nav sb-nav" id="lpNav" aria-label="Seções do portal">
<?php foreach ($menu as $grupo => $itens): ?>
      <div class="sb-group"><?= $v->e($grupo) ?></div>
<?php foreach ($itens as [$chave, $rotulo]): ?>
      <button type="button" class="lp-nav-b" id="nav-<?= $chave ?>" data-tab="<?= $chave ?>"<?= $chave === 'dashboard' ? ' aria-current="page"' : '' ?>><?= $ico($icones[$chave]) ?><span class="sb-lbl"><?= $v->e($rotulo) ?></span><b class="lp-nav-n" data-badge="<?= $chave ?>" hidden></b></button>
<?php endforeach; ?>
<?php endforeach; ?>
    </nav>

    <div class="sb-foot">
      <button type="button" class="lp-nav-b" id="nav-config" data-tab="config"><?= $ico($icones['config']) ?><span class="sb-lbl">Configurações</span></button>
      <button type="button" class="sb-btn sb-collapse" id="lpCollapse" aria-expanded="true" aria-controls="lpSide" title="Recolher menu"><?= $ico($icones['recolher']) ?><span class="sb-lbl">Recolher menu</span></button>
    </div>
  </aside>
  <div class="lp-scrim" id="lpScrim" hidden></div>

  <!-- ═══ ÁREA PRINCIPAL ═══ -->
  <div class="lp-wrap main-area">
    <header class="lp-top topbar">
      <div class="tb-left">
        <button class="lp-burger tb-burger" id="lpBurger" type="button" aria-label="Abrir o menu" aria-controls="lpSide" aria-expanded="false"><?= $ico($icones['menu']) ?></button>
      </div>
      <div class="tb-title" id="lpTbTitle">Dashboard</div>
      <div class="tb-center"><span class="lp-data-pill" title="Hoje"><?= $ico($icones['calendario']) ?><span id="lpData"></span></span></div>
      <div class="lp-top-a tb-right">
        <button class="lp-search-b" id="lpBuscar" type="button" aria-label="Busca global (Ctrl+K)"><?= $ico($icones['buscar']) ?><span>Buscar</span><kbd>Ctrl K</kbd></button>
        <div class="tb-account">
          <div class="lp-bell-w">
            <button class="lp-bell" id="lpSino" type="button" aria-label="Notificações" aria-haspopup="true" aria-expanded="false"><?= $ico($icones['sino']) ?><b class="lp-bell-n" id="lpSinoN" hidden>0</b></button>
            <div class="lp-pop" id="lpSinoPop" role="region" aria-label="Notificações" hidden></div>
          </div>
          <i class="tb-sep" aria-hidden="true"></i>
          <div class="tb-prof-w">
            <button type="button" class="tb-prof" id="lpPerfilBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="lpPerfilMenu"><span class="tb-prof-n" id="lpNome"><?= $v->e($boot['nome']) ?></span><svg class="tb-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg><span class="tb-av" id="lpAvatar" aria-hidden="true"></span></button>
            <div class="tb-menu" id="lpPerfilMenu" role="menu" aria-label="Conta" hidden>
              <button type="button" role="menuitem" id="lpMenuPerfil"><?= $ico($icones['perfil']) ?>Meu perfil</button>
              <button type="button" role="menuitem" id="lpMenuConfig"><?= $ico($icones['config']) ?>Configurações</button>
              <button type="button" role="menuitem" class="tb-sair" id="lpSair"><?= $ico($icones['sair']) ?>Sair</button>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="lp-main content" id="lpMain" tabindex="-1">

      <div class="page-header" id="lpPghead">
        <h1 class="page-title" id="lpTitulo">Painel da equipe</h1>
        <p class="page-subtitle" id="lpSubtitulo"></p>
      </div>

      <!-- ── Dashboard ── -->
      <section class="lp-pane" id="pane-dashboard" data-pane="dashboard" aria-labelledby="nav-dashboard">
        <div class="lp-grid lp-g-top">
          <article class="lp-card" aria-labelledby="dPerfH">
            <div class="lp-card-h"><h2 id="dPerfH">Performance da equipe</h2><span class="lp-sub" id="dPerfSub"></span></div>
            <div id="dPerf" aria-live="polite"></div>
          </article>
          <article class="lp-card" aria-labelledby="dAlH">
            <div class="lp-card-h"><h2 id="dAlH">Alertas proativos</h2><span class="lp-sub" id="dAlSub"></span></div>
            <div id="dAlertas" aria-live="polite"></div>
          </article>
        </div>
        <div class="lp-grid lp-g-mid">
          <article class="lp-card" aria-labelledby="dPenH">
            <div class="lp-card-h"><h2 id="dPenH">Ações pendentes</h2><span class="lp-sub" id="dPenSub"></span></div>
            <div id="dPend" aria-live="polite"></div>
          </article>
          <article class="lp-card" aria-labelledby="dCmpH">
            <div class="lp-card-h"><h2 id="dCmpH">Comparação com o Comercial</h2><span class="lp-sub" id="dCmpSub"></span></div>
            <div id="dComp" aria-live="polite"></div>
          </article>
        </div>
        <article class="lp-card" aria-labelledby="dProdH">
          <div class="lp-card-h"><h2 id="dProdH">Produtividade da equipe</h2>
            <div class="ld-seg" id="dProdSeg" role="tablist" aria-label="Período da produtividade">
              <button type="button" role="tab" data-per="hoje" aria-selected="true" class="active">Hoje</button>
              <button type="button" role="tab" data-per="semana" aria-selected="false">Semana</button>
              <button type="button" role="tab" data-per="mes" aria-selected="false">Mês</button>
            </div></div>
          <div id="dProd" aria-live="polite"></div>
        </article>
      </section>

      <!-- ── Equipe ── -->
      <section class="lp-pane" id="pane-equipe" data-pane="equipe" aria-labelledby="nav-equipe" hidden>
        <div class="lp-barra"><p id="eqSub" class="lp-sub"></p></div>
        <div id="eqLista" class="lp-closers" aria-live="polite"></div>
      </section>

      <!-- ── Agenda ── -->
      <section class="lp-pane" id="pane-agenda" data-pane="agenda" aria-labelledby="nav-agenda" hidden>
        <div class="lp-barra">
          <div class="lp-mes">
            <button class="ld-btn sm" id="agPrev" type="button" aria-label="Mês anterior">‹</button>
            <strong id="agTitulo" aria-live="polite"></strong>
            <button class="ld-btn sm" id="agNext" type="button" aria-label="Próximo mês">›</button>
            <button class="ld-btn sm" id="agHoje" type="button">Hoje</button>
          </div>
          <button class="ld-btn pri" id="agNovo" type="button">+ Nova atividade</button>
        </div>
        <div class="lp-grid lp-g-ag">
          <article class="lp-card" aria-label="Calendário do mês">
            <div class="lp-legenda"><span><i class="lp-pt reu"></i>Reunião de equipe</span><span><i class="lp-pt trei"></i>Treinamento</span><span><i class="lp-pt fdb"></i>Feedback</span><span><i class="lp-pt cob"></i>Cobrança</span><span><i class="lp-pt rev"></i>Revisão de atendimento</span></div>
            <div id="agCal" aria-live="polite"></div>
          </article>
          <article class="lp-card" aria-labelledby="agProxH">
            <div class="lp-card-h"><h2 id="agProxH">Próximas atividades</h2><span class="lp-sub">reuniões e treinamentos · 7 dias</span></div>
            <div id="agProx" aria-live="polite"></div>
          </article>
        </div>
      </section>

      <!-- ── Meu dia (portal do closer) ── -->
      <section class="lp-pane" id="pane-meu-dia" data-pane="meu-dia" aria-labelledby="nav-meu-dia" hidden>
        <!-- Registro de Ação Rápida: digite o número e clique no tipo — salva na hora -->
        <section class="lp-card lp-quick" id="lpQuick" aria-labelledby="qfH">
          <div class="lp-card-h"><h2 id="qfH">Registrar ação rápida</h2></div>
          <div class="lp-quick-body">
            <div class="lp-fld">
              <label for="qfNum">Número do cliente</label>
              <div class="lp-paste">
                <input class="lp-in" id="qfNum" type="tel" inputmode="tel" autocomplete="off" placeholder="(00) 00000-0000" aria-describedby="qfErr">
                <button class="ld-btn sm" id="qfPaste" type="button" aria-label="Colar o número da área de transferência">Colar</button>
              </div>
              <div class="lp-ferr" id="qfErr" role="alert"></div>
            </div>
            <div class="lp-qr3" id="qfTipos" role="group" aria-label="Escolha o tipo para salvar">
              <button class="lp-qtb c-lig" type="button" data-t="ligacao"><?= $boot['ic']['phone'] ?>Ligação</button>
              <button class="lp-qtb c-fu" type="button" data-t="followup"><?= $boot['ic']['refresh'] ?>Follow-up</button>
              <button class="lp-qtb c-lq" type="button" data-t="lead_quente"><?= $boot['ic']['flame'] ?>Lead Quente</button>
            </div>
          </div>
          <p class="lp-hint">Digite o número e clique no tipo — salva na hora. Atalho: Ctrl+N.</p>
        </section>
        <div class="mp-sum" id="mdResumo" aria-live="polite">
          <div class="mp-sum-c c-lig" data-k="ligacao"><div class="mp-sum-l"><?= $boot['ic']['phone'] ?>Ligações</div><div class="mp-sum-n" id="nLig">0</div></div>
          <div class="mp-sum-c c-fu" data-k="followup"><div class="mp-sum-l"><?= $boot['ic']['refresh'] ?>Follow-ups</div><div class="mp-sum-n" id="nFu">0</div></div>
          <div class="mp-sum-c c-lq" data-k="lead_quente"><div class="mp-sum-l"><?= $boot['ic']['flame'] ?>Leads Quentes</div><div class="mp-sum-n" id="nLq">0</div></div>
        </div>
        <section class="lp-card" id="mdFeedbacks" aria-labelledby="mdFbH" hidden>
          <div class="lp-card-h"><h2 id="mdFbH">Meus feedbacks <span class="lp-badge" id="mdFbBd" hidden></span></h2><button type="button" class="ld-btn sm" id="mdFbToggle" aria-expanded="false" aria-controls="mdFbLista">Abrir</button></div>
          <div id="mdFbLista" class="mp-fb" aria-live="polite" hidden></div>
        </section>
        <div class="lp-grid lp-g-md">
          <article class="lp-card" aria-labelledby="mdRecH">
            <div class="lp-card-h"><h2 id="mdRecH">Últimas ações</h2><button type="button" class="ld-btn sm" id="mdHist" aria-expanded="false" aria-controls="mdHistCard">Ver histórico completo</button></div>
            <div id="mdRecentes" aria-live="polite"></div>
          </article>
          <article class="lp-card" aria-labelledby="mdEvH">
            <div class="lp-card-h"><h2 id="mdEvH">Minha evolução</h2><span class="lp-sub">últimos 7 dias</span></div>
            <p class="mp-ev-txt" id="evTxt">Carregando…</p>
            <div id="evChart"></div>
          </article>
        </div>
        <article class="lp-card mp-hist" id="mdHistCard" aria-labelledby="mdHistH" hidden>
          <div class="lp-card-h"><h2 id="mdHistH">Histórico completo das ações</h2><button type="button" class="ld-btn sm" id="mdHistX">Fechar</button></div>
          <div id="mdHistBody"></div>
        </article>
      </section>

      <!-- ── Meus contratos (portal do closer) ── -->
      <section class="lp-pane" id="pane-contratos" data-pane="contratos" aria-labelledby="nav-contratos" hidden>
        <div class="lp-grid lp-g-ct">
          <article class="lp-card" aria-labelledby="ctH">
            <div class="lp-card-h"><h2 id="ctH">Meus contratos</h2>
              <div class="ld-seg" id="ctSeg" role="tablist" aria-label="Período dos contratos">
                <button type="button" role="tab" data-per="semana" aria-selected="true" class="active">Semana</button>
                <button type="button" role="tab" data-per="mes" aria-selected="false">Mês</button>
              </div>
            </div>
            <div class="mp-ct-nav" id="ctNav">
              <div class="mp-ct-mes">
                <button type="button" id="ctPrev" aria-label="Mês anterior">‹</button>
                <b id="ctMesLbl" aria-live="polite"></b>
                <button type="button" id="ctNext" aria-label="Próximo mês">›</button>
              </div>
              <div class="mp-ct-sems" id="ctSems" role="group" aria-label="Escolha a semana" hidden></div>
            </div>
            <p class="mp-ct-cap" id="ctCap"></p>
            <div class="mp-ct-kpis" id="ctKpis" aria-live="polite"></div>
            <div class="mp-ct-chart" id="ctChart"><div class="ld-skel" style="height:190px"></div></div>
          </article>
          <article class="lp-card" aria-labelledby="ctBH">
            <div class="lp-card-h"><h2 id="ctBH">Benefícios fechados</h2><span class="lp-sub" id="ctBenN"></span></div>
            <div id="ctBen"></div>
          </article>
        </div>
      </section>

      <!-- ── Configurações ── -->
      <section class="lp-pane" id="pane-config" data-pane="config" aria-labelledby="nav-config" hidden>
        <div id="cfView" aria-live="polite"></div>
      </section>

    </main>
  </div>
</div>

<script>
const BOOT = <?= $bootJson ?>;
</script>
<script defer src="<?= $v->e($v->asset('js/lider/base.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/registros.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/app.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/quick.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/dashboard.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/equipe.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/agenda.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/acoes.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/meu-portal.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/config.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/busca.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/iniciar.js')) ?>"></script>
</body>
</html>
