<?php
/**
 * Portal do Closer (closer.php): a página inteira, no mesmo estilo do admin e do portal do líder (barra lateral com a marca, barra superior com migalhas,
 * busca e sino, cabeçalho de página e cartões iguais). A barra lateral recolhe para 64px (desktop) e vira um menu deslizante (celular). Seis páginas:
 * Meu dia (meta da semana/mês, meu ranking, registro de ação rápida, últimas interações, evolução e últimas ações), Minhas ações (histórico completo),
 * Meus contratos, Ranking, Minhas interações e Configurações. O botão flutuante (FAB) leva ao Registro de Ação Rápida do Meu dia (no celular, abre como
 * uma folha). O JavaScript (assets/js/lider/*.js, que o portal do líder também usa, e assets/js/closer/*.js) preenche tudo a partir do closer-api.php.
 * @var \App\Core\View $v
 * @var array<string, mixed> $boot
 * @var string $bootJson
 */
$ico = static function (string $caminhos): string {
    return '<svg class="lp-ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $caminhos . '</svg>';
};
$icones = [
    'meu-dia' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>',
    'acoes' => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>',
    'contratos' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15h6"/>',
    'ranking' => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2z"/>',
    'interacoes' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'config' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
    'buscar' => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
    'sino' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'menu' => '<path d="M3 12h18M3 6h18M3 18h18"/>',
    'sair' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
    'recolher' => '<path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/>',
    'fechar' => '<path d="M6 6l12 12M18 6L6 18"/>',
    'perfil' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'calendario' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'mais' => '<path d="M12 5v14M5 12h14"/>',
];
/** O menu: [chave da página, rótulo, submenu [[valor, rótulo]…]]. As Configurações ficam no rodapé da barra lateral. */
$menu = [
    ['meu-dia', 'Meu dia', []],
    ['acoes', 'Minhas ações', []],
    ['contratos', 'Meus contratos', [['hoje', 'Hoje'], ['semana', 'Semana'], ['mes', 'Mês']]],
    ['ranking', 'Ranking', [['semana', 'Semana'], ['mes', 'Mês']]],
    ['interacoes', 'Minhas interações', [['feedback', 'Feedbacks'], ['reuniao', 'Reuniões'], ['cobranca', 'Cobranças'], ['revisao', 'Revisões'], ['treinamento', 'Treinamentos']]],
];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
<meta charset="UTF-8">
<?= $v->parcial('partials/api') ?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf" content="<?= $v->e($boot['csrf']) ?>">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#050505">
<title>Portal do Closer – TOP CLOSERS</title>
<script>try { var t = localStorage.getItem('cpTema'); if (t === 'claro') { document.documentElement.dataset.tema = 'claro'; } } catch (e) {}</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/tokens.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/componentes.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/portal-lider.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/shell-admin.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/meu-portal.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/rail.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/closer/closer.css')) ?>">
</head>
<body class="lp cp">
<a class="lp-skip" href="#lpMain">Pular para o conteúdo</a>

<div class="lp-shell ld" id="lpShell">

  <!-- ═══ BARRA LATERAL (256px; recolhe para 64px; no celular vira menu deslizante) ═══ -->
  <aside class="lp-side" id="lpSide" aria-label="Menu do closer">
    <div class="sb-top">
      <button type="button" class="sb-logo" id="lpLogo" aria-label="TOP CLOSERS: ir ao Meu dia"><span class="sb-name">Top<br>Lendários</span></button>
      <button type="button" class="sb-x" id="lpMenuX" aria-label="Fechar o menu"><?= $ico($icones['fechar']) ?></button>
    </div>

    <nav class="lp-nav sb-nav" id="lpNav" aria-label="Páginas do portal">
<?php foreach ($menu as [$chave, $rotulo, $subs]): ?>
      <div class="sb-item<?= $subs ? ' tem-sub' : '' ?>" data-tab="<?= $chave ?>">
        <button type="button" class="lp-nav-b" id="nav-<?= $chave ?>" data-tab="<?= $chave ?>"<?= $chave === 'meu-dia' ? ' aria-current="page"' : '' ?>><?= $ico($icones[$chave]) ?><span class="sb-lbl"><?= $v->e($rotulo) ?></span><b class="lp-nav-n" data-badge="<?= $chave ?>" hidden></b><?= $subs ? '<svg class="sb-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>' : '' ?></button>
<?php if ($subs): ?>
        <div class="sb-sub" role="group" aria-label="<?= $v->e($rotulo) ?>">
<?php foreach ($subs as [$valor, $nomeSub]): ?>
          <button type="button" class="sb-sub-b" data-sub="<?= $chave ?>:<?= $valor ?>" aria-current="false"><?= $v->e($nomeSub) ?></button>
<?php endforeach; ?>
        </div>
<?php endif; ?>
      </div>
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
      <div class="tb-title" id="lpTbTitle">Meu dia</div>
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
        <h1 class="page-title" id="lpTitulo">Meu dia</h1>
        <p class="page-subtitle" id="lpSubtitulo"></p>
      </div>

      <!-- ── Meu dia ── -->
      <section class="lp-pane" id="pane-meu-dia" data-pane="meu-dia" aria-labelledby="nav-meu-dia">
        <!-- Meta da semana (ou do mês): a barra grossa, verde ≥ 80%, amarela 40 a 79%, vermelha < 40% -->
        <section class="lp-card cp-meta" id="mdMeta" aria-labelledby="mdMetaTit">
          <div class="lp-card-h"><h2 id="mdMetaTit">Meta da semana</h2>
            <div class="ld-seg" id="mdMetaSeg" role="tablist" aria-label="Período da meta">
              <button type="button" role="tab" data-per="semana" aria-selected="true" class="active">Semana</button>
              <button type="button" role="tab" data-per="mes" aria-selected="false">Mês</button>
            </div></div>
          <p class="cp-meta-per" id="mdMetaPer"></p>
          <div class="cp-meta-l"><b id="mdMetaNum">—</b></div>
          <div id="mdMetaBar" class="cp-meta-bar"><div class="cp-bar"><i style="width:0"></i></div></div>
          <p class="cp-meta-txt" id="mdMetaTxt" aria-live="polite">Carregando…</p>
        </section>

        <div>
          <!-- Registro de Ação Rápida: digite o número e clique no tipo — salva na hora (no celular, o botão flutuante abre como uma folha) -->
          <section class="lp-card lp-quick" id="lpQuick" aria-labelledby="qfH">
            <div class="lp-card-h"><h2 id="qfH">Registrar ação rápida</h2><button type="button" class="ld-btn sm cp-sheet-x" id="qfFechar" aria-label="Fechar o registro">Fechar</button></div>
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
        </div>

        <div class="mp-sum" id="mdResumo" aria-live="polite">
          <div class="mp-sum-c c-lig" data-k="ligacao"><div class="mp-sum-l"><?= $boot['ic']['phone'] ?>Ligações hoje</div><div class="mp-sum-n" id="nLig">0</div></div>
          <div class="mp-sum-c c-fu" data-k="followup"><div class="mp-sum-l"><?= $boot['ic']['refresh'] ?>Follow-ups hoje</div><div class="mp-sum-n" id="nFu">0</div></div>
          <div class="mp-sum-c c-lq" data-k="lead_quente"><div class="mp-sum-l"><?= $boot['ic']['flame'] ?>Leads Quentes hoje</div><div class="mp-sum-n" id="nLq">0</div></div>
        </div>

        <!-- o código do Meu dia (compartilhado com o portal do líder) procura estes três: ficam escondidos; no portal do closer os feedbacks estão em Minhas interações -->
        <div hidden><span id="mdFeedbacks"></span><span id="mdFbBd"></span><button type="button" id="mdFbToggle"></button><div id="mdFbLista"></div></div>

        <div class="lp-grid lp-g-md">
          <article class="lp-card" aria-labelledby="mdInterH">
            <div class="lp-card-h"><h2 id="mdInterH">Últimas interações</h2><button type="button" class="ld-btn sm" id="mdInterVer">Ver todas →</button></div>
            <div id="mdInter" aria-live="polite"><div class="ld-skel" style="height:70px"></div></div>
          </article>
          <article class="lp-card" aria-labelledby="mdEvH">
            <div class="lp-card-h"><h2 id="mdEvH">Minha evolução</h2><span class="lp-sub">últimos 7 dias</span></div>
            <p class="mp-ev-txt" id="evTxt">Carregando…</p>
            <div id="evChart"></div>
          </article>
        </div>

        <article class="lp-card" aria-labelledby="mdRecH">
          <div class="lp-card-h"><h2 id="mdRecH">Últimas ações</h2><button type="button" class="ld-btn sm" id="mdHist">Ver histórico completo →</button></div>
          <div id="mdRecentes" aria-live="polite"></div>
        </article>
        <!-- o histórico completo vive na página Minhas ações; estes dois ficam só porque o Meu dia compartilha o código do portal do líder -->
        <article class="lp-card mp-hist" id="mdHistCard" hidden><div id="mdHistBody"></div><button type="button" id="mdHistX" hidden></button></article>
      </section>

      <!-- ── Minhas ações (histórico completo) ── -->
      <section class="lp-pane" id="pane-acoes" data-pane="acoes" aria-labelledby="nav-acoes" hidden>
        <article class="lp-card">
          <div class="lp-card-h"><h2>Histórico completo</h2><span class="lp-sub">o registro novo fica no Meu dia</span></div>
          <div id="acHost"></div>
        </article>
      </section>

      <!-- ── Meus contratos ── -->
      <section class="lp-pane" id="pane-contratos" data-pane="contratos" aria-labelledby="nav-contratos" hidden>
        <div class="lp-grid lp-g-ct">
          <article class="lp-card" aria-labelledby="ctH">
            <div class="lp-card-h"><h2 id="ctH">Meus contratos</h2>
              <div class="ld-seg" id="ctSeg" role="tablist" aria-label="Período dos contratos">
                <button type="button" role="tab" data-per="hoje" aria-selected="false">Hoje</button>
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
            <div class="cp-ct-meta" id="ctMeta"></div>
            <div class="mp-ct-chart" id="ctChart"><div class="ld-skel" style="height:190px"></div></div>
          </article>
          <article class="lp-card" aria-labelledby="ctBH">
            <div class="lp-card-h"><h2 id="ctBH">Benefícios fechados</h2><span class="lp-sub" id="ctBenN"></span></div>
            <div id="ctBen"></div>
          </article>
        </div>
      </section>

      <!-- ── Ranking ── -->
      <section class="lp-pane" id="pane-ranking" data-pane="ranking" aria-labelledby="nav-ranking" hidden>
        <article class="lp-card" aria-labelledby="rkMinhaH">
          <div class="lp-card-h"><h2 id="rkMinhaH">Minha posição</h2>
            <div class="ld-seg" id="rkSeg" role="tablist" aria-label="Período do ranking">
              <button type="button" role="tab" data-per="semana" aria-selected="true" class="active">Semana</button>
              <button type="button" role="tab" data-per="mes" aria-selected="false">Mês</button>
            </div></div>
          <p class="cp-meta-per" id="rkRotulo"></p>
          <div id="rkMinha" aria-live="polite"></div>
        </article>
        <div class="lp-grid cp-g-rk">
          <article class="lp-card" aria-labelledby="rkEqH">
            <div class="lp-card-h"><h2 id="rkEqH">Minha equipe</h2></div>
            <div id="rkEquipe" aria-live="polite"></div>
          </article>
          <article class="lp-card" aria-labelledby="rkComH">
            <div class="lp-card-h"><h2 id="rkComH">Comercial <span class="lp-sub" id="rkComN"></span></h2><button type="button" class="ld-btn sm" id="rkComToggle" aria-expanded="true" aria-controls="rkCom">Recolher</button></div>
            <div id="rkCom" aria-live="polite"></div>
          </article>
        </div>
      </section>

      <!-- ── Minhas interações ── -->
      <section class="lp-pane" id="pane-interacoes" data-pane="interacoes" aria-labelledby="nav-interacoes" hidden>
        <article class="lp-card">
          <div class="ld-seg cp-abas" id="inAbas" role="tablist" aria-label="Tipo de interação">
            <button type="button" role="tab" data-tipo="feedback" aria-selected="true" class="active">Feedbacks <b class="cp-tab-n" hidden>0</b></button>
            <button type="button" role="tab" data-tipo="reuniao" aria-selected="false">Reuniões</button>
            <button type="button" role="tab" data-tipo="cobranca" aria-selected="false">Cobranças <b class="cp-tab-n" hidden>0</b></button>
            <button type="button" role="tab" data-tipo="revisao" aria-selected="false">Revisões <b class="cp-tab-n" hidden>0</b></button>
            <button type="button" role="tab" data-tipo="treinamento" aria-selected="false">Treinamentos</button>
          </div>
          <div id="inLista" aria-live="polite"></div>
        </article>
      </section>

      <!-- ── Configurações ── -->
      <section class="lp-pane" id="pane-config" data-pane="config" aria-labelledby="nav-config" hidden>
        <div id="cfView" aria-live="polite"></div>
      </section>

    </main>
  </div>

  <!-- botão flutuante: leva ao Registro de Ação Rápida do Meu dia (no celular, abre a folha) -->
  <button type="button" class="cp-fab" id="cpFab" aria-label="Registrar ação rápida"><?= $ico($icones['mais']) ?><span>Registrar ação</span></button>
</div>

<script>
const BOOT = <?= $bootJson ?>;
</script>
<script defer src="<?= $v->e($v->asset('js/lider/base.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/app.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/quick.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/acoes.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/meu-portal.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/closer/ranking.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/closer/interacoes.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/closer/config.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/closer/app.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/busca.js')) ?>"></script>
<script defer src="<?= $v->e($v->asset('js/lider/iniciar.js')) ?>"></script>
</body>
</html>
