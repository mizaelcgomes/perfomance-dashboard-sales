<?php /** @var \App\Core\View $v  Aba painel do admin: o HTML que antes ficava no admin.php (fase 7). */ ?>
  <!-- ═══════════════ PAINEL ═══════════════ -->
  <div class="tab-pane active" id="tabPainel">
    <div class="pn-wrap">
      <header class="pn-head">
        <div>
          <h1 class="pn-title">Painel Geral</h1>
          <p class="pn-sub" id="pnSub">Carregando…</p>
        </div>
      </header>

      <div class="pn-chips" id="pnChips" role="group" aria-label="Filtrar por equipe"></div>

      <section class="pn-kpis" id="pnKpis" aria-label="Indicadores"></section>

      <section class="pn-grid2" aria-label="Gráficos">
        <article class="pn-card">
          <div class="pn-card-h"><h2>Ranking de Equipes</h2><span id="pnTeamsSub"></span></div>
          <div class="pn-chart" id="pnTeamsWrap"><canvas id="pnChartTeams" role="img" aria-label="Contratos por equipe na semana"></canvas></div>
        </article>
        <article class="pn-card">
          <div class="pn-card-h"><h2>Benefícios</h2><span id="pnMixSub"></span></div>
          <div class="pn-mix" id="pnMix">
            <div class="pn-donut"><canvas id="pnChartMix" role="img" aria-label="Contratos por tipo de benefício"></canvas></div>
            <ul class="pn-legend" id="pnMixLegend"></ul>
          </div>
        </article>
      </section>

      <section class="pn-card" id="pnDaily" aria-label="Contratos por dia no mês">
        <div class="pn-card-h"><h2>Contratos por Dia</h2><span id="pnDailySub"></span></div>
        <div class="pn-daily-stats" id="pnDailyStats"></div>
        <div class="pn-daily-chart" id="pnDailyWrap"><canvas id="pnChartDaily" role="img" aria-label="Contratos por dia no mês"></canvas></div>
      </section>

      <section class="pn-card" id="pnVt" aria-label="Visão do time">
        <div class="pn-card-h pn-vt-h">
          <h2>Visão do Time</h2>
          <div class="pn-seg" id="pnSeg" role="tablist" aria-label="Período da visão do time">
            <button type="button" role="tab" data-vt="dia" aria-selected="false">Dia</button>
            <button type="button" role="tab" data-vt="semana" aria-selected="true" class="active">Semana</button>
            <button type="button" role="tab" data-vt="mes" aria-selected="false">Mês</button>
          </div>
        </div>
        <div class="pn-vt-bar">
          <div class="pn-chips pn-status" id="pnStatus" role="group" aria-label="Filtrar por situação"></div>
          <span class="pn-vt-sub" id="pnTeamSub"></span>
        </div>
        <div class="pn-dstrip" id="pnDayStrip" role="group" aria-label="Escolher o dia" hidden></div>
        <div class="pn-tblwrap" id="pnTblWrap"></div>
        <div class="pn-cards" id="pnCards"></div>
      </section>
    </div>
  </div>

