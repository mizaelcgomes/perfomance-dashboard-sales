<?php
/**
 * Admin (admin.php): a página inteira. Fase 7: o HTML de cada aba está em app/Views/admin/aba-*.php, o CSS em assets/css/admin.css (mais o rail.css) e o
 * JavaScript em assets/js/admin/*.js, na mesma ordem em que ficava no admin.php. Os valores que o PHP injetava no JavaScript chegam em window.ADMIN_BOOT.
 * @var \App\Core\View $v
 * @var string $csrf
 * @var string $adminUser
 * @var string $adminInitial
 * @var array<string, mixed> $boot
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<?= $v->parcial('partials/api') ?>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin – TOP CLOSERS</title>
<script>
// CSRF: todo POST para a API (window.API_URL) leva o token da sessão do admin (sem ele o servidor responde 419 e não grava nada).
// Um único ponto cobre os ~35 fetch de escrita da página, inclusive os de FormData (upload de foto, música).
(function(){
  var token = <?= json_encode($csrf, JSON_HEX_TAG) ?>, orig = window.fetch.bind(window);
  window.CSRF_TOKEN = token;
  window.fetch = function(input, init){
    try {
      var url = typeof input === 'string' ? input : ((input && input.url) || '');
      var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
      if (method === 'POST' && url.split('?')[0] === window.API_URL) {
        init = Object.assign({}, init);
        var h = new Headers(init.headers || (input && typeof input !== 'string' && input.headers) || {});
        if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', token);
        init.headers = h;
      }
    } catch (e) {}
    return orig(input, init);
  };
})();
</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/footer.css')) ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="<?= $v->asset('css/admin.css') ?>">
<link rel="stylesheet" href="<?= $v->asset('css/rail.css') ?>">
</head>
<body data-tab="painel">

<div class="app-shell" id="appShell">

<div class="sb-overlay" id="sbOverlay" onclick="sbCloseDrawer()"></div>

<aside class="sidebar" id="sidebar" aria-label="Navegação principal">
  <div class="sb-top">
    <button type="button" class="sb-logo" onclick="showTab('painel')" aria-label="TOP CLOSERS — ir ao Painel">
      <span class="sb-name">Top<br>Closers</span>
    </button>
    <button type="button" class="sb-x" onclick="sbCloseDrawer()" aria-label="Fechar menu"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
  </div>

  <nav class="sb-nav">
    <div class="sb-group">Operação</div>
    <button class="tab-btn active" data-tab="painel" aria-current="page" onclick="showTab('painel',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg><span class="sb-lbl">Dashboard</span></button>
    <button class="tab-btn" data-tab="rankings" onclick="showTab('rankings',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 21V11H4v10"/><path d="M14 21V3h-4v18"/><path d="M20 21v-7h-4v7"/></svg><span class="sb-lbl">Rankings</span></button>
    <button class="tab-btn" data-tab="contratos" onclick="showTab('contratos',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg><span class="sb-lbl">Contratos</span></button>

    <div class="sb-group">Gestão</div>
    <button class="tab-btn" data-tab="equipes" onclick="showTab('equipes',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><span class="sb-lbl">Equipes</span></button>
    <button class="tab-btn" data-tab="produtividade" onclick="showTab('produtividade',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg><span class="sb-lbl">Produtividade</span></button>
    <button class="tab-btn" data-tab="relatorios" onclick="showTab('relatorios',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="17" x2="8" y2="13"/><line x1="12" y1="17" x2="12" y2="11"/><line x1="16" y1="17" x2="16" y2="14"/></svg><span class="sb-lbl">Relatórios</span></button>
    <button class="tab-btn" data-tab="metas" onclick="showTab('metas',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg><span class="sb-lbl">Metas</span></button>

    <div class="sb-group">Gamificação</div>
    <button class="tab-btn" data-tab="calendario" onclick="showTab('calendario',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><span class="sb-lbl">Calendário</span></button>
    <button class="tab-btn" data-tab="propaganda" onclick="showTab('propaganda',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg><span class="sb-lbl">Propaganda</span></button>
    <button class="tab-btn" data-tab="vivo" onclick="showTab('vivo',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><polyline points="17 2 12 7 7 2"/></svg><span class="sb-lbl">Pódio</span></button>
  </nav>

  <div class="sb-foot">
    <a href="/" target="_blank" rel="noopener" class="sb-btn live" title="Abrir a TV em outra aba"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><polyline points="17 2 12 7 7 2"/></svg><span class="sb-lbl">Pódio</span></a>
    <button type="button" class="sb-btn sb-collapse" id="sbCollapseBtn" onclick="sbToggle()" aria-expanded="true" aria-controls="sidebar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg><span class="sb-lbl">Recolher menu</span></button>
  </div>
</aside>

<div class="main-area" id="mainArea">
  <header class="topbar">
    <div class="tb-left">
      <button type="button" class="tb-burger" id="sbBurger" onclick="sbOpenDrawer()" aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    </div>
    <div class="tb-title" id="tbTitle">Painel</div>
    <div class="tb-center">
      <button type="button" class="pd-btn" id="pdBtn" onclick="pdToggle(this)" aria-haspopup="dialog" aria-expanded="false" aria-label="Selecionar período">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span class="pd-m" data-pd="month">–</span><i class="sep"></i><span class="wk" data-pd="week">–</span>
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
    </div>
    <div class="tb-right">
      <div class="cac-w" data-cac title="CAC do mês"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M14.5 9a2.5 2 0 0 0-2.5-1.5c-1.5 0-2.5.8-2.5 1.8 0 2.5 5 1.2 5 3.7 0 1-1 1.8-2.5 1.8A2.7 2 0 0 1 9.5 14.5"/><path d="M12 6.5V8m0 8v1.5"/></svg>CAC: <b data-cac-val>–</b><i class="cac-dot" data-cac-dot></i></div>
      <button type="button" class="tb-ico tb-search" onclick="cpOpen()" aria-label="Buscar (Ctrl+K)"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg><span class="t">Buscar</span><kbd>Ctrl K</kbd></button>
      <div class="tb-account">
        <button type="button" class="tb-ico tb-bell" id="bellBtn" onclick="bellToggle(this)" aria-haspopup="dialog" aria-expanded="false" aria-label="Notificações"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg><span class="bell-n" id="bellN" hidden>0</span></button>
        <i class="tb-sep" aria-hidden="true"></i>
        <div class="tb-prof-w">
          <button type="button" class="tb-prof" id="profBtn" onclick="profToggle(this)" aria-haspopup="menu" aria-expanded="false" aria-controls="profMenu"><span class="tb-prof-n"><?=htmlspecialchars($adminUser)?></span><svg class="tb-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg><span class="tb-av" aria-hidden="true"><?=htmlspecialchars($adminInitial)?></span></button>
          <div class="tb-menu" id="profMenu" role="menu" aria-label="Conta" hidden>
            <button type="button" role="menuitem" onclick="profPerfil()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Meu perfil</button>
            <button type="button" role="menuitem" onclick="profClose();showTab('metas')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>Configurações</button>
            <a role="menuitem" class="tb-sair" href="/logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sair</a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="content">

  <!-- CAC + período no mobile: card logo abaixo da topbar -->
  <div class="mob-info" id="mobInfo">
    <button type="button" class="pd-btn" id="pdBtnM" onclick="pdToggle(this)" aria-haspopup="dialog" aria-expanded="false" aria-label="Selecionar período">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span class="wk" data-pd="short">–</span>
      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    <div class="cac-w" data-cac title="CAC do mês"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M14.5 9a2.5 2 0 0 0-2.5-1.5c-1.5 0-2.5.8-2.5 1.8 0 2.5 5 1.2 5 3.7 0 1-1 1.8-2.5 1.8A2.7 2 0 0 1 9.5 14.5"/><path d="M12 6.5V8m0 8v1.5"/></svg>CAC <b data-cac-val>–</b><i class="cac-dot" data-cac-dot></i></div>
  </div>

  <!-- PAGE HEADER (telas internas; o Painel tem cabeçalho próprio) -->
  <div class="page-header">
    <h1 class="page-title" id="pageHeading">Painel</h1>
    <p class="page-subtitle" id="pageSubtitle"></p>
  </div>

<?= $v->parcial('admin/aba-painel') ?>
<?= $v->parcial('admin/aba-produtividade') ?>
<?= $v->parcial('admin/aba-equipes') ?>
<?= $v->parcial('admin/aba-metas') ?>
<?= $v->parcial('admin/aba-contratos') ?>
<?= $v->parcial('admin/aba-rankings') ?>
  <?php echo $v->parcial('admin/relatorios'); /* HTML da aba: app/Views/admin/relatorios.php */ ?>

<?= $v->parcial('admin/aba-calendario') ?>
<?= $v->parcial('admin/aba-propaganda') ?>
<?= $v->parcial('admin/aba-vivo') ?>
  </div><!-- /content -->
</div><!-- /main-area -->
</div><!-- /app-shell -->
<!-- Popover do seletor de período global -->
<div class="pop pd-pop" id="pdPop" role="dialog" aria-label="Selecionar período" hidden>
  <div class="pd-nav">
    <button type="button" class="pd-arrow" onclick="pdMonth(-1)" aria-label="Mês anterior">‹</button>
    <b id="pdPopMonth">–</b>
    <button type="button" class="pd-arrow" onclick="pdMonth(1)" aria-label="Próximo mês">›</button>
  </div>
  <div class="pd-weeks" id="pdWeeks"></div>
  <div class="pd-foot">
    <button type="button" class="btn btn-s btn-sm" onclick="pdReset()">Semana atual</button>
    <button type="button" class="btn btn-sm" style="background:transparent;color:var(--muted);box-shadow:none" onclick="pdClose()">Fechar</button>
  </div>
</div>

<!-- Popover de notificações -->
<div class="pop bell-pop" id="bellPop" role="dialog" aria-label="Notificações" hidden>
  <div class="bell-h"><span>Alertas da semana</span></div>
  <div class="bell-list" id="bellList"></div>
</div>

<!-- Busca global (Ctrl+K) -->
<dialog class="cp-dlg" id="cpDlg" aria-label="Busca global">
  <div class="cp-in">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
    <input id="cpInput" type="text" placeholder="Buscar telas, closers, equipes ou ações…" autocomplete="off" spellcheck="false" aria-label="Buscar" role="combobox" aria-expanded="true" aria-controls="cpList">
    <kbd>Esc</kbd>
  </div>
  <div class="cp-list" id="cpList" role="listbox"></div>
  <div class="cp-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> navegar</span><span><kbd>Enter</kbd> abrir</span><span><kbd>Esc</kbd> fechar</span></div>
</dialog>

<!-- Venda rápida (botão ⚡ no celular · Ctrl+K · aba Contratos) -->
<dialog class="cv-dlg" id="cvDlg" aria-labelledby="cvDlgT">
  <form id="cvForm" autocomplete="off" novalidate onsubmit="return cvSubmit(event,false)">
    <div class="cv-dh">
      <h3 id="cvDlgT"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg><span id="cvDlgTitle">Contrato rápida</span></h3>
      <button type="button" class="cv-x" onclick="cvClose()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="width:20px;height:20px"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    </div>

    <div class="cv-db">
      <div class="cv-f">
        <label class="cv-l" for="cvDate">Data</label>
        <div class="cv-daterow">
          <input class="cv-in" type="date" id="cvDate" required>
          <button type="button" class="cv-chip" data-d="0" aria-pressed="false">Hoje</button>
          <button type="button" class="cv-chip" data-d="-1" aria-pressed="false">Ontem</button>
        </div>
      </div>

      <div class="cv-f">
        <span class="cv-l" id="cvCloserL">Closer</span>
        <div class="cv-cb" id="cvCb">
          <button type="button" class="cv-cb-btn" id="cvCbBtn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="cvCloserL cvCbTxt">
            <span class="cv-av" id="cvCbAv" aria-hidden="true">?</span>
            <span class="cv-cb-txt ph" id="cvCbTxt">Selecione o closer</span>
            <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div class="cv-cb-pop" id="cvCbPop" hidden>
            <input class="cv-in" type="search" id="cvCbQ" placeholder="Buscar closer ou equipe…" aria-label="Buscar closer" autocomplete="off">
            <ul class="cv-cb-list" id="cvCbList" role="listbox" aria-label="Closers"></ul>
          </div>
        </div>
      </div>

      <div class="cv-f">
        <span class="cv-l" id="cvBenL">Benefício</span>
        <div class="cv-bens" id="cvBens" role="radiogroup" aria-labelledby="cvBenL"></div>
        <input class="cv-in" id="cvDesc" maxlength="100" placeholder="Qual benefício? (descrição de “Outros”)" aria-label="Descrição do benefício" hidden>
      </div>

      <div class="cv-f">
        <label class="cv-l" for="cvQty">Quantidade</label>
        <div class="cv-qty">
          <button type="button" id="cvMinus" aria-label="Diminuir"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg></button>
          <input class="cv-in" id="cvQty" type="number" inputmode="numeric" min="1" max="99" value="1">
          <button type="button" id="cvPlus" aria-label="Aumentar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></button>
        </div>
      </div>
      <div class="cv-err" id="cvErr" role="alert"></div>
    </div>

    <div class="cv-df">
      <div class="cv-recap" id="cvRecap" aria-live="polite"></div>
      <div class="cv-btns">
        <button type="button" class="btn btn-s cv-b" onclick="cvClose()">Cancelar</button>
        <button type="button" class="btn btn-s cv-b" id="cvAgain" onclick="cvSubmit(null,true)">Registrar e adicionar outra</button>
        <button type="submit" class="btn btn-p cv-b" id="cvOk">Registrar venda</button>
      </div>
    </div>
  </form>
</dialog>

<div class="cv-menu" id="cvMenu" role="menu" aria-label="Ações da venda" hidden>
  <button type="button" role="menuitem" data-act="edit"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Editar</button>
  <button type="button" role="menuitem" data-act="del" class="d"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>Excluir</button>
</div>

<button type="button" class="fab" id="fabBtn" onclick="cvOpen()" aria-label="Registrar contrato rápido"><svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></button>

<div class="toast" id="toast"></div>

<script>window.ADMIN_BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="<?= $v->asset('js/admin/base.js') ?>"></script>
<script src="<?= $v->asset('js/admin/time.js') ?>"></script>
<script src="<?= $v->asset('js/admin/contratos.js') ?>"></script>
<script src="<?= $v->asset('js/admin/rankings.js') ?>"></script>
<script src="<?= $v->asset('js/admin/calendario-propaganda.js') ?>"></script>
<script src="<?= $v->asset('js/admin/metas.js') ?>"></script>
<script src="<?= $v->asset('js/admin/relatorios.js') ?>"></script>
<script src="<?= $v->asset('js/admin/shell.js') ?>"></script>
<script src="<?= $v->asset('js/admin/produtividade.js') ?>"></script>
<script src="<?= $v->asset('js/admin/painel.js') ?>"></script>
<script src="<?= $v->asset('js/admin/iniciar.js') ?>"></script>
</body>
</html>
