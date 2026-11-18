<?php
/**
 * Admin, aba Produtividade, sub-aba "Atividades dos líderes": o que cada líder registrou no período (reuniões, feedbacks, cobranças,
 * revisões e treinamentos) e quando foi a última vez, com alerta para quem está parado. Só leitura. Clicar numa linha abre os últimos
 * registros do líder num painel lateral. O período e a equipe seguem o filtro da aba Produtividade. Dados: api.php?action=get_atividades_lideres
 * e get_atividade_lider_detalhe. (Estilo e script vêm da view produtividade-subabas.)
 * @var \App\Core\View $v
 */
?>
<section class="ld ld-sec" id="prAtiv" aria-labelledby="prAtivH">
  <div class="ld-sec-h"><h2 id="prAtivH">Atividades dos líderes</h2><span id="prAtivSub"></span></div>
  <p>Conta o que o líder <b>criou</b> na equipe dele no período (editar um registro não conta como atividade nova). Clique em uma linha para ver os últimos registros do líder.</p>
  <div id="prAtivLista" aria-live="polite"><span class="ld-skel k"></span></div>
</section>
