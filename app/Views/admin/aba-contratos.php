<?php /** @var \App\Core\View $v  Aba contratos do admin: o HTML que antes ficava no admin.php (fase 7). */ ?>
  <!-- ═══════════════ CONTRATOS: Registro e Feed · Matriz de Auditoria ═══════════════ -->
  <div class="tab-pane" id="tabContratos">
    <div class="cv-tabs" role="tablist" aria-label="Contratos">
      <button type="button" class="cv-tab active" role="tab" id="cvTabFeed" aria-selected="true" aria-controls="cvPaneFeed" onclick="cvTab('feed')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>Registro e Feed</button>
      <button type="button" class="cv-tab" role="tab" id="cvTabMatriz" aria-selected="false" aria-controls="cvPaneMatriz" onclick="cvTab('matriz')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>Matriz de Auditoria</button>
    </div>

    <!-- ── Registro e Feed ── -->
    <section class="cv-pane" id="cvPaneFeed" role="tabpanel" aria-labelledby="cvTabFeed">
      <button type="button" class="cv-cta" id="cvCta" onclick="cvOpen()">
        <span class="cv-cta-ic"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>
        <span><b>REGISTRAR CONTRATO RÁPIDO</b><small>Data, closer, benefício e quantidade em poucos toques</small></span>
      </button>

      <div class="card cv-fcard">
        <div class="cv-fh">
          <div class="card-title">Feed de vendas</div>
          <div class="cv-dnav">
            <button type="button" class="cv-nb" id="cvPrevDay" onclick="cvDay(-1)" aria-label="Dia anterior">‹</button>
            <button type="button" class="cv-dlbl" id="cvDayLbl" onclick="cvDayToday()" title="Voltar para hoje">Hoje</button>
            <button type="button" class="cv-nb" id="cvNextDay" onclick="cvDay(1)" aria-label="Próximo dia">›</button>
          </div>
        </div>
        <ol class="cv-feed" id="cvFeed" aria-live="polite"><li class="cv-empty">Carregando…</li></ol>
      </div>

      <div class="card cv-sum" id="cvSum" aria-label="Resumo do dia">
        <div>
          <small id="cvSumT">Resumo do dia</small>
          <div class="cv-sum-n" id="cvSumN">0</div>
          <div class="cv-sum-l" id="cvSumL">contratos hoje</div>
        </div>
        <div class="cv-sum-chips" id="cvSumChips"></div>
        <div class="cv-sum-note" id="cvSumNote" hidden></div>
      </div>
    </section>

    <!-- ── Matriz de Auditoria ── -->
    <section class="cv-pane" id="cvPaneMatriz" role="tabpanel" aria-labelledby="cvTabMatriz" hidden>
      <div class="card">
        <div class="card-title">Matriz de auditoria <small>benefício × dia</small></div>

        <div class="m-nav" style="margin-bottom:10px;">
          <button type="button" class="btn btn-s btn-sm" onclick="cvMxMonth(-1)" aria-label="Mês anterior">‹</button>
          <div class="m-lbl" id="cvMxMonth">–</div>
          <button type="button" class="btn btn-s btn-sm" onclick="cvMxMonth(1)" aria-label="Próximo mês">›</button>
          <span class="cv-badge" id="cvMxBadge" style="display:none;"></span>
        </div>
        <div class="cv-weeks" id="cvMxWeeks" role="group" aria-label="Semanas do mês"></div>

        <div class="fgrid" style="margin-bottom:12px;">
          <div class="fg" style="grid-column:1/-1;">
            <label class="lbl" for="cvMxCloser">Closer</label>
            <select class="sel" id="cvMxCloser" onchange="cvMxCloser(this)"><option value="0">Todos os closers (consolidado)</option></select>
          </div>
        </div>

        <p class="cv-mx-note" id="cvMxNote"></p>
        <p class="cv-hint">Arraste para o lado para ver todos os dias — a coluna do benefício fica fixa.</p>
        <div class="cv-mx-wrap" id="cvMxWrap" tabindex="0" role="region" aria-label="Matriz de contratos por benefício e dia">
          <table class="cv-mx" id="cvMx"></table>
        </div>
        <div class="cv-mxbar" id="cvMxBar">
          <span class="cv-mx-status" id="cvMxStatus"></span>
          <span class="sp"></span>
          <button type="button" class="btn btn-s" id="cvMxDiscard" onclick="cvMxDiscard()">Descartar</button>
          <button type="button" class="btn btn-p" id="cvMxSave" onclick="cvMxSave()">Salvar alterações</button>
        </div>
      </div>
    </section>
  </div>

