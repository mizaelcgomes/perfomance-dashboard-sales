  <!-- ═══════════════ RELATÓRIOS ═══════════════ -->
  <div class="tab-pane" id="tabRelatorios">
    <div class="subtabs" id="rpTabs" role="tablist" aria-label="Relatórios">
      <button class="subtab active" role="tab" aria-selected="true" aria-controls="rpPerf" onclick="rpSetView('perf',this)">Performance de Closers</button>
      <button class="subtab" role="tab" aria-selected="false" aria-controls="rpCusto" onclick="rpSetView('custo',this)">Custo por Contrato</button>
    </div>

    <!-- ── ABA 1 · Performance de Closers (padrão) ── -->
    <div class="subpanel active" id="rpPerf" role="tabpanel">
      <div class="pf-bar-top">
        <div class="pf-filters">
          <div class="pf-fl">
            <span class="lbl" id="pfLblMes">Mês</span>
            <div class="pf-month" role="group" aria-labelledby="pfLblMes">
              <button type="button" class="btn btn-s btn-sm" onclick="rpChangeMonth(-1)" aria-label="Mês anterior">‹</button>
              <span class="pf-month-lbl rpMonthLbl" aria-live="polite">–</span>
              <button type="button" class="btn btn-s btn-sm" onclick="rpChangeMonth(1)" aria-label="Próximo mês">›</button>
            </div>
          </div>
          <div class="pf-fl">
            <label class="lbl" for="pfEquipe">Equipe</label>
            <select class="sel pf-sel" id="pfEquipe" onchange="pfSetEquipe(this.value)"><option value="">Todas as equipes</option></select>
          </div>
          <div class="pf-fl">
            <label class="lbl" for="pfBenef">Benefício</label>
            <select class="sel pf-sel" id="pfBenef" onchange="pfSetBenef(this.value)"><option value="">Todos os benefícios</option></select>
          </div>
        </div>
        <div class="pf-export">
          <button type="button" class="btn btn-s btn-sm" id="pfBtnCsv" onclick="pfExportCsv()" title="Tabela completa do mês (todas as semanas e benefícios)"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> CSV</button>
          <button type="button" class="btn btn-p btn-sm" id="pfBtnPdf" onclick="pfExportPdf()" title="PDF gerado no servidor, com a tabela completa do mês"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> PDF</button>
        </div>
      </div>

      <div class="pf-seg" id="pfSeg" role="group" aria-label="Período"></div>

      <div id="pfBody" aria-live="polite"><div class="m-loading">Carregando...</div></div>
    </div>

    <!-- ── ABA 2 · Custo por Contrato ── -->
    <div class="subpanel" id="rpCusto" role="tabpanel">
      <div class="cc-top">
        <div class="pf-fl">
          <span class="lbl">Mês</span>
          <div class="pf-month" role="group" aria-label="Mês">
            <button type="button" class="btn btn-s btn-sm" onclick="rpChangeMonth(-1)" aria-label="Mês anterior">‹</button>
            <span class="pf-month-lbl rpMonthLbl" aria-live="polite">–</span>
            <button type="button" class="btn btn-s btn-sm" onclick="rpChangeMonth(1)" aria-label="Próximo mês">›</button>
          </div>
        </div>
        <div class="pf-fl">
          <label class="lbl" for="miMetaCac">Meta de CAC (R$)</label>
          <input type="number" id="miMetaCac" class="chart-sel cc-meta" min="0" step="0.01" onchange="miSaveMetaCac()">
        </div>
        <div class="pf-fl cc-add"><span class="lbl">&nbsp;</span><button type="button" class="btn btn-s btn-sm" onclick="miAddMes()">+ Mês</button></div>
      </div>

      <div class="cc-kpis" id="ccKpis"><div class="m-loading" style="grid-column:1/-1">Carregando...</div></div>

      <div class="card cc-card">
        <div class="card-title">Eficiência Acumulada <small id="ccChartSub">investimento × contratos, dia a dia</small></div>
        <div class="cc-chart"><canvas id="ccChart" role="img" aria-label="Gráfico de linha dupla: investimento acumulado e contratos acumulados por dia do mês"></canvas></div>
        <p class="cc-note" id="ccChartNote"></p>
      </div>

      <div class="card cc-card">
        <div class="card-title">Histórico <small>contratos, investimento e CAC por mês · clique numa linha para ver o mês</small></div>
        <div id="ccHist"><div class="m-loading">Carregando...</div></div>
      </div>
    </div>
  </div>

