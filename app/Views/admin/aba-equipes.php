<?php /** @var \App\Core\View $v  Aba equipes do admin: o HTML que antes ficava no admin.php (fase 7). */ ?>
  <!-- ═══════════════ TIME (Equipes + Closers) ═══════════════ -->
  <div class="tab-pane" id="tabEquipes">
    <div class="tm-layout">

      <!-- Coluna esquerda: visão das equipes -->
      <aside class="tm-side" aria-label="Equipes">
        <div class="tm-side-head">
          <div class="card-title" style="margin-bottom:0">Equipes <small>(<span id="tmTeamCount">0</span>)</small></div>
          <button class="btn btn-p btn-sm" onclick="tmOpenTeamDrawer()">+ Nova Equipe</button>
        </div>
        <div class="tm-mobile-pick">
          <label class="lbl" for="tmTeamSel">Equipe</label>
          <select class="sel" id="tmTeamSel" onchange="tmSelectTeam(this.value)"></select>
        </div>
        <div class="tm-teams" id="tmTeams"></div>
      </aside>

      <!-- Coluna direita: closers da equipe selecionada -->
      <section class="tm-main" aria-label="Closers">
        <div class="tm-head">
          <div class="tm-head-title">
            <span class="tm-dot" id="tmHeadDot"></span>
            <div>
              <h2 id="tmHeadName">Equipe</h2>
              <div class="tm-head-sub" id="tmHeadSub"></div>
            </div>
          </div>
          <button class="btn btn-p" onclick="tmOpenCloserDrawer()">+ Adicionar Closer</button>
        </div>

        <div class="tm-toolbar">
          <div class="tm-chips" id="tmChips" role="tablist" aria-label="Filtrar closers"></div>
          <div class="tm-toolbar-r">
            <label class="tm-selall"><input type="checkbox" id="tmSelAll" onchange="tmSelectAllVisible(this.checked)"> Selecionar todos</label>
            <input class="inp tm-search" id="tmSearch" type="search" placeholder="Buscar closer…" oninput="tmSetSearch(this.value)" aria-label="Buscar closer">
          </div>
        </div>

        <div class="tm-bulk" id="tmBulk" hidden>
          <span class="tm-bulk-n"><b id="tmBulkN">0</b> selecionado(s)</span>
          <button class="btn btn-s btn-sm" onclick="tmOpenBulkMeta('mes')">Definir meta do mês</button>
          <button class="btn btn-s btn-sm" onclick="tmOpenBulkMeta('sem')">Definir meta semanal</button>
          <select class="sel tm-bulk-move" id="tmBulkMove" onchange="tmBulkMove(this)" aria-label="Mover selecionados para"></select>
          <button class="btn btn-sm tm-link" onclick="tmClearSel()">Limpar seleção</button>
        </div>

        <div class="tm-reqs" id="tmReqs" hidden aria-live="polite"></div>
        <div class="tm-grid" id="tmGrid"></div>

        <!-- Offboarding: closers arquivados -->
        <details class="tm-archive" id="tmArchive">
          <summary><span>Arquivo Morto / Ex-Closers</span><span class="tm-count" id="tmArchiveCount">0</span></summary>
          <p class="tm-archive-hint">Histórico de contratos preservado. Clique em <strong>Reativar</strong> para devolver o closer ao time.</p>
          <div class="tm-ex-list" id="tmExList"></div>
        </details>
      </section>
    </div>
  </div>

  <!-- ── Menu flutuante dos 3 pontinhos (preenchido por JS) ── -->
  <div class="tm-menu" id="tmMenu" role="menu" hidden></div>

  <!-- ── Drawer lateral: editar / adicionar closer ── -->
  <dialog class="tm-dlg tm-drawer" id="tmDrawerCl">
    <form id="clForm" class="tm-dlg-in" onsubmit="return submitCloser(event)">
      <input type="hidden" id="clId">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow" id="clEyebrow">Novo closer</div><h3 id="clDrawerTitle">Adicionar closer</h3></div>
        <button type="button" class="tm-x" onclick="resetClForm()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="fgrid" style="grid-template-columns:1fr 1fr">
          <div class="fg" style="grid-column:1/-1"><label class="lbl" for="clNome">Nome</label><input class="inp" id="clNome" required placeholder="Nome do closer"></div>
          <div class="fg" style="grid-column:1/-1"><label class="lbl" for="clEquipe">Equipe</label><select class="sel" id="clEquipe"><option value="">Sem equipe</option></select></div>
          <div class="fg" style="grid-column:1/-1"><label class="lbl" for="clCor">Cor</label><input type="color" class="inp" id="clCor" value="#d4af37" style="height:40px;padding:3px;"></div>
          <div class="tm-hint" style="grid-column:1/-1;margin-top:0">As metas não são definidas aqui. O closer segue a <b>meta padrão</b>; para mudar a meta dele, use <b>Metas &amp; Regras → Metas por Período</b>.</div>
          <div class="fg" style="grid-column:1/-1">
            <label class="lbl">Foto (opcional)</label>
            <div class="upload-wrap" id="clUploadArea" onclick="document.getElementById('clFoto').click()">
              <input type="file" style="display:none" id="clFoto" accept="image/*" onchange="previewImg(event,'clPreviewImg','clUploadArea','clPlaceholder','clPreviewWrap')">
              <div id="clPlaceholder"><div style="font-size:10px;color:#f0d878">Clique para enviar</div><div style="font-size:9px;color:rgba(212,175,55,.45)">JPG, PNG ou GIF (máx 500KB)</div></div>
              <div id="clPreviewWrap" style="display:none;position:relative"><div class="up-preview"><img id="clPreviewImg" src=""><div class="rm-btn" onclick="event.stopPropagation();removeImg('clFoto','clUploadArea','clPlaceholder','clPreviewWrap')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></div></div></div>
            </div>
          </div>
        </div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="resetClForm()">Cancelar</button>
        <button type="submit" class="btn btn-p" id="clSubmitBtn">Adicionar Closer</button>
      </footer>
    </form>
  </dialog>

  <!-- ── Drawer lateral: editar / criar equipe ── -->
  <dialog class="tm-dlg tm-drawer" id="tmDrawerEq">
    <form id="eqForm" class="tm-dlg-in" onsubmit="return submitEquipe(event)">
      <input type="hidden" id="eqId">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow" id="eqEyebrow">Nova equipe</div><h3 id="eqDrawerTitle">Criar equipe</h3></div>
        <button type="button" class="tm-x" onclick="resetEqForm()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="fgrid" style="grid-template-columns:1fr 1fr">
          <div class="fg" style="grid-column:1/-1"><label class="lbl" for="eqNome">Nome da equipe</label><input class="inp" id="eqNome" required placeholder="Ex: Equipe Alpha"></div>
          <div class="fg" style="grid-column:1/-1">
            <label class="lbl" for="eqLider">Líder da equipe</label>
            <select class="sel" id="eqLider"><option value="">— sem líder —</option></select>
            <div style="font-size:9px;color:rgba(212,175,55,.45);margin-top:4px;line-height:1.5">
              Apenas closers já vinculados a esta equipe. Em equipe nova, crie a equipe, vincule os closers e depois volte aqui para definir o líder (ou use “Tornar líder” no menu do closer).
            </div>
          </div>
          <div class="fg" style="grid-column:1/-1"><label class="lbl" for="eqCor">Cor</label><input type="color" class="inp" id="eqCor" value="#d4af37" style="height:40px;padding:3px;"></div>
          <div class="fg" style="grid-column:1/-1">
            <label class="tm-sw"><input type="checkbox" id="eqOcultaTv"><span>Tirar do ranking da TV</span></label>
            <div style="font-size:9px;color:rgba(212,175,55,.45);margin-top:4px;line-height:1.5">A equipe e os closers dela deixam de aparecer no ranking, na solenidade e no Hall da Fama da TV. Nada some do admin, dos relatórios nem dos portais; os contratos continuam no total da empresa.</div>
          </div>
          <div class="tm-hint" style="grid-column:1/-1;margin-top:0">A meta da equipe é a <b>soma das metas dos closers</b>. Para fixar outra meta, use <b>Metas &amp; Regras → Metas por Período → Equipes</b>.</div>
          <div class="fg" style="grid-column:1/-1">
            <label class="lbl">Imagem (opcional)</label>
            <div class="upload-wrap" id="eqUploadArea" onclick="document.getElementById('eqFoto').click()">
              <input type="file" style="display:none" id="eqFoto" accept="image/*" onchange="previewImg(event,'eqPreviewImg','eqUploadArea','eqPlaceholder','eqPreviewWrap')">
              <div id="eqPlaceholder"><div style="font-size:10px;color:#f0d878">Clique para enviar</div></div>
              <div id="eqPreviewWrap" style="display:none;position:relative"><div class="up-preview"><img id="eqPreviewImg" src=""><div class="rm-btn" onclick="event.stopPropagation();removeImg('eqFoto','eqUploadArea','eqPlaceholder','eqPreviewWrap')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></div></div></div>
            </div>
          </div>
        </div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="resetEqForm()">Cancelar</button>
        <button type="submit" class="btn btn-p" id="eqSubmitBtn">Criar Equipe</button>
      </footer>
    </form>
  </dialog>

  <!-- ── Modal: férias com período ── -->
  <dialog class="tm-dlg tm-modal" id="tmFeriasDlg">
    <form class="tm-dlg-in" onsubmit="return tmSaveFerias(event)">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg> Férias</div><h3 id="tmFerName">Closer</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('tmFeriasDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="fgrid" style="grid-template-columns:1fr 1fr">
          <div class="fg"><label class="lbl" for="tmFerStart">Data de início</label><input type="date" class="inp" id="tmFerStart" required oninput="tmFerasHint()"></div>
          <div class="fg"><label class="lbl" for="tmFerEnd">Data de retorno (último dia)</label><input type="date" class="inp" id="tmFerEnd" oninput="tmFerasHint()"></div>
        </div>
        <div class="tm-presets" aria-label="Atalhos de duração">
          <span>Atalhos:</span>
          <button type="button" class="tm-chip" onclick="tmFerPreset(7)">7 dias</button>
          <button type="button" class="tm-chip" onclick="tmFerPreset(15)">15 dias</button>
          <button type="button" class="tm-chip" onclick="tmFerPreset(30)">30 dias</button>
        </div>
        <label class="tm-check"><input type="checkbox" id="tmFerNoEnd" onchange="tmFerasHint()"> Sem data de retorno definida</label>
        <div class="tm-hint" id="tmFerHint"></div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-w" id="tmFerEndBtn" onclick="tmEndFerias()" hidden>Encerrar férias agora</button>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-s" onclick="document.getElementById('tmFeriasDlg').close()">Cancelar</button>
        <button type="submit" class="btn btn-p">Salvar férias</button>
      </footer>
    </form>
  </dialog>

  <!-- ── Modal: desligamento (motivo obrigatório) ── -->
  <dialog class="tm-dlg tm-modal" id="tmDesligarDlg">
    <form class="tm-dlg-in" onsubmit="return tmConfirmDesligar(event)">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow">Desligamento</div><h3 id="tmDesName">Closer</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('tmDesligarDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="fg">
          <label class="lbl" for="tmDesMotivo">Motivo do desligamento</label>
          <select class="sel" id="tmDesMotivo" required onchange="document.getElementById('tmDesOk').disabled=!this.value">
            <option value="">Selecione o motivo…</option>
            <option value="demissao">Demissão</option>
            <option value="transferencia">Transferência</option>
            <option value="ferias_prolongadas">Férias prolongadas</option>
          </select>
        </div>
        <div class="tm-hint">O closer sai do ranking e vai para o <strong>Arquivo Morto</strong>. O histórico de contratos é preservado e dá para reativar depois.</div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="document.getElementById('tmDesligarDlg').close()">Cancelar</button>
        <button type="submit" class="btn btn-d" id="tmDesOk" disabled>Mover para o arquivo</button>
      </footer>
    </form>
  </dialog>

  <!-- ── Modal: meta em lote ── -->
  <dialog class="tm-dlg tm-modal" id="tmMetaDlg">
    <form class="tm-dlg-in" onsubmit="return tmSaveBulkMeta(event)">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow">Ação em lote</div><h3 id="tmMetaTitle">Definir meta</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('tmMetaDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="fg">
          <label class="lbl" for="tmMetaInput" id="tmMetaLabel">Meta</label>
          <input type="number" class="inp" id="tmMetaInput" min="0" required>
        </div>
        <div class="tm-hint" id="tmMetaHint"></div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="document.getElementById('tmMetaDlg').close()">Cancelar</button>
        <button type="submit" class="btn btn-p">Aplicar</button>
      </footer>
    </form>
  </dialog>

  <!-- ── Modal: aprovar acesso ao portal ── -->
  <dialog class="tm-dlg tm-modal" id="tmAccEditDlg">
    <form class="tm-dlg-in" onsubmit="return tmAccEditSave(event)">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow">Pedido de acesso ao portal</div><h3>Editar pedido</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('tmAccEditDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="fg"><label class="lbl" for="tmAccEditNome">Nome completo</label><input class="inp" id="tmAccEditNome" maxlength="100" required autocomplete="off"></div>
        <div class="fg"><label class="lbl" for="tmAccEditEmail">E-mail (login do closer)</label><input class="inp" id="tmAccEditEmail" type="email" maxlength="150" required autocomplete="off"></div>
        <div class="fg"><label class="lbl" for="tmAccEditEq">Equipe</label><select class="sel" id="tmAccEditEq"></select></div>
        <div class="tm-hint" id="tmAccEditHint"></div>
        <div id="tmAccEditErr" role="alert" style="font-size:12px;font-weight:700;color:#fb7185;min-height:0"></div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="document.getElementById('tmAccEditDlg').close()">Cancelar</button>
        <button type="submit" class="btn btn-p" id="tmAccEditOk">Salvar pedido</button>
      </footer>
    </form>
  </dialog>
  <dialog class="tm-dlg tm-modal" id="tmAccDlg">
    <form class="tm-dlg-in" onsubmit="return tmAccConfirm(event)">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow">Acesso ao Portal do Closer</div><h3>Aprovar acesso</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('tmAccDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div id="tmAccWho"></div>
        <div id="tmAccLink" hidden>
          <div class="lbl" id="tmAccLinkL">Qual é o closer desta conta?</div>
          <label class="tm-radio"><input type="radio" name="tmAccModo" value="existente" checked onchange="tmAccMode()"> Vincular a um closer já cadastrado</label>
          <select class="sel" id="tmAccSel" aria-labelledby="tmAccLinkL"></select>
          <label class="tm-radio"><input type="radio" name="tmAccModo" value="novo" onchange="tmAccMode()"> Criar um novo closer na equipe <b id="tmAccTeam"></b></label>
        </div>
        <div class="tm-hint" id="tmAccHint"></div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="document.getElementById('tmAccDlg').close()">Cancelar</button>
        <button type="submit" class="btn btn-p" id="tmAccOk">Aprovar e liberar acesso</button>
      </footer>
    </form>
  </dialog>

  <!-- ── Modal: rejeitar acesso ── -->
  <dialog class="tm-dlg tm-modal" id="tmRejDlg">
    <form class="tm-dlg-in" onsubmit="return tmRejConfirm(event)">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow">Acesso ao Portal do Closer</div><h3>Rejeitar acesso</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('tmRejDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div id="tmRejWho"></div>
        <div class="fg">
          <label class="lbl" for="tmRejMotivo">Motivo (opcional)</label>
          <textarea class="inp" id="tmRejMotivo" maxlength="255" rows="3" placeholder="Ex.: não reconheci esse cadastro"></textarea>
        </div>
        <div class="tm-hint">O closer não consegue entrar e vê “Acesso negado”. Você pode reconsiderar depois.</div>
      </div>
      <footer class="tm-dlg-foot">
        <button type="button" class="btn btn-s" onclick="document.getElementById('tmRejDlg').close()">Cancelar</button>
        <button type="submit" class="btn btn-d" id="tmRejOk">Rejeitar acesso</button>
      </footer>
    </form>
  </dialog>

  <!-- ── Modal: convite / token (mostra o texto uma única vez) ── -->
  <dialog class="tm-dlg tm-modal" id="tmShareDlg">
    <div class="tm-dlg-in">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow" id="tmShEye">Convite</div><h3 id="tmShTitle">Link de cadastro</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('tmShareDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div id="tmShWho" class="tm-acc-who"></div>
        <div class="lbl" id="tmShValL">Link</div>
        <div class="tm-share-val" id="tmShVal" aria-labelledby="tmShValL"></div>
        <div class="tm-share-acts">
          <button type="button" class="btn btn-p btn-sm" id="tmShCopy" onclick="tmShareCopy('val')">Copiar</button>
          <button type="button" class="btn btn-s btn-sm" onclick="tmShareCopy('msg')">Copiar mensagem pronta</button>
          <a class="btn btn-s btn-sm" id="tmShWa" target="_blank" rel="noopener noreferrer">Abrir no WhatsApp</a>
        </div>
        <div class="tm-hint" id="tmShNote"></div>
      </div>
      <footer class="tm-dlg-foot"><button type="button" class="btn btn-s" onclick="document.getElementById('tmShareDlg').close()">Fechar</button></footer>
    </div>
  </dialog>

  <!-- ── Modal: histórico completo de ações de um closer (Produtividade) ── -->
  <dialog class="tm-dlg tm-modal" id="prAllDlg" style="width:min(680px,calc(100vw - 28px))">
    <div class="tm-dlg-in">
      <header class="tm-dlg-head">
        <div><div class="tm-eyebrow">Produtividade</div><h3 id="prAllTitle">Todas as ações</h3></div>
        <button type="button" class="tm-x" onclick="document.getElementById('prAllDlg').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body">
        <div class="pr-all-f">
          <div class="fg"><label class="lbl" for="prAllTipo">Tipo</label><select class="sel" id="prAllTipo" onchange="prAllLoad(true)"><option value="">Todos</option><option value="ligacao">Ligação</option><option value="followup">Follow-up</option><option value="lead_quente">Lead quente</option></select></div>
          <div class="fg"><label class="lbl" for="prAllFrom">De</label><input type="date" class="inp" id="prAllFrom" onchange="prAllLoad(true)"></div>
          <div class="fg"><label class="lbl" for="prAllTo">Até</label><input type="date" class="inp" id="prAllTo" onchange="prAllLoad(true)"></div>
        </div>
        <div class="pr-all-body"><div class="pr-last" id="prAllList"></div></div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px"><span class="tm-head-sub" id="prAllCount"></span><button type="button" class="btn btn-s btn-sm" id="prAllMore" hidden onclick="prAllLoad(false)">Carregar mais</button></div>
        <div class="tm-hint">Os números dos clientes aparecem mascarados.</div>
      </div>
    </div>
  </dialog>

  <!-- ── Drawer: mix de benefícios completo de um closer (Relatórios → Performance de Closers) ── -->
  <dialog class="tm-dlg tm-drawer" id="pfDrawer" aria-labelledby="pfDwHead">
    <div class="tm-dlg-in">
      <header class="tm-dlg-head">
        <div id="pfDwHead"></div>
        <button type="button" class="tm-x" onclick="document.getElementById('pfDrawer').close()" aria-label="Fechar"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="tm-dlg-body" id="pfDwBody"></div>
    </div>
  </dialog>

  <!-- ── Modal: confirmação genérica ── -->
  <dialog class="tm-dlg tm-modal" id="tmConfirmDlg">
    <form method="dialog" class="tm-dlg-in">
      <header class="tm-dlg-head"><div><h3 id="tmConfTitle">Confirmar</h3></div></header>
      <div class="tm-dlg-body"><p id="tmConfMsg" class="tm-conf-msg"></p></div>
      <footer class="tm-dlg-foot">
        <button type="submit" value="cancel" class="btn btn-s">Cancelar</button>
        <button type="submit" value="ok" class="btn btn-d" id="tmConfOk">Confirmar</button>
      </footer>
    </form>
  </dialog>

