<?php
/**
 * Admin, aba Produtividade, sub-aba "Líderes": um cartão por equipe, com o líder, o funil do período e as pontuações do Painel.
 * O período e a equipe seguem os filtros da própria aba Produtividade: o admin.php chama prLidCarregar() (assets/js/lider/admin.js)
 * toda vez que a aba recarrega, e só carrega esta sub-aba quando ela está aberta. Dados: api.php?action=get_produtividade_lideres.
 * (Os arquivos de estilo e script vêm da view produtividade-subabas.)
 * @var \App\Core\View $v
 */
?>
<section class="ld ld-sec" id="prLid" aria-labelledby="prLidH">
  <div class="ld-sec-h"><h2 id="prLidH">Líderes</h2><span id="prLidSub"></span></div>
  <p>Cada líder é o closer escolhido pela equipe. Os números seguem o período e a equipe do filtro acima.</p>
  <div class="ld-teams" id="prLidList" aria-live="polite"><div style="grid-column:1/-1"><span class="ld-skel k"></span></div></div>
</section>
