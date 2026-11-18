<?php /** @var \App\Core\View $v  Aba metas do admin: o HTML que antes ficava no admin.php (fase 7). */ ?>
  <!-- ═══════════════ METAS & REGRAS: visão por exceção ═══════════════ -->
  <div class="tab-pane" id="tabMetas">
    <div class="cv-tabs ms-tabs" role="tablist" aria-label="Metas e regras">
      <button type="button" class="cv-tab active" role="tab" id="msTabPeriodo" aria-selected="true" aria-controls="msPeriodo" onclick="msTab('periodo')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>Metas por Período</button>
      <button type="button" class="cv-tab" role="tab" id="msTabDiaria" aria-selected="false" aria-controls="msDiaria" onclick="msTab('diaria')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Meta Diária</button>
      <button type="button" class="cv-tab" role="tab" id="msTabDesempate" aria-selected="false" aria-controls="msDesempate" onclick="msTab('desempate')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v18"/><path d="M5 7h14"/><path d="M5 7l-3 7a3 3 0 0 0 6 0z"/><path d="M19 7l-3 7a3 3 0 0 0 6 0z"/></svg>Critérios de Desempate</button>
      <button type="button" class="cv-tab" role="tab" id="msTabTipos" aria-selected="false" aria-controls="msTipos" onclick="msTab('tipos')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>Tipos de Benefício</button>
    </div>

    <div class="ms-month" id="msMonthBar">
      <button type="button" class="cv-nb" onclick="mpChangeMonth(-1)" aria-label="Mês anterior">‹</button>
      <b id="mpMonthLabel" aria-live="polite">–</b>
      <button type="button" class="cv-nb" onclick="mpChangeMonth(1)" aria-label="Próximo mês">›</button>
      <small>Mês de referência (vale para Metas por Período e Meta Diária)</small>
    </div>

    <!-- ── 1. Metas por Período ── -->
    <section class="ms-pane" id="msPeriodo" role="tabpanel" aria-labelledby="msTabPeriodo">
      <div class="ms-scope" id="msScope" role="group" aria-label="Metas de quem">
        <button type="button" class="active" data-sc="closer" aria-pressed="true">Closers</button>
        <button type="button" data-sc="equipe" aria-pressed="false">Equipes</button>
        <button type="button" data-sc="geral" aria-pressed="false">Comercial (geral)</button>
      </div>

      <div id="mexView">
        <div class="ms-padrao" id="mexPadrao"><div class="m-loading">Carregando...</div></div>
        <div class="ms-sec-h">
          <h3 id="mexH">Exceções</h3>
          <button type="button" class="btn btn-p" id="mexNew" onclick="mexOpen('')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Definir Exceção</button>
        </div>
        <div class="ms-cards" id="mexList"></div>
      </div>
    </section>

    <!-- ── 2. Meta Diária ── -->
    <section class="ms-pane" id="msDiaria" role="tabpanel" aria-labelledby="msTabDiaria" hidden>
      <div class="ms-tpl-b ms-tpl-solo" id="mdiTpl" aria-checked="true"><span class="ms-tpl-ic"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg></span><b>Personalizado</b><span>Você define os intervalos de dias e quantos contratos por dia em cada um. Vale igual para todos os closers.</span></div>

      <div class="card" id="mdiDist"><div class="m-loading">Carregando...</div></div>

      <div class="ms-sec-h"><h3 id="mdiH">Exceções</h3></div>
      <div class="ms-cards" id="mdiList"></div>
    </section>

    <!-- ── 3. Critérios de Desempate ── -->
    <section class="ms-pane" id="msDesempate" role="tabpanel" aria-labelledby="msTabDesempate" hidden>
      <div class="card ms-prev" id="tbcPreview"></div>
      <div class="card">
        <div class="card-title">Critérios de desempate <small>ordem de prioridade</small></div>
        <p class="tb-hint" style="margin-bottom:14px">Em empate de score, o closer é ordenado pelo critério de maior prioridade (nº 1). Use as setas para reordenar: a prévia acima muda na hora. Nada vale até clicar em <b>Salvar Critérios</b>.</p>
        <div class="tbc-list" id="tbcList"></div>
        <div class="tbc-foot">
          <button type="button" class="btn btn-s" onclick="tbcAddOpen()"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Adicionar Critério</button>
          <button type="button" class="btn btn-s" onclick="tbcReset()"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>Restaurar Padrão</button>
          <span class="sp"></span>
          <span class="tbc-dirty" id="tbcDirty" hidden>Alterações não salvas</span>
          <button type="button" class="btn btn-p" id="tbcSave" onclick="tbcSave()"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Salvar Critérios</button>
        </div>
      </div>
    </section>

    <!-- ── 4. Tipos de Benefício ── -->
    <section class="ms-pane" id="msTipos" role="tabpanel" aria-labelledby="msTabTipos" hidden>
      <div class="ms-sec-h" style="margin-top:0"><h3>Benefícios padrão <small>cor, ícone e posição valem em todo o sistema</small></h3></div>
      <div class="tpb-grid" id="tpbFixos"><div class="m-loading">Carregando...</div></div>
      <div class="ms-sec-h">
        <h3>Benefícios customizáveis <small>tipos extras criados por você</small></h3>
        <button type="button" class="btn btn-p" onclick="tpbOpen(null)"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Adicionar Novo Tipo</button>
      </div>
      <div class="tpb-grid" id="tpbCustom"></div>
    </section>
  </div>

  <!-- Metas por exceção: definir / editar -->
  <dialog class="tm-dlg tm-modal ms-dlg" id="mexDlg" aria-labelledby="mexDlgT">
    <form class="tm-dlg-in" onsubmit="return mexSave(event)" autocomplete="off" novalidate>
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow">Metas por período</div><h3 id="mexDlgT">Definir exceção</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('mexDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="fg"><label class="lbl" for="mexAlvo" id="mexAlvoL">Para quem?</label><select class="sel" id="mexAlvo" onchange="mexAlvoChange()"></select></div>
        <div class="ms-inputs" id="mexInputs"></div>
        <div class="tm-hint" id="mexHint"></div>
        <div class="ms-err" id="mexErr" role="alert"></div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="document.getElementById('mexDlg').close()">Cancelar</button>
        <button type="submit" class="btn btn-p" id="mexOk">Salvar</button>
      </footer>
    </form>
  </dialog>

  <!-- Meta diária: editar um closer -->
  <dialog class="tm-dlg tm-modal ms-dlg wide" id="mdiDlg" aria-labelledby="mdiDlgT">
    <form class="tm-dlg-in" onsubmit="return mdiSave(event)" autocomplete="off" novalidate>
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow">Meta diária · exceção</div><h3 id="mdiDlgT">Editar metas diárias</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('mdiDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="ms-qa">
          <button type="button" class="btn btn-s btn-sm" id="mdiQaTpl" onclick="mdiQa('tpl')">Copiar do template</button>
          <button type="button" class="btn btn-s btn-sm" onclick="mdiQa('zero')">Zerar todos os dias</button>
          <button type="button" class="btn btn-s btn-sm" onclick="mdiQa('clear')">Limpar (sem meta)</button>
          <span style="flex:1"></span>
          <span class="ms-chip" id="mdiSoma">Soma <b>0</b></span>
        </div>
        <div class="ms-days" id="mdiDays"></div>
        <div class="tm-hint" id="mdiHint">Campo vazio = sem meta naquele dia. Zero = meta zero (ex.: férias).</div>
        <div class="ms-err" id="mdiErr" role="alert"></div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="document.getElementById('mdiDlg').close()">Cancelar</button>
        <button type="submit" class="btn btn-p" id="mdiOk">Salvar</button>
      </footer>
    </form>
  </dialog>

  <!-- Desempate: adicionar critério / grupo -->
  <dialog class="tm-dlg tm-modal ms-dlg" id="tbcAddDlg" aria-labelledby="tbcAddT">
    <div class="tm-dlg-in">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow">Critérios de desempate</div><h3 id="tbcAddT">Adicionar critério</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('tbcAddDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div id="tbcAddSimple">
          <div class="lbl">Critérios disponíveis (clique para adicionar ao fim da lista)</div>
          <div class="tbc-avail" id="tbcAvail"></div>
        </div>
        <div class="tbc-sep" id="tbcGrpSep">Ou crie um grupo unificado</div>
        <div id="tbcGrpBox">
          <p class="tb-hint" style="margin:6px 0 0">Um grupo soma vários tipos e conta como um critério só (ex.: Acidente + Doença).</p>
          <div class="tbc-grp" id="tbcGrpChecks"></div>
          <div class="fg" style="margin-top:12px"><label class="lbl" for="tbcGrpLabel">Nome do grupo (opcional)</label><input class="inp" id="tbcGrpLabel" maxlength="60" placeholder="Ex.: Acidente + Doença"></div>
          <div class="ms-err" id="tbcGrpErr" role="alert"></div>
        </div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="document.getElementById('tbcAddDlg').close()">Cancelar</button>
        <button type="button" class="btn btn-p" id="tbcGrpOk" onclick="tbcGrpSave()">Salvar grupo</button>
      </footer>
    </div>
  </dialog>

  <!-- Tipos de benefício: criar / editar -->
  <dialog class="tm-dlg tm-modal ms-dlg" id="tpbDlg" aria-labelledby="tpbDlgT">
    <form class="tm-dlg-in" onsubmit="return tpbSave(event)" autocomplete="off" novalidate>
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow" id="tpbEyebrow">Tipos de benefício</div><h3 id="tpbDlgT">Novo tipo</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('tpbDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="tpb-pv" id="tpbPv"></div>
        <div class="fg"><label class="lbl" for="tpbNome">Nome do tipo</label><input class="inp" id="tpbNome" maxlength="100" required placeholder="Ex.: Pensão por Morte"></div>
        <div class="fg" style="margin-top:14px">
          <div class="tpb-lbl"><label class="lbl" for="tpbCor">Cor</label><button type="button" class="tpb-auto" id="tpbCorAuto" onclick="tpbAuto('cor')" hidden>Voltar ao automático</button></div>
          <div class="tpb-colorrow"><input type="color" id="tpbCor" aria-label="Seletor de cor"><input class="inp" id="tpbCorHex" maxlength="7" aria-label="Cor em hexadecimal" placeholder="#34d399"></div>
          <div class="tpb-sw" id="tpbSw" role="group" aria-label="Cores sugeridas"></div>
        </div>
        <div class="fg" style="margin-top:14px">
          <div class="tpb-lbl"><span class="lbl">Ícone</span><button type="button" class="tpb-auto" id="tpbIcoAuto" onclick="tpbAuto('icone')" hidden>Voltar ao automático</button></div>
          <div class="tpb-icons" id="tpbIcons" role="group" aria-label="Ícones"></div>
        </div>
        <div class="fgrid" style="margin:14px 0 0">
          <div class="fg"><label class="lbl" for="tpbOrdem">Ordem (posição na lista)</label><input class="inp" id="tpbOrdem" type="number" min="1" max="99" inputmode="numeric"></div>
          <div class="fg" id="tpbAtivoBox"><span class="lbl">Status</span><button type="button" class="tpb-st" id="tpbAtivo" role="switch" aria-checked="true" onclick="tpbToggleAtivo()" style="align-self:flex-start;margin-top:6px"><i></i><span>Ativo</span></button></div>
        </div>
        <div class="tm-hint" id="tpbHint"></div>
        <div class="ms-err" id="tpbErr" role="alert"></div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="document.getElementById('tpbDlg').close()">Cancelar</button>
        <button type="submit" class="btn btn-p" id="tpbOk">Salvar</button>
      </footer>
    </form>
  </dialog>

