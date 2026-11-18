<?php
/**
 * Portal do closer (closer.php): o cartão "Meus feedbacks" — o que o líder registrou para o closer logado (feedbacks e cobranças de
 * cadastramento), só leitura. Fica escondido (hidden) até o JavaScript (assets/js/lider/meus-feedbacks.js) confirmar com o servidor
 * (closer-api.php?action=meus_feedbacks) que há algo recebido; o closer é sempre o da sessão.
 * @var \App\Core\View $v
 */
?>
<section class="ld ld-mf ld-portal" id="mfSec" hidden aria-labelledby="mfH">
  <link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/tokens.css')) ?>">
  <link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/componentes.css')) ?>">
  <link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/portal.css')) ?>">
  <div class="pt-card">
    <div class="ld-head">
      <h2 id="mfH">Meus feedbacks <span class="ld-bd" id="mfBd" hidden></span></h2>
      <button type="button" class="ld-toggle" id="mfToggle" aria-expanded="false" aria-controls="mfBody">Abrir</button>
    </div>
    <div class="ld-body" id="mfBody" hidden>
      <div class="ld-mf-l" id="mfLista" aria-live="polite"></div>
    </div>
  </div>
  <script defer src="<?= $v->e($v->asset('js/lider/base.js')) ?>"></script>
  <script defer src="<?= $v->e($v->asset('js/lider/meus-feedbacks.js')) ?>"></script>
</section>
