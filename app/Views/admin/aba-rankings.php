<?php /** @var \App\Core\View $v  Aba rankings do admin: o HTML que antes ficava no admin.php (fase 7). */ ?>
  <!-- ═══════════════ RANKINGS ═══════════════ -->
  <div class="tab-pane" id="tabRankings">
    <div class="rk-head">
      <div class="rk-tabs" role="tablist" aria-label="Tipo de ranking">
        <button type="button" class="rk-tab active" role="tab" aria-selected="true" data-scope="closer"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Closers</button>
        <button type="button" class="rk-tab" role="tab" aria-selected="false" data-scope="equipe"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/></svg>Equipes</button>
      </div>
      <div class="rk-period">
        <select id="rkRef" class="sel rk-ref" aria-label="Escolher período"></select>
        <div class="rk-toggle" role="group" aria-label="Período">
          <button type="button" class="active" data-per="semana" aria-pressed="true">Semana</button>
          <button type="button" data-per="mes" aria-pressed="false">Mês</button>
        </div>
      </div>
    </div>
    <div class="rk-summary" id="rkSummary"></div>
    <div id="rkBoard"><div class="m-loading">Carregando...</div></div>
  </div>

