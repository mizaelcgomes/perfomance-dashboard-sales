<?php /** @var \App\Core\View $v  Aba calendario do admin: o HTML que antes ficava no admin.php (fase 7). */ ?>
  <!-- ═══════════════ CALENDÁRIO ═══════════════ -->
  <div class="tab-pane" id="tabCalendario">
    <div class="card">
      <div class="card-title">Semanas do Mês <small>define os períodos de cada semana</small></div>
      <div class="cal-month-nav">
        <button class="btn btn-s btn-sm" onclick="calChangeMonth(-1)">‹</button>
        <div class="cal-month-label" id="calMonthLabel">–</div>
        <button class="btn btn-s btn-sm" onclick="calChangeMonth(1)">›</button>
        <span class="cal-status auto" id="calStatus">Auto-calculado</span>
      </div>
      <div class="semana-rows" id="semanaRows"></div>
      <div class="cal-add-btn">
        <button class="btn btn-s" onclick="calAddSemana()">+ Adicionar Semana</button>
      </div>
      <div class="fa">
        <button class="btn btn-p" onclick="calSave()">Salvar Semanas</button>
        <button class="btn btn-d" id="calDeleteBtn" onclick="calDelete()" style="display:none">Remover Configuração</button>
      </div>
      <p style="font-size:10px;color:rgba(212,175,55,.45);margin-top:12px;line-height:1.6">
        Ao salvar, o dashboard usará exatamente esses períodos para o mês selecionado.<br>
        Se nenhuma semana for configurada, o sistema calcula automaticamente (quartas-feiras do mês).
      </p>
    </div>
    <div class="card">
      <div class="card-title">Histórico de Meses Configurados</div>
      <div class="hist-list" id="calHistList"><div class="empty-state">Carregando...</div></div>
    </div>
  </div>

