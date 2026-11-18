<?php
/**
 * Admin, aba Produtividade: a barra de sub-abas (Closers | Líderes | Atividades dos líderes) e os arquivos de estilo e script do módulo
 * do líder para o admin (assets/css/lider/*.css e assets/js/lider/base.js e admin.js). O admin.php coloca esta barra no topo da aba e
 * embrulha o conteúdo de sempre no painel "Closers"; os painéis "Líderes" e "Atividades dos líderes" vêm das views irmãs.
 * @var \App\Core\View $v
 */
?>
<div class="ld">
  <link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/tokens.css')) ?>">
  <link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/componentes.css')) ?>">
  <link rel="stylesheet" href="<?= $v->e($v->asset('css/lider/admin.css')) ?>">
  <div class="ld-subtabs" id="prSubTabs" role="tablist" aria-label="Visões da Produtividade">
    <button type="button" role="tab" data-sub="closers" aria-selected="true" aria-controls="prPanelClosers">Closers</button>
    <button type="button" role="tab" data-sub="lideres" aria-selected="false" aria-controls="prPanelLideres">Líderes</button>
    <button type="button" role="tab" data-sub="ativ" aria-selected="false" aria-controls="prPanelAtiv">Atividades dos líderes</button>
  </div>
  <script defer src="<?= $v->e($v->asset('js/lider/base.js')) ?>"></script>
  <script defer src="<?= $v->e($v->asset('js/lider/admin.js')) ?>"></script>
</div>
