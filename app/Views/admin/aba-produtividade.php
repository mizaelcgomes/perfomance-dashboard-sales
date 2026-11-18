<?php /** @var \App\Core\View $v  Aba produtividade do admin: o HTML que antes ficava no admin.php (fase 7). */ ?>
  <!-- ═══════════════ PRODUTIVIDADE (ações dos closers: Ligação → Follow-up → Lead quente → Contrato) ═══════════════ -->
  <div class="tab-pane" id="tabProdutividade">
    <div class="pr-wrap">
      <?php echo $v->parcial('admin/produtividade-subabas'); /* sub-abas Closers | Líderes | Atividades dos líderes (módulo do líder): app/Views/admin/produtividade-subabas.php */ ?>
      <div class="pr-filters">
        <div class="fg"><span class="lbl" id="prPerLbl">Período</span>
          <div class="pn-seg" id="prSeg" role="tablist" aria-labelledby="prPerLbl">
            <button type="button" role="tab" data-per="hoje" aria-selected="true" class="active">Hoje</button>
            <button type="button" role="tab" data-per="semana" aria-selected="false">Semana</button>
            <button type="button" role="tab" data-per="mes" aria-selected="false">Mês</button>
            <button type="button" role="tab" data-per="custom" aria-selected="false">Personalizado</button>
          </div></div>
        <div class="fg" id="prCustom" hidden><span class="lbl">Intervalo</span>
          <div class="pr-range"><input type="date" class="inp" id="prFrom" aria-label="Data inicial"><span>até</span><input type="date" class="inp" id="prTo" aria-label="Data final"></div></div>
        <div class="fg"><label class="lbl" for="prEq">Equipe</label><select class="sel" id="prEq"></select></div>
        <div class="fg"><label class="lbl" for="prCl">Closer</label><select class="sel" id="prCl"></select></div>
        <div class="pr-range-lbl" id="prRangeLbl" aria-live="polite"></div>
      </div>
      <div class="ld-subpanel" id="prPanelClosers" role="tabpanel">
      <div class="pr-note" id="prNote" hidden></div>
      <section class="pr-kpis" id="prKpis" aria-label="Indicadores do período"></section>
      <section class="pr-grid">
        <article class="pn-card"><div class="pn-card-h"><h2>Funil de vendas</h2><span id="prFunnelSub"></span></div><div id="prFunnel"></div></article>
        <article class="pn-card"><div class="pn-card-h"><h2>Ranking de atividade</h2><span id="prTblSub"></span></div><div class="pr-tbl" id="prTbl" role="table" aria-label="Ranking de atividade dos closers"></div></article>
      </section>
      </div><!-- /prPanelClosers -->
      <div class="ld-subpanel" id="prPanelLideres" role="tabpanel" hidden>
        <?php echo $v->parcial('admin/produtividade-lideres'); /* seção Líderes (fase L2 do módulo do líder): app/Views/admin/produtividade-lideres.php */ ?>
      </div>
      <div class="ld-subpanel" id="prPanelAtiv" role="tabpanel" hidden>
        <?php echo $v->parcial('admin/atividades-lideres'); /* sub-aba Atividades dos líderes (fase L3): app/Views/admin/atividades-lideres.php */ ?>
      </div>
    </div>
  </div>

