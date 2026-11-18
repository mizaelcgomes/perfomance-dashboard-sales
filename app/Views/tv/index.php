<?php
/**
 * TV (index.php do site): a página do painel ao vivo. Casca HTML; o CSS e o JavaScript ficam em public/assets (css/tv.css e js/tv.js),
 * carregados com ?v=<data do arquivo> para o navegador da TV pegar a versão nova assim que ela for enviada.
 * Dados: $config = ['celebrationTracks' => [...], 'bgTracks' => [...]] (as músicas que existem no servidor, lidas pelo núcleo).
 * @var \App\Core\View $v
 * @var array<string, mixed> $config
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<?= $v->parcial('partials/api') ?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TOP CLOSERS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/footer.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/tv.css')) ?>">
</head>
<body>
<div class="dashboard" id="dashboard">
  <div class="tv-stage" id="tvStage">
    <div class="live-indicator" id="liveIndicator"><div class="live-dot"></div><span>AO VIVO</span></div>
    <header class="tv-head">
      <div class="header-subtitle">ELITE EM JOGO — QUEM SE TORNA LENDÁRIO?</div>
      <h1>TOP CLOSERS</h1>
      <div class="tv-sub">
        <div class="podium-mode-badge" id="podiumModeBadge"><div class="podium-mode-label" id="podiumModeLabel">RANKING SEMANAL</div></div>
        <div id="mesCounter" style="display:none"></div>
      </div>
    </header>
    <main class="tv-board" id="tvBoard">
      <section class="tv-left">
        <section class="podium-stage" id="podiumStage"></section>
      <footer class="rank-stats-mini">
        <div class="rsm-item highlight">
          <div class="rsm-label">Contratos Semana</div>
          <div class="rsm-val" id="statSemana">–</div>
          <div class="rsm-sub" id="statSemanaObs">carregando...</div>
        </div>
        <div class="rsm-sep"></div>
        <div class="rsm-item">
          <div class="rsm-label">Meta Semanal</div>
          <div class="rsm-val" id="statMetaSem">–</div>
          <div class="rsm-sub" id="statMetaObs">–</div>
        </div>
        <div class="rsm-sep" id="statHojeSep" style="display:none"></div>
        <div class="rsm-item" id="statHojeTile" style="display:none">
          <div class="rsm-label">Meta Hoje</div>
          <div class="rsm-val" id="statHoje">–</div>
          <div class="rsm-sub" id="statHojeObs">–</div>
        </div>
        <div class="rsm-sep" id="statCacSep" style="display:none"></div>
        <div class="rsm-item" id="statCacTile" style="display:none">
          <div class="rsm-label">CAC Mês</div>
          <div class="rsm-val" id="statCac">–</div>
          <div class="rsm-sub" id="statCacObs">–</div>
        </div>
      </footer>
      </section>
      <section class="tv-list" id="tvList">
        <div class="ranking-header"><div class="ranking-title" id="rankingTitle">Classificação Geral</div><div class="ranking-count" id="rankingCount">0 closers</div></div>
        <div class="ranking-list" id="rankingList"></div>
      </section>
    </main>
    <div class="tv-credit">TOP CLOSERS ◆ Desenvolvido por Mizael Gomes ◆ © <?= date('Y') ?></div>
  </div>
</div>
<!-- anúncio: tela cheia (os anúncios são páginas em 16:9 que já se ajustam à janela) -->
<button class="tv-nav prev" id="tvPrev" type="button" aria-label="Item anterior da rotação" title="Anterior (←)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg></button>
<button class="tv-nav next" id="tvNext" type="button" aria-label="Próximo item da rotação" title="Próximo (→)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg></button>
<div id="propaPanel" class="propa-panel"></div>

<!-- Hall da Fama: retrospectiva em tela cheia (item da rotação) -->
<div class="hall" id="hall" aria-hidden="true"><div class="hall-bg"></div><div class="hall-stage" id="hallStage"></div></div>

<div class="celebration-overlay" id="celebrationOverlay">
  <canvas id="celebrationCanvas" style="position:absolute;inset:0;pointer-events:none;"></canvas>
  <div class="celebration-popup" id="celebrationPopup">
    <div class="celebration-icon-wrap"><div class="celebration-icon" id="celebrationIcon">🏆</div></div>
    <div class="celebration-title" id="celebrationTitle">NOVO CONTRATO!</div>
    <div class="celebration-photo-wrapper"><div class="celebration-photo-ring"><div class="celebration-photo" id="celebrationPhoto"><div class="initials" id="celebrationInitials">?</div></div></div></div>
    <div class="celebration-seller" id="celebrationSeller">Nome</div>
    <div class="celebration-message" id="celebrationMessage">fechou um novo contrato!</div>
  </div>
</div>
<audio id="celebrationAudio" preload="none"></audio>
<audio id="bgAudio" preload="none"></audio>

<div class="milestone-overlay" id="milestoneOverlay" onclick="closeMilestone()">
  <canvas id="milestoneCanvas" style="position:absolute;inset:0;pointer-events:none;"></canvas>
  <div class="milestone-popup" onclick="event.stopPropagation()">
    <span class="ms-close" onclick="closeMilestone()">✕</span>
    <span class="ms-burst">🏆</span>
    <span class="ms-number" id="msNumber">100</span>
    <span class="ms-contracts-lbl">CONTRATOS</span>
    <span class="ms-headline" id="msHeadline">MARCO HISTÓRICO!</span>
    <span class="ms-sub" id="msSub">O time acaba de alcançar um novo marco neste mês!</span>
    <div class="ms-bar"><div class="ms-bar-fill" id="msBarFill"></div></div>
  </div>
</div>
<!-- Goal popup (closer weekly/monthly, team weekly) -->
<div class="goal-overlay" id="goalOverlay" data-popup-type="sem" onclick="closeGoalPopup()">
  <canvas id="goalCanvas" style="position:absolute;inset:0;pointer-events:none;"></canvas>
  <div class="goal-popup" onclick="event.stopPropagation()">
    <span class="goal-close" onclick="closeGoalPopup()">✕</span>
    <span class="goal-burst" id="goalBurst">🎯</span>
    <span class="goal-type-label" id="goalTypeLabel">META SEMANAL</span>
    <div class="goal-photo-wrap" id="goalPhotoWrap">
      <div class="goal-photo" id="goalPhoto"><div class="initials" id="goalInitials">?</div></div>
    </div>
    <span class="goal-name" id="goalName"></span>
    <span class="goal-headline" id="goalHeadline">META BATIDA!</span>
    <span class="goal-sub" id="goalSub"></span>
    <div class="goal-bar"><div class="goal-bar-fill" id="goalBarFill"></div></div>
  </div>
</div>
<!-- Custom popup (admin-triggered, two photos) -->
<div class="custom-popup-overlay" id="customPopupOverlay" onclick="closeCustomPopup()">
  <div class="custom-popup" onclick="event.stopPropagation()">
    <span class="custom-popup-close" onclick="closeCustomPopup()">✕</span>
    <div class="custom-popup-title" id="customPopupTitle"></div>
    <div class="custom-popup-photos">
      <div class="custom-popup-photo" id="customPopupPhoto1" style="display:none"></div>
      <div class="custom-popup-photo" id="customPopupPhoto2" style="display:none"></div>
    </div>
    <div class="custom-popup-bar"><div class="custom-popup-bar-fill" id="customPopupBarFill"></div></div>
  </div>
</div>
<!-- Goal ceremony (admin-triggered, previous week's podium honorees, one at a time) -->
<div class="ceremony-overlay" id="ceremonyOverlay" onclick="closeCeremony()">
  <canvas id="ceremonyCanvas" style="position:absolute;inset:0;pointer-events:none;"></canvas>
  <div class="ceremony-kicker" id="ceremonyKicker">SOLENIDADE DE ENTREGA DE METAS</div>
  <div class="ceremony-progress" id="ceremonyProgress"></div>
  <div class="ceremony-popup" id="ceremonyPopup" data-rank="1" onclick="event.stopPropagation()">
    <span class="ceremony-close" onclick="closeCeremony()">✕</span>
    <div class="ceremony-mystery" id="ceremonyMystery">
      <span class="ceremony-mystery-icon">❓</span>
      <span class="ceremony-mystery-text">REVELANDO<span class="ceremony-mystery-dots"><span>.</span><span>.</span><span>.</span></span></span>
    </div>
    <div class="ceremony-reveal" id="ceremonyReveal">
      <span class="ceremony-medal" id="ceremonyMedal">🥇</span>
      <span class="ceremony-rank-label" id="ceremonyRankLabel">1º LUGAR</span>
      <div class="ceremony-photo-wrap"><div class="ceremony-photo" id="ceremonyPhoto"><div class="initials" id="ceremonyInitials">?</div></div></div>
      <span class="ceremony-name" id="ceremonyName"></span>
      <span class="ceremony-team" id="ceremonyTeam"></span>
      <span class="ceremony-stats" id="ceremonyStats"></span>
      <span class="ceremony-sub" id="ceremonySub">Meta semanal batida!</span>
    </div>
  </div>
  <div class="ceremony-final" id="ceremonyFinal" onclick="event.stopPropagation()">
    <div class="ceremony-final-title">TODOS QUE BATERAM A META!</div>
    <div class="ceremony-final-grid" id="ceremonyFinalGrid"></div>
  </div>
</div>
<audio id="ceremonyAudio" preload="none" loop></audio>
<div id="audioEnableBtn" style="display:none;position:fixed;bottom:18px;right:18px;z-index:9000;cursor:pointer;background:rgba(212,175,55,0.12);border:1px solid rgba(212,175,55,0.35);border-radius:50px;padding:7px 16px;font-size:13px;color:#d4af37;backdrop-filter:blur(6px);" onclick="enableBgMusic()">🔊 Ativar Música</div>

<script>window.TV_CFG=<?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= $v->e($v->asset('js/tv.js')) ?>"></script>
<?= $v->parcial('partials/rodape-site') ?>
</body>
</html>
