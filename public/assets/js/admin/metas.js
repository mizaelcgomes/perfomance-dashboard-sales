// ══════════════════════════════════════════════════════════════════════════
// METAS & REGRAS — visão por exceção
//   Metas por Período · Meta Diária (templates) · Critérios de Desempate · Tipos de Benefício
//   dados: api.php?action=get_metas_excecoes · save_meta_excecao · remove_meta_excecao · get_metas_diarias_estado ·
//          aplicar_meta_diaria · save_meta_diaria_closer · remove_meta_diaria_excecao · get/save_tiebreaker · get_tipos_config …
// ══════════════════════════════════════════════════════════════════════════
const msEl = id => document.getElementById(id);
const MS = { tab:'periodo', scope:'closer' };
try { const t = localStorage.getItem('msTab'); if (['periodo','diaria','desempate','tipos'].includes(t)) MS.tab = t; } catch(e) {}
const MS_VIEWS = { periodo:'Periodo', diaria:'Diaria', desempate:'Desempate', tipos:'Tipos' };
const MS_DOW = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
const msOk = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
const msInfo = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
const msUp = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>';
const msDown = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';
const msX = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>';
const msTargetIco = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>';
const msPlural = (n, s, p) => n + ' ' + (n === 1 ? s : p);
const msDM = iso => iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) : '';

function msShow(){ msTab(MS.tab, true); }
async function msTab(v, force){
  if (!MS_VIEWS[v]) return;
  if (!force && v === MS.tab) return;
  if (MS.tab === 'desempate' && v !== 'desempate' && TBC.dirty) {
    if (!await tmConfirm('Descartar alterações?', 'Você mudou os critérios de desempate e ainda não salvou.', 'Descartar')) return;
    TBC.dirty = false;
  }
  MS.tab = v; try { localStorage.setItem('msTab', v); } catch(e) {}
  Object.entries(MS_VIEWS).forEach(([k, n]) => {
    const on = k === v;
    msEl('msTab' + n).classList.toggle('active', on); msEl('msTab' + n).setAttribute('aria-selected', String(on));
    msEl('ms' + n).hidden = !on;
  });
  msEl('msMonthBar').hidden = !(v === 'periodo' || v === 'diaria');
  if (v === 'periodo') mexShow(); else if (v === 'diaria') mdiLoad(); else if (v === 'desempate') tbcLoad(); else tpbLoad();
}
function msInit(){
  msEl('mpMonthLabel').textContent = ptMonths[mpMonth - 1] + ' ' + mpYear;
  msEl('msScope').addEventListener('click', e => { const b = e.target.closest('[data-sc]'); if (b) msScopeSet(b.dataset.sc); });
}
function msScopeSet(sc){
  if (sc === MS.scope) return;
  MS.scope = sc;
  msEl('msScope').querySelectorAll('[data-sc]').forEach(x => { const on = x.dataset.sc === sc; x.classList.toggle('active', on); x.setAttribute('aria-pressed', String(on)); });
  mexShow();
}

// ── Mês de referência (vale para Metas por Período e Meta Diária) ──────────
let mpYear = ADMIN_BOOT.curYear, mpMonth = ADMIN_BOOT.curMonth;
function mpChangeMonth(d){
  mpMonth += d;
  if (mpMonth > 12) { mpMonth = 1; mpYear++; }
  if (mpMonth < 1) { mpMonth = 12; mpYear--; }
  msEl('mpMonthLabel').textContent = ptMonths[mpMonth - 1] + ' ' + mpYear;
  if (MS.tab === 'periodo') mexShow(); else if (MS.tab === 'diaria') mdiLoad();
}

// ══ 1. METAS POR PERÍODO — padrão + exceções (Closers · Equipes · Comercial) ═══
// Closers: o padrão é o valor que a maioria dos closers já tem; exceção = closer com meta diferente.
// Equipes e Comercial (geral): o padrão é a soma automática das metas dos closers; exceção = meta fixa definida aqui.
const MEX = { data:null, seq:0, editing:false };
const mexLbl = c => c === 0 ? 'Mês' : 'S' + c;
const mexG = () => MS.scope !== 'closer';                              // equipes / comercial: metas fixas sobre a soma automática
const mexItens = () => !MEX.data ? [] : mexG() ? MEX.data.itens : MEX.data.closers;
function mexShow(){ mexLoad(); }
async function mexLoad(){
  const seq = ++MEX.seq, g = mexG();
  MEX.data = null;
  msEl('mexPadrao').innerHTML = '<div class="m-loading">Carregando...</div>'; msEl('mexList').innerHTML = '';
  const url = g ? `${API_URL}?action=get_metas_excecoes_grupo&escopo=${MS.scope}&ano=${mpYear}&mes=${mpMonth}` : `${API_URL}?action=get_metas_excecoes&ano=${mpYear}&mes=${mpMonth}`;
  const r = await fetch(url).then(x => x.json()).catch(() => null);
  if (seq !== MEX.seq) return;
  if (!r || !r.success) { msEl('mexPadrao').innerHTML = '<div class="m-loading">Não foi possível carregar as metas.</div>'; return; }
  MEX.data = r; mexRender();
}
function mexRender(){ if (!MEX.data) return; if (mexG()) mexRenderG(); else mexRenderC(); }
function mexRenderC(){
  const d = MEX.data;
  // meta padrão
  const chips = d.semanas.map(w => '<span class="ms-chip">S' + w.num + ' <b>' + d.padrao[w.num] + '</b></span>').join('') + '<span class="ms-chip">Mês <b>' + d.padrao[0] + '</b></span>';
  const principal = d.padrao_semanal !== null ? '<b>' + d.padrao_semanal + '</b> contratos/semana' : 'metas por semana <small style="font-size:12px;color:var(--muted)">(variam: veja ao lado)</small>';
  const para = !d.total_closers ? 'Nenhum closer ativo.' : d.excecoes_n === 0 ? 'para todos os <b>' + d.total_closers + '</b> closers' : 'para <b>' + d.seguem + '</b> de ' + d.total_closers + ' closers · <b>' + msPlural(d.excecoes_n, 'exceção', 'exceções') + '</b>';
  msEl('mexPadrao').innerHTML = '<div class="ms-padrao-ic">' + msTargetIco + '</div>'
    + '<div class="ms-padrao-main"><small>Meta padrão · ' + esc(ptMonths[d.mes - 1]) + ' ' + d.ano + '</small><div class="ms-padrao-n">' + principal + '</div><div class="ms-padrao-s">' + para + ' · o padrão é o valor que a maioria dos closers já tem</div></div>'
    + '<div class="ms-chips" aria-label="Meta padrão por período">' + chips + '</div>'
    + '<button type="button" class="btn btn-s" onclick="mexOpen(\'todos\',false)"' + (d.total_closers ? '' : ' disabled') + '>Editar padrão</button>';
  // exceções
  const exc = d.closers.filter(c => c.excecao);
  msEl('mexH').innerHTML = 'Exceções <small>' + (exc.length ? msPlural(exc.length, 'closer', 'closers') + ' com meta diferente do padrão' : 'nenhuma') + '</small>';
  msEl('mexNew').hidden = false;
  msEl('mexNew').disabled = !d.total_closers;
  msEl('mexList').innerHTML = exc.length ? exc.map(c => {
    const diffs = c.diffs.map(x => '<span class="ms-diff ' + (x.valor > x.padrao ? 'up' : 'down') + '" title="' + (x.cel === 0 ? 'Meta do mês' : 'Semana ' + x.cel) + '"><b>' + mexLbl(x.cel) + '</b> <strong>' + x.valor + '</strong> <em>padrão ' + x.padrao + '</em></span>').join('');
    return '<article class="ms-card" data-id="' + c.id + '"><div class="tm-av">' + esc(tmInitials(c.nome)) + '</div>'
      + '<div class="ms-card-main"><div class="tm-name" title="' + esc(c.nome) + '">' + esc(c.nome) + '</div><div class="ms-card-sub">' + esc(c.equipe_nome || 'Sem equipe') + (c.ferias ? ' · em férias' : '') + '</div><div class="ms-diffs">' + diffs + '</div></div>'
      + '<div class="ms-card-b"><button type="button" class="btn btn-s btn-sm" data-act="edit">' + TM_IC.edit + ' Editar</button><button type="button" class="btn btn-d btn-sm" data-act="rm">Remover Exceção</button></div></article>';
  }).join('') : '<div class="ms-empty">' + msOk + '<div>Todos os closers seguem a meta padrão. Nenhuma exceção configurada.<small>Use “Definir Exceção” para um closer com meta diferente (ex.: novato, férias, meta reforçada).</small></div></div>';
}
function mexRenderG(){
  const d = MEX.data, eq = d.escopo === 'equipe';
  let chips, principal, para;
  if (eq) {
    // padrão por célula = o valor que TODAS as equipes têm (fixo ou soma dos closers); "varia" quando cada equipe tem o seu
    const eff = (i, c) => i.fixas[c] !== undefined ? i.fixas[c] : i.auto[c];
    const comum = c => d.itens.length && d.itens.every(i => eff(i, c) === eff(d.itens[0], c)) ? eff(d.itens[0], c) : null;
    chips = d.semanas.map(w => '<span class="ms-chip">S' + w.num + ' <b>' + (comum(w.num) ?? 'varia') + '</b></span>').join('') + '<span class="ms-chip">Mês <b>' + (comum(0) ?? 'varia') + '</b></span>';
    const sem = d.semanas.map(w => comum(w.num));
    principal = sem.length && sem.every(v => v !== null && v === sem[0]) ? '<b>' + sem[0] + '</b> contratos/semana' : 'Soma das metas dos closers';
    const iguais = d.semanas.every(w => comum(w.num) !== null) && comum(0) !== null;
    para = !d.total ? 'Nenhuma equipe cadastrada'
      : d.excecoes_n === 0 ? 'para todas as <b>' + d.total + '</b> equipes · cada uma soma as metas dos próprios closers'
      : iguais ? 'para todas as <b>' + d.total + '</b> equipes · <b>' + msPlural(d.excecoes_n, 'exceção', 'exceções') + '</b> · meta fixa igual em todas'
      : 'para <b>' + d.seguem + '</b> de ' + d.total + ' equipes · <b>' + msPlural(d.excecoes_n, 'exceção', 'exceções') + '</b> · cada equipe soma as metas dos próprios closers';
  } else {
    // o padrão do comercial é a meta coletiva fixa, se houver; senão a soma das metas dos closers
    const fix = d.itens[0] && d.itens[0].excecao ? d.itens[0].fixas : {};
    const eff = c => fix[c] !== undefined ? fix[c] : d.auto_total[c];
    const sem = d.semanas.map(w => eff(w.num)), igual = sem.length > 0 && sem.every(v => v === sem[0]);
    chips = d.semanas.map(w => '<span class="ms-chip">S' + w.num + ' <b>' + eff(w.num) + '</b></span>').join('') + '<span class="ms-chip">Mês <b>' + eff(0) + '</b></span>';
    principal = igual ? '<b>' + sem[0] + '</b> contratos/semana' : 'metas por semana <small style="font-size:12px;color:var(--muted)">(variam: veja ao lado)</small>';
    para = d.excecoes_n ? 'meta coletiva fixa · a soma dos <b>' + d.total_closers + '</b> closers ativos daria ' + d.auto_total[0] + ' no mês' : 'soma das metas dos <b>' + d.total_closers + '</b> closers ativos · sem exceção';
  }
  msEl('mexPadrao').innerHTML = '<div class="ms-padrao-ic">' + msTargetIco + '</div>'
    + '<div class="ms-padrao-main"><small>Meta padrão · ' + esc(ptMonths[d.mes - 1]) + ' ' + d.ano + '</small><div class="ms-padrao-n">' + principal + '</div><div class="ms-padrao-s">' + para + '</div></div>'
    + '<div class="ms-chips" aria-label="Meta padrão">' + chips + '</div>'
    + '<button type="button" class="btn btn-s" onclick="mexOpen(\'todos\',false)"' + (d.total ? '' : ' disabled') + '>Editar padrão</button>';
  const exc = d.itens.filter(i => i.excecao);
  msEl('mexH').innerHTML = 'Exceções <small>' + (exc.length ? (eq ? msPlural(exc.length, 'equipe', 'equipes') + ' com meta fixa' : 'meta coletiva fixa') : 'nenhuma') + '</small>';
  msEl('mexNew').hidden = MS.scope === 'geral';
  msEl('mexNew').disabled = !d.total;
  msEl('mexList').innerHTML = exc.length ? exc.map(i => {
    const diffs = i.diffs.map(x => '<span class="ms-diff ' + (x.valor > x.padrao ? 'up' : x.valor < x.padrao ? 'down' : '') + '" title="' + (x.cel === 0 ? 'Meta do mês' : 'Semana ' + x.cel) + '"><b>' + mexLbl(x.cel) + '</b> <strong>' + x.valor + '</strong> <em>soma ' + x.padrao + '</em></span>').join('');
    const sub = eq ? msPlural(i.closers, 'closer ativo', 'closers ativos') : 'meta coletiva da empresa';
    return '<article class="ms-card" data-id="' + i.id + '"><div class="tm-av">' + (eq ? esc(tmInitials(i.nome)) : msTargetIco) + '</div>'
      + '<div class="ms-card-main"><div class="tm-name" title="' + esc(i.nome) + '">' + esc(i.nome) + '</div><div class="ms-card-sub">' + sub + (i.legado ? ' · meta fixa do cadastro' : '') + '</div><div class="ms-diffs">' + diffs + '</div></div>'
      + '<div class="ms-card-b"><button type="button" class="btn btn-s btn-sm" data-act="edit">' + TM_IC.edit + ' Editar</button><button type="button" class="btn btn-d btn-sm" data-act="rm">Remover Exceção</button></div></article>';
  }).join('') : !d.total ? '<div class="ms-empty neutro">' + msInfo + '<div>Nenhuma equipe cadastrada.<small>Crie equipes na aba Time para definir metas por equipe.</small></div></div>'
    : '<div class="ms-empty">' + msOk + '<div>' + (eq ? 'Todas as equipes seguem a soma automática das metas dos closers. Nenhuma exceção configurada.' : 'A meta comercial segue a soma automática das metas dos closers. Nenhuma exceção configurada.')
      + '<small>' + (eq ? 'Use “Definir Exceção” para fixar uma meta própria para uma equipe (ex.: equipe nova, meta reforçada).' : 'Use “Editar padrão” para fixar uma meta coletiva diferente da soma (ex.: meta da empresa para o mês).') + '</small></div></div>';
}
msEl('mexList').addEventListener('click', e => {
  const b = e.target.closest('[data-act]'); if (!b) return;
  const id = b.closest('.ms-card').dataset.id;
  if (b.dataset.act === 'edit') mexOpen(id, true); else mexRemove(+id);
});
function mexAlvoVals(alvo){
  const d = MEX.data;
  if (mexG()) { const it = d.itens.find(x => String(x.id) === String(alvo)); return it ? it.fixas : {}; }
  if (alvo === 'todos' || alvo === '') return d.padrao;
  const c = d.closers.find(x => String(x.id) === String(alvo)); return c ? c.efetivas : d.padrao;
}
function mexOpen(alvo, editing){
  const d = MEX.data, its = mexItens(); if (!d || !its.length) return;
  const g = mexG(), eq = MS.scope === 'equipe', geral = MS.scope === 'geral';
  if (geral) alvo = '0';
  MEX.editing = !!editing;
  msEl('mexDlgT').textContent = editing ? 'Editar exceção' : (alvo === 'todos' || geral ? 'Editar meta padrão' : 'Definir exceção');
  msEl('mexAlvoL').textContent = !g ? 'Para quem?' : eq ? 'Equipe' : 'Meta';
  const sel = msEl('mexAlvo');
  if (g) sel.innerHTML = (editing || geral ? '' : '<option value="">Selecione…</option>') + (eq && !editing ? '<option value="todos">Todas as equipes (' + its.length + ')</option>' : '') + its.map(i => '<option value="' + i.id + '">' + esc(i.nome) + (i.excecao && !geral ? ' · exceção' : '') + '</option>').join('');
  else sel.innerHTML = (editing ? '' : '<option value="">Selecione…</option>') + (editing ? '' : '<option value="todos">Todos os closers (' + d.total_closers + ')</option>')
    + its.map(c => '<option value="' + c.id + '">' + esc(c.nome) + (c.excecao ? ' · exceção' : '') + '</option>').join('');
  sel.value = String(alvo); sel.disabled = !!editing || geral;
  msEl('mexErr').textContent = ''; msEl('mexOk').disabled = false;
  mexBuild();
  msEl('mexDlg').showModal();
  (editing || geral || alvo === 'todos' ? msEl('mexInputs').querySelector('input') : sel).focus();
}
function mexAlvoChange(){ mexBuild(); msEl('mexErr').textContent = ''; }
function mexBuild(){
  const d = MEX.data, g = mexG(), alvo = msEl('mexAlvo').value, base = mexAlvoVals(alvo);
  const it = g ? d.itens.find(x => String(x.id) === String(alvo)) : null;
  // equipe/geral: vazio = automático (soma dos closers); em "todas as equipes" a soma de referência só aparece se for a mesma para todas
  const ph = c => !g ? d.padrao[c] : it ? it.auto[c] : alvo === 'todos' ? (d.itens.every(i => i.auto[c] === d.itens[0].auto[c]) ? d.itens[0].auto[c] : 'varia') : '';
  const cels = d.semanas.map(w => ({ c:w.num, rot:'S' + w.num, sub:msDM(w.start) + '–' + msDM(w.end) })).concat([{ c:0, rot:'Mês', sub:'meta do mês' }]);
  msEl('mexInputs').innerHTML = cels.map(x => '<div class="fg"><label class="lbl" for="mexI' + x.c + '">' + x.rot + ' <small>' + x.sub + '</small></label>'
    + '<input class="inp" id="mexI' + x.c + '" type="number" min="1" max="99999" inputmode="numeric" data-c="' + x.c + '" data-o="' + (base[x.c] ?? '') + '" value="' + (base[x.c] ?? '') + '" placeholder="' + (ph(x.c) ?? '') + '"' + (alvo === '' ? ' disabled' : '') + '></div>').join('');
  mexHint();
}
msEl('mexInputs').addEventListener('input', e => { const i = e.target; if (i.dataset.c === undefined) return; i.classList.toggle('dirty', i.value !== i.dataset.o); mexHint(); });
// closers: só conta o que foi preenchido; equipes/geral: apagar um campo também é uma alteração (volta ao automático)
function mexDirty(){ const g = mexG(); return [...msEl('mexInputs').querySelectorAll('input')].filter(i => i.value !== i.dataset.o && (g || i.value !== '')); }
function mexHint(){
  const d = MEX.data, alvo = msEl('mexAlvo').value, h = msEl('mexHint');
  if (mexG()) {
    if (alvo === 'todos') { h.textContent = 'Vale para todas as ' + d.total + ' equipes: cada campo preenchido vira meta fixa em todas elas. Campo vazio não muda nada. Um valor igual à soma de uma equipe volta a ser automático para ela.'; return; }
    const it = d.itens.find(x => String(x.id) === String(alvo));
    if (!it) { h.textContent = 'Escolha a equipe que terá meta fixa neste mês.'; return; }
    h.textContent = 'Campo vazio = automático (soma das metas dos ' + (MS.scope === 'equipe' ? 'closers da equipe' : 'closers ativos') + '). Só os campos preenchidos viram meta fixa. Para voltar tudo ao automático, use “Remover Exceção”.'
      + (it.legado ? ' Esta equipe tem uma meta fixa antiga do cadastro (semanal ' + it.legado.sem + ' · mensal ' + it.legado.mes + ', valendo em todos os meses): ao salvar, ela vira meta fixa deste mês e os outros meses voltam à soma automática.' : '');
    return;
  }
  if (!alvo) { h.textContent = 'Escolha para quem vale a meta: um closer (exceção) ou todos os closers (muda o padrão).'; return; }
  if (alvo === 'todos') {
    const cels = mexDirty().map(i => +i.dataset.c);
    const afetados = d.closers.filter(c => c.excecao && cels.some(cel => c.efetivas[cel] !== d.padrao[cel])).length;
    h.textContent = 'Vale para todos os ' + d.total_closers + ' closers. Só os campos que você alterar mudam.' + (afetados ? ' ' + msPlural(afetados, 'closer com exceção nessas células será ajustado', 'closers com exceção nessas células serão ajustados') + ' também.' : '');
  } else h.textContent = 'Só os campos que você alterar viram exceção. Um valor igual à meta base do closer não cria exceção. Para voltar ao padrão, use “Remover Exceção”.';
}
async function mexSave(e){
  e.preventDefault();
  const g = mexG(), alvo = msEl('mexAlvo').value, err = msEl('mexErr'); err.textContent = '';
  if (!alvo) { err.textContent = g ? 'Escolha a equipe.' : 'Escolha para quem vale a meta.'; return false; }
  const metas = {}; let bad = false;
  mexDirty().forEach(i => {
    if (g && i.value === '') { metas[i.dataset.c] = null; return; }
    const v = Number(i.value); if (!Number.isInteger(v) || v < 1) bad = true; metas[i.dataset.c] = v;
  });
  if (bad) { err.textContent = 'Use números inteiros maiores que zero.'; return false; }
  if (!Object.keys(metas).length) { err.textContent = 'Altere ao menos um valor.'; return false; }
  const btn = msEl('mexOk'); btn.disabled = true;
  const r = await tmApi(g ? { action:'save_meta_excecao_grupo', escopo:MS.scope, ano:mpYear, mes:mpMonth, alvo, metas:JSON.stringify(metas) }
                          : { action:'save_meta_excecao', ano:mpYear, mes:mpMonth, alvo, metas:JSON.stringify(metas) });
  if (!r.success) { err.textContent = r.message || 'Não foi possível salvar.'; btn.disabled = false; return false; }
  msEl('mexDlg').close(); toast(r.message); MEX.data = r; mexRender(); fetchData();
  return false;
}
async function mexRemove(id){
  const g = mexG(), mes = ptMonths[mpMonth - 1];
  let r;
  if (g) {
    const it = MEX.data.itens.find(x => x.id === id); if (!it) return;
    const msg = MS.scope === 'equipe' ? it.nome + ' volta a somar as metas dos próprios closers em ' + mes + '.' + (it.legado ? ' A meta fixa antiga do cadastro (valendo em todos os meses) também será removida.' : '')
                                     : 'A meta comercial volta a ser a soma das metas dos closers ativos em ' + mes + '.';
    if (!await tmConfirm('Remover exceção?', msg, 'Remover Exceção')) return;
    r = await tmApi({ action:'remove_meta_excecao_grupo', escopo:MS.scope, ano:mpYear, mes:mpMonth, alvo:id });
  } else {
    const c = MEX.data.closers.find(x => x.id === id); if (!c) return;
    if (!await tmConfirm('Remover exceção?', c.nome + ' volta a seguir a meta padrão de ' + mes + '.', 'Remover Exceção')) return;
    r = await tmApi({ action:'remove_meta_excecao', ano:mpYear, mes:mpMonth, closer_id:id });
  }
  if (!r.success) { toast(r.message || 'Não foi possível remover', true); return; }
  toast(r.message); MEX.data = r; mexRender(); fetchData();
}

// ══ 2. META DIÁRIA — template + exceções ═══════════════════════════════════
const MDI = { data:null, segs:[], seq:0, edit:null };
const MDI_NOMES = { crescente:'Crescente', uniforme:'Uniforme', personalizado:'Personalizado' };
// série dia → meta a partir dos intervalos (mesma regra do servidor: dias fora do mês são ignorados)
function mdiGerar(dias, segs){
  const out = {};
  (segs || []).forEach(s => { for (let d = +s.de; d <= +s.ate && d <= dias; d++) if (d >= 1) out[d] = +s.valor; });
  return out;
}
function mdiRuns(serie){
  const ks = Object.keys(serie).map(Number).sort((a, b) => a - b), runs = [];
  ks.forEach(d => { const l = runs[runs.length - 1]; if (l && l.v === serie[d] && l.ate === d - 1) l.ate = d; else runs.push({ de:d, ate:d, v:serie[d] }); });
  return runs;
}
const mdiSoma = s => Object.values(s).reduce((a, b) => a + (Number(b) || 0), 0);
function mdiSegsPadrao(dias, M){
  const v = Math.max(1, Math.round((M || dias) / dias)), c = [[1, 7], [8, 14], [15, 21], [22, dias]];
  return c.filter(x => x[0] <= dias).map(x => ({ de:x[0], ate:Math.min(x[1], dias), valor:v }));
}
// refresh = atualização depois de uma ação: não pisca (mantém a tela até chegar o dado novo) e preserva o template que você está vendo
async function mdiLoad(refresh){
  const seq = ++MDI.seq;
  if (!refresh) { msEl('mdiDist').innerHTML = '<div class="m-loading">Carregando...</div>'; msEl('mdiList').innerHTML = ''; }
  const r = await fetch(`${API_URL}?action=get_metas_diarias_estado&ano=${mpYear}&mes=${mpMonth}`).then(x => x.json()).catch(() => null);
  if (seq !== MDI.seq) return;
  if (!r || !r.success) { if (refresh) toast('Não foi possível atualizar as metas diárias.', true); else msEl('mdiDist').innerHTML = '<div class="m-loading">Não foi possível carregar as metas diárias.</div>'; return; }
  MDI.data = r;
  if (!refresh && r.aplicada && r.aplicada.tipo === 'personalizado') MDI.segs = (r.aplicada.segs || []).map(s => Object.assign({}, s));
  if (!MDI.segs.length || MDI.segsDias !== r.dias) { if (!(r.aplicada && r.aplicada.tipo === 'personalizado')) MDI.segs = mdiSegsPadrao(r.dias, r.padrao_mes); MDI.segsDias = r.dias; }
  mdiRender();
}
// a Meta Diária tem um único ritmo: os intervalos personalizados (o cartão em #mdiTpl é só a apresentação)
function mdiRender(){
  if (!MDI.data) return;
  mdiRenderDist(true); mdiRenderList();
}
function mdiRenderDist(full){
  const d = MDI.data, serie = mdiGerar(d.dias, MDI.segs);
  if (full) {
    msEl('mdiDist').innerHTML = '<div class="ms-dist-h"><div><h3>Intervalos do mês</h3><p>Os intervalos valem igual para <b>todos os closers</b> (contratos por dia, em número absoluto). Dias fora dos intervalos ficam sem meta.</p></div><span class="ms-chip" id="mdiSomaChip"></span></div>'
      + '<div class="ms-segs" id="mdiSegs"></div><div class="ms-err" id="mdiSegErr" role="alert"></div><button type="button" class="btn btn-s btn-sm" onclick="mdiSegAdd()" style="margin-bottom:14px">+ Adicionar intervalo</button>'
      + '<div id="mdiBars"></div><div class="ms-runs" id="mdiRuns"></div><div class="ms-foot" id="mdiFoot"></div>';
    mdiRenderSegs();
    const apl = d.aplicada, quando = apl && apl.aplicado_em ? ' · aplicado em ' + esc(apl.aplicado_em.slice(8, 10) + '/' + apl.aplicado_em.slice(5, 7) + ' às ' + apl.aplicado_em.slice(11, 16)) : '';
    const igual = !!apl && apl.tipo === 'personalizado' && JSON.stringify((apl.segs || []).map(s => [+s.de, +s.ate, +s.valor])) === JSON.stringify(MDI.segs.map(s => [+s.de, +s.ate, +s.valor]));
    const status = !apl
      ? (d.comum ? 'Sem template aplicado neste mês: <b>' + msPlural(d.comum.n, 'closer tem', 'closers têm') + '</b> a mesma distribuição cadastrada.' : 'Nenhum template aplicado neste mês.')
      : apl.tipo === 'personalizado'
        ? 'Em vigor: <b>intervalos personalizados</b>' + quando + (igual ? '' : '. <span style="color:#fbbf24">Você alterou os intervalos: ainda não aplicados.</span>')
        : 'Em vigor: template <b>' + MDI_NOMES[apl.tipo] + '</b>' + quando + '. <span style="color:#fbbf24">Aplicar os intervalos acima substitui este template.</span>';
    msEl('mdiFoot').innerHTML = '<div class="st">' + status + '</div><button type="button" class="btn btn-p" id="mdiApply" onclick="mdiAplicar()"' + (d.total_closers ? '' : ' disabled') + '>Aplicar a todos os closers</button>';
  }
  const bars = msEl('mdiBars'), runs = msEl('mdiRuns'), chip = msEl('mdiSomaChip');
  if (!Object.keys(serie).length) { bars.innerHTML = ''; runs.innerHTML = '<span style="font-size:12px;color:var(--muted)">Nenhum intervalo definido ainda.</span>'; chip.innerHTML = ''; return; }
  bars.innerHTML = mdiBarsSvg(serie, d.dias, 640, 130, true);
  runs.innerHTML = mdiRuns(serie).map(r => '<div class="ms-run"><small>' + (r.de === r.ate ? 'Dia ' + r.de : 'Dias ' + r.de + '–' + r.ate) + '</small> <b>' + r.v + ' <span>contrato' + (r.v === 1 ? '' : 's') + '/dia</span></b></div>').join('');
  chip.innerHTML = 'Soma por closer <b>' + mdiSoma(serie) + '</b>';
}
function mdiBarsSvg(serie, dias, W, H, labels){
  const max = Math.max(1, ...Object.values(serie).map(Number)), padT = labels ? 16 : 2, padB = labels ? 16 : 2, bw = W / dias, ch = H - padT - padB;
  let s = '<svg class="' + (labels ? 'ms-bars' : 'ms-mini') + '" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="Meta por dia do mês">';
  for (let d = 1; d <= dias; d++) {
    const v = serie[d], x = (d - 1) * bw, we = [0, 6].includes(new Date(mpYear, mpMonth - 1, d).getDay());
    if (v === undefined) { s += '<rect x="' + (x + bw * .2).toFixed(1) + '" y="' + (padT + ch - 1.5) + '" width="' + (bw * .6).toFixed(1) + '" height="1.5" rx=".7" fill="rgba(255,255,255,.12)"/>'; }
    else { const h = Math.max(1.5, v / max * ch); s += '<rect x="' + (x + bw * .15).toFixed(1) + '" y="' + (padT + ch - h).toFixed(1) + '" width="' + (bw * .7).toFixed(1) + '" height="' + h.toFixed(1) + '" rx="2" fill="' + (v === 0 ? 'rgba(255,255,255,.14)' : we ? 'rgba(212,175,55,.5)' : '#d4af37') + '"><title>Dia ' + d + ': ' + v + '</title></rect>'; if (labels && bw > 14 && v > 0) s += '<text x="' + (x + bw / 2).toFixed(1) + '" y="' + (padT + ch - h - 4).toFixed(1) + '" text-anchor="middle" font-size="9.5" font-weight="800" fill="#f3f3f3">' + v + '</text>'; }
    if (labels && (d === 1 || d % 5 === 0 || d === dias)) s += '<text x="' + (x + bw / 2).toFixed(1) + '" y="' + (H - 3) + '" text-anchor="middle" font-size="9" fill="rgba(255,255,255,.45)">' + d + '</text>';
  }
  return s + '</svg>';
}
// editor dos intervalos (template Personalizado)
function mdiRenderSegs(){
  msEl('mdiSegs').innerHTML = MDI.segs.map((s, i) => '<div class="ms-seg" data-i="' + i + '"><span>Dias</span><input class="inp" type="number" min="1" max="' + MDI.data.dias + '" data-f="de" value="' + s.de + '" aria-label="Dia inicial"><span>a</span><input class="inp" type="number" min="1" max="' + MDI.data.dias + '" data-f="ate" value="' + s.ate + '" aria-label="Dia final"><span>meta</span><input class="inp" type="number" min="0" max="999" data-f="valor" value="' + s.valor + '" aria-label="Contratos por dia"><span>por dia</span><button type="button" data-rm aria-label="Remover intervalo">' + msX + '</button></div>').join('');
}
function mdiSegAdd(){
  const d = MDI.data, ult = MDI.segs.reduce((m, s) => Math.max(m, +s.ate || 0), 0), de = Math.min(d.dias, ult + 1);
  MDI.segs.push({ de, ate:d.dias, valor:MDI.segs.length ? MDI.segs[MDI.segs.length - 1].valor : 1 }); mdiRenderSegs(); mdiRenderDist(false);
}
msEl('mdiDist').addEventListener('input', e => {
  const i = e.target, row = i.closest('.ms-seg'); if (!row || !i.dataset.f) return;
  MDI.segs[+row.dataset.i][i.dataset.f] = i.value === '' ? '' : +i.value;
  const er = msEl('mdiSegErr'); if (er) er.textContent = '';
  mdiRenderDist(false);
});
msEl('mdiDist').addEventListener('click', e => {
  const b = e.target.closest('[data-rm]'); if (!b) return;
  MDI.segs.splice(+b.closest('.ms-seg').dataset.i, 1); mdiRenderSegs(); mdiRenderDist(false);
});
function mdiValidaSegs(segs, dias){
  if (!segs.length) return 'Defina ao menos um intervalo de dias.';
  const usado = {};
  for (const s of segs) {
    const de = +s.de, ate = +s.ate;
    if (!(de >= 1) || !(ate >= de) || ate > dias) return 'Intervalo inválido: use dias entre 1 e ' + dias + ', com o início antes do fim.';
    if (s.valor === '' || !(+s.valor >= 0) || +s.valor > 999) return 'Informe a meta por dia (0 a 999) em cada intervalo.';
    for (let d = de; d <= ate; d++) { if (usado[d]) return 'Os intervalos não podem se sobrepor (dia ' + d + ').'; usado[d] = true; }
  }
  return '';
}
async function mdiAplicar(){
  const d = MDI.data; if (!d) return;
  const er = mdiValidaSegs(MDI.segs, d.dias);
  if (er) { msEl('mdiSegErr').textContent = er; return; }
  const segs = MDI.segs.map(s => ({ de:+s.de, ate:+s.ate, valor:+s.valor }));
  if (d.excecoes_n > 0 && !await tmConfirm('Aplicar a todos os closers?', msPlural(d.excecoes_n, 'closer tem', 'closers têm') + ' uma meta diária diferente do template (exceção). Ao aplicar, as metas diárias de todos os closers de ' + ptMonths[mpMonth - 1] + ' serão substituídas.', 'Aplicar a todos')) return;
  const btn = msEl('mdiApply'); btn.disabled = true;
  const r = await tmApi({ action:'aplicar_meta_diaria', ano:mpYear, mes:mpMonth, tipo:'personalizado', segs:JSON.stringify(segs) });
  if (!r.success) { toast(r.message || 'Não foi possível aplicar', true); btn.disabled = false; return; }
  toast(r.message); await mdiLoad(true); fetchData();
}
function mdiRenderList(){
  const d = MDI.data, exc = d.closers.filter(c => c.excecao);
  msEl('mdiH').innerHTML = 'Exceções <small>' + (exc.length ? msPlural(exc.length, 'closer', 'closers') + ' com meta diária diferente do template' : 'nenhuma') + '</small>';
  const MOT = { diferente:'Diferente do template', sem_meta:'Sem meta diária', sem_template:'Meta própria' };
  if (!exc.length) {
    const vazio = !d.aplicada && !d.comum && d.closers.every(c => !c.tem_serie);
    msEl('mdiList').innerHTML = vazio
      ? '<div class="ms-empty neutro">' + msInfo + '<div>Nenhum template aplicado neste mês.<small>Escolha um ritmo acima e clique em “Aplicar a todos os closers”.</small></div></div>'
      : '<div class="ms-empty">' + msOk + '<div>Todos os closers seguem o template. Nenhuma exceção configurada.<small>Se um closer precisar de um ritmo próprio (férias, novato…), ele aparece aqui depois de editado.</small></div></div>';
    return;
  }
  msEl('mdiList').innerHTML = exc.map(c => {
    const ser = c.serie || {}, soma = mdiSoma(ser), esp = c.esperada ? mdiSoma(c.esperada) : null;
    const info = c.tem_serie ? 'Soma <b>' + soma + '</b>' + (esp !== null ? ' · template ' + esp : '') + ' · meta do mês ' + c.meta_mes : 'Sem nenhuma meta diária cadastrada · meta do mês ' + c.meta_mes;
    return '<article class="ms-card" data-id="' + c.id + '" style="flex-wrap:wrap"><div class="tm-av">' + esc(tmInitials(c.nome)) + '</div>'
      + '<div class="ms-card-main"><div class="tm-name" title="' + esc(c.nome) + '">' + esc(c.nome) + '<span class="ms-motivo">' + MOT[c.motivo] + '</span></div><div class="ms-card-sub">' + esc(c.equipe_nome || 'Sem equipe') + (c.ferias ? ' · em férias' : '') + '</div><div class="ms-card-sub" style="margin-top:6px">' + info + '</div></div>'
      + '<div class="ms-card-b"><button type="button" class="btn btn-s btn-sm" data-act="edit">' + TM_IC.edit + ' Editar</button><button type="button" class="btn btn-d btn-sm" data-act="rm">Remover Exceção</button></div>'
      + (c.tem_serie ? '<div style="flex-basis:100%">' + mdiBarsSvg(ser, d.dias, 640, 38, false) + '</div>' : '') + '</article>';
  }).join('');
}
msEl('mdiList').addEventListener('click', e => {
  const b = e.target.closest('[data-act]'); if (!b) return;
  const id = +b.closest('.ms-card').dataset.id;
  if (b.dataset.act === 'edit') mdiOpen(id); else mdiRemove(id);
});
async function mdiRemove(id){
  const c = MDI.data.closers.find(x => x.id === id); if (!c) return;
  const volta = MDI.data.aplicada ? (MDI.data.aplicada.tipo === 'personalizado' ? 'volta aos intervalos do mês' : 'volta à distribuição do template ' + MDI_NOMES[MDI.data.aplicada.tipo]) : (MDI.data.comum ? 'passa a ter a mesma distribuição dos demais' : 'fica sem metas diárias neste mês');
  if (!await tmConfirm('Remover exceção?', c.nome + ' ' + volta + '.', 'Remover Exceção')) return;
  const r = await tmApi({ action:'remove_meta_diaria_excecao', ano:mpYear, mes:mpMonth, closer_id:id });
  if (!r.success) { toast(r.message || 'Não foi possível remover', true); return; }
  toast(r.message); await mdiLoad(true); fetchData();
}
// editar as metas diárias de um closer (dia a dia)
function mdiOpen(id){
  const d = MDI.data, c = d.closers.find(x => x.id === id); if (!c) return;
  MDI.edit = c;
  msEl('mdiDlgT').textContent = c.nome;
  msEl('mdiQaTpl').disabled = !c.esperada;
  msEl('mdiErr').textContent = ''; msEl('mdiOk').disabled = false;
  const h = [];
  for (let dia = 1; dia <= d.dias; dia++) {
    const v = c.serie[dia], wd = new Date(mpYear, mpMonth - 1, dia).getDay();
    h.push('<div class="ms-day' + ([0, 6].includes(wd) ? ' we' : '') + '"><small>' + dia + ' · ' + MS_DOW[wd] + '</small><input class="inp" type="number" min="0" max="999" inputmode="numeric" data-d="' + dia + '" data-o="' + (v === undefined ? '' : v) + '" value="' + (v === undefined ? '' : v) + '" aria-label="Dia ' + dia + '"></div>');
  }
  msEl('mdiDays').innerHTML = h.join('');
  mdiSomaUpd();
  msEl('mdiDlg').showModal();
  msEl('mdiDays').querySelector('input').focus();
}
function mdiSomaUpd(){
  const ins = [...msEl('mdiDays').querySelectorAll('input')], soma = ins.reduce((s, i) => s + (i.value === '' ? 0 : +i.value || 0), 0), c = MDI.edit;
  msEl('mdiSoma').innerHTML = 'Soma <b>' + soma + '</b> · meta do mês ' + c.meta_mes;
  ins.forEach(i => i.classList.toggle('dirty', i.value !== i.dataset.o));
}
msEl('mdiDays').addEventListener('input', mdiSomaUpd);
function mdiQa(kind){
  const c = MDI.edit; if (!c) return;
  msEl('mdiDays').querySelectorAll('input').forEach(i => { i.value = kind === 'zero' ? '0' : kind === 'clear' ? '' : (c.esperada && c.esperada[i.dataset.d] !== undefined ? String(c.esperada[i.dataset.d]) : ''); });
  mdiSomaUpd();
}
async function mdiSave(e){
  e.preventDefault();
  const c = MDI.edit, dias = {}; let bad = false;
  msEl('mdiDays').querySelectorAll('input').forEach(i => { if (i.value === '') return; const v = Number(i.value); if (!Number.isInteger(v) || v < 0 || v > 999) bad = true; dias[i.dataset.d] = v; });
  if (bad) { msEl('mdiErr').textContent = 'Use números inteiros de 0 a 999.'; return false; }
  const btn = msEl('mdiOk'); btn.disabled = true;
  const r = await tmApi({ action:'save_meta_diaria_closer', ano:mpYear, mes:mpMonth, closer_id:c.id, dias:JSON.stringify(dias) });
  if (!r.success) { msEl('mdiErr').textContent = r.message || 'Não foi possível salvar.'; btn.disabled = false; return false; }
  msEl('mdiDlg').close(); toast(r.message); await mdiLoad(true); fetchData();
  return false;
}

// ── Critérios de desempate: padrão e rótulo (o Ranking usa os dois) ──
const DEFAULT_TB_ORDER = ['aux_acidente','bpc_loas','sal_maternidade','aux_doenca','outros'];
let tbOrder = [];
function tbLabel(entry){
  if (typeof entry === 'string') return entry === 'conv_rate' ? 'Taxa de Conversão' : (TIPOS_MAP[entry] || entry);
  if (entry && entry.grupo) return entry.label || entry.grupo.map(t => TIPOS_MAP[t] || t).join(' + ');
  return '?';
}

// ══ 3. CRITÉRIOS DE DESEMPATE — prévia ao vivo ═════════════════════════════
const TBC = { dirty:false, saved:'[]', editGrp:null };
// Prévia com dados fixos de exemplo (não são closers reais): todos com 10/10 contratos (100%), para o desempate decidir
const TBC_MOCK = [
  { n:'Camila Nunes',  t:{ aux_acidente:5, bpc_loas:1, sal_maternidade:2, aux_doenca:2 }, conv:27.0 },
  { n:'Marina Costa',  t:{ aux_acidente:4, bpc_loas:5, sal_maternidade:0, aux_doenca:1 }, conv:31.0 },
  { n:'Lucas Ferraz',  t:{ aux_acidente:4, bpc_loas:2, sal_maternidade:1, aux_doenca:3 }, conv:22.5 },
  { n:'Diego Alves',   t:{ aux_acidente:3, bpc_loas:3, sal_maternidade:4, aux_doenca:5 }, conv:24.0 },
  { n:'Rafael Lima',   t:{ aux_acidente:2, bpc_loas:6, sal_maternidade:3, aux_doenca:4 }, conv:18.0 }
];
function tbcMockVal(r, key){
  if (r.t[key] !== undefined) return r.t[key];
  let h = 0; for (const ch of r.n + '|' + key) h = (h * 31 + ch.charCodeAt(0)) >>> 0;     // tipos sem valor fixo: número estável (0–6)
  return h % 7;
}
function tbcCrit(r, entry){
  if (entry && entry.grupo) return entry.grupo.reduce((s, k) => s + tbcMockVal(r, k), 0);
  if (entry === 'conv_rate') return r.conv;
  return tbcMockVal(r, entry);
}
const tbcSortMock = () => [...TBC_MOCK].sort((a, b) => { for (const e of tbOrder) { const va = tbcCrit(a, e), vb = tbcCrit(b, e); if (Math.abs(vb - va) > 0.001) return vb - va; } return a.n.localeCompare(b.n, 'pt-BR'); });
function tbcDecisor(a, b){ for (let i = 0; i < tbOrder.length; i++) if (Math.abs(tbcCrit(a, tbOrder[i]) - tbcCrit(b, tbOrder[i])) > 0.001) return i; return -1; }
function tbcVis(entry){   // cor e ícone do critério (os do benefício, quando é um tipo)
  if (entry && entry.grupo) return { cor:'#d4af37', svg:tpSvg('star') };
  if (entry === 'conv_rate') return { cor:'#a78bfa', svg:tpSvg('activity') };
  return { cor:tpCor(entry), svg:tpIcon(entry) };
}
const tbcFmt = (entry, v) => entry === 'conv_rate' ? String(v).replace('.', ',') + '%' : v;
async function tbcLoad(){
  try { const d = await fetch(API_URL+'?action=get_tiebreaker').then(r => r.json()); tbOrder = (d.success && Array.isArray(d.data)) ? d.data : [...DEFAULT_TB_ORDER]; }
  catch(e) { tbOrder = [...DEFAULT_TB_ORDER]; }
  TBC.saved = JSON.stringify(tbOrder); TBC.dirty = false; tbcRender();
}
function tbcMark(){ TBC.dirty = JSON.stringify(tbOrder) !== TBC.saved; tbcRender(); }
function tbcRender(){ tbcRenderPreview(); tbcRenderList(); msEl('tbcDirty').hidden = !TBC.dirty; msEl('tbcSave').disabled = !TBC.dirty; }
function tbcRenderPreview(){
  const rows = tbcSortMock(), cols = tbOrder;
  const th = cols.map(e => { const v = tbcVis(e); return '<th style="--tc:' + esc(v.cor) + '"><i></i>' + esc(tbLabel(e)) + '</th>'; }).join('');
  const body = rows.map((r, i) => {
    const dec = i < rows.length - 1 ? tbcDecisor(r, rows[i + 1]) : -2;
    return '<tr class="' + (i === 0 ? 'p1' : '') + '"><td class="pos">' + (i + 1) + 'º</td><td class="who l">' + esc(r.n) + '<small>10/10 contratos · 100% da meta</small></td>'
      + cols.map((e, j) => '<td' + (j === dec ? ' class="dec" title="Este critério decidiu a posição em relação ao de baixo"' : '') + '>' + tbcFmt(e, tbcCrit(r, e)) + '</td>').join('') + '</tr>';
  }).join('');
  const semCrit = !cols.length;
  msEl('tbcPreview').innerHTML = '<div class="ms-prev-h"><h3>Prévia: como ficaria o Top 5 com esses critérios</h3><small>Dados de exemplo · todos com 100% da meta, então só o desempate decide</small></div>'
    + '<div class="ms-ptbl-w"><table class="ms-ptbl"><thead><tr><th>#</th><th class="l">Closer</th>' + th + '</tr></thead><tbody>' + body + '</tbody></table></div>'
    + '<div class="ms-ptbl-n">' + (semCrit ? 'Sem critérios: o desempate cai direto na <b>ordem alfabética</b>.' : 'Célula <b>verde</b> = critério que decidiu a posição em relação ao closer logo abaixo. Se todos empatarem, vale a ordem alfabética.') + '</div>';
}
function tbcRenderList(){
  const list = msEl('tbcList');
  if (!tbOrder.length) { list.innerHTML = '<div class="ms-empty neutro">' + msInfo + '<div>Nenhum critério definido.<small>O desempate cai direto para a ordem alfabética. Use “Adicionar Critério”.</small></div></div>'; return; }
  list.innerHTML = tbOrder.map((e, i) => {
    const v = tbcVis(e), grp = e && typeof e === 'object' && e.grupo, tag = grp ? '<small>Grupo · ' + e.grupo.length + ' tipos</small>' : (e === 'conv_rate' ? '<small>Conversão</small>' : '');
    return '<div class="tbc-item" style="--tc:' + esc(v.cor) + '" data-i="' + i + '"><span class="tbc-num">' + (i + 1) + '</span><span class="tbc-ic">' + v.svg + '</span>'
      + '<span class="tbc-label" title="' + esc(tbLabel(e)) + '">' + esc(tbLabel(e)) + tag + '</span>'
      + '<span class="tbc-btns"><button type="button" data-a="up" aria-label="Subir"' + (i === 0 ? ' disabled' : '') + '>' + msUp + '</button><button type="button" data-a="down" aria-label="Descer"' + (i === tbOrder.length - 1 ? ' disabled' : '') + '>' + msDown + '</button>'
      + (grp ? '<button type="button" data-a="edit" aria-label="Editar grupo">' + TM_IC.edit + '</button>' : '') + '<button type="button" class="d" data-a="rm" aria-label="Remover critério">' + msX + '</button></span></div>';
  }).join('');
}
msEl('tbcList').addEventListener('click', e => {
  const b = e.target.closest('[data-a]'); if (!b) return;
  const i = +b.closest('.tbc-item').dataset.i, a = b.dataset.a;
  if (a === 'up' || a === 'down') { const j = i + (a === 'up' ? -1 : 1); if (j < 0 || j >= tbOrder.length) return; [tbOrder[i], tbOrder[j]] = [tbOrder[j], tbOrder[i]]; tbcMark(); const nb = msEl('tbcList').querySelector('[data-i="' + j + '"] [data-a="' + a + '"]'); if (nb && !nb.disabled) nb.focus(); }
  else if (a === 'rm') { tbOrder.splice(i, 1); tbcMark(); }
  else if (a === 'edit') tbcAddOpen(i);
});
function tbcAddOpen(editIdx){
  TBC.editGrp = (typeof editIdx === 'number') ? editIdx : null;
  const used = new Set(tbOrder.filter(e => typeof e === 'string')), editing = TBC.editGrp !== null;
  msEl('tbcAddT').textContent = editing ? 'Editar grupo unificado' : 'Adicionar critério';
  msEl('tbcAddSimple').hidden = editing; msEl('tbcGrpSep').hidden = editing;
  const opts = TIPOS.filter(t => !used.has(t)).map(t => ({ v:t, l:TIPOS_MAP[t] || t })).concat(used.has('conv_rate') ? [] : [{ v:'conv_rate', l:'Taxa de Conversão' }]);
  msEl('tbcAvail').innerHTML = opts.length ? opts.map(o => { const v = tbcVis(o.v); return '<button type="button" data-k="' + esc(o.v) + '" style="--tc:' + esc(v.cor) + '">' + v.svg + esc(o.l) + '</button>'; }).join('') : '<span style="font-size:12px;color:var(--muted)">Todos os critérios simples já estão na lista.</span>';
  const sel = new Set(editing ? tbOrder[TBC.editGrp].grupo : []);
  msEl('tbcGrpChecks').innerHTML = TIPOS.map(t => '<label><input type="checkbox" value="' + esc(t) + '"' + (sel.has(t) ? ' checked' : '') + '>' + tpIcon(t) + esc(TIPOS_MAP[t] || t) + '</label>').join('');
  msEl('tbcGrpLabel').value = editing ? (tbOrder[TBC.editGrp].label || '') : '';
  msEl('tbcGrpErr').textContent = ''; msEl('tbcGrpOk').textContent = editing ? 'Salvar grupo' : 'Criar grupo';
  msEl('tbcAddDlg').showModal();
}
msEl('tbcAvail').addEventListener('click', e => { const b = e.target.closest('[data-k]'); if (!b) return; tbOrder.push(b.dataset.k); msEl('tbcAddDlg').close(); tbcMark(); });
function tbcGrpSave(){
  const sel = [...msEl('tbcGrpChecks').querySelectorAll('input:checked')].map(c => c.value);
  if (sel.length < 2) { msEl('tbcGrpErr').textContent = 'Selecione ao menos 2 tipos para o grupo.'; return; }
  const label = msEl('tbcGrpLabel').value.trim(), entry = { grupo:sel }; if (label) entry.label = label;
  if (TBC.editGrp !== null) tbOrder[TBC.editGrp] = entry; else tbOrder.push(entry);
  msEl('tbcAddDlg').close(); tbcMark();
}
async function tbcReset(){
  if (!await tmConfirm('Restaurar padrão?', 'A lista volta ao padrão (Aux. Acidente, BPC/LOAS, Salário Maternidade, Aux. Doença, Outros). Só vale depois de clicar em Salvar Critérios.', 'Restaurar')) return;
  tbOrder = [...DEFAULT_TB_ORDER]; tbcMark();
}
async function tbcSave(){
  const btn = msEl('tbcSave'); btn.disabled = true;
  const d = await tmApi({ action:'save_tiebreaker', order:JSON.stringify(tbOrder) });
  if (!d.success) { toast(d.message || 'Não foi possível salvar', true); btn.disabled = false; return; }
  toast(d.message); TBC.saved = JSON.stringify(tbOrder); TBC.dirty = false; try { rkTb = null; } catch(e) {} tbcRender();
}

// ══ 4. TIPOS DE BENEFÍCIO — cor, ícone e ordem ═════════════════════════════
const TPB = { cfg:null, item:null, cor:null, icone:null, ativo:true, seq:0 };
const TPB_SW = ['#60a5fa','#34d399','#fbbf24','#fb7185','#f472b6','#a78bfa','#fb923c','#a3e635','#2dd4bf','#38bdf8','#e879f9','#94a3b8'];
// mesmo critério do servidor (tipoIconeDefault) — só para a prévia enquanto você digita o nome
function tpIconeDefault(label){
  const s = tmNorm(label);
  for (const [p, ic] of [['bpc','heart'],['loas','heart'],['aposent','sun'],['acidente','alert'],['doenc','activity'],['matern','smile'],['pens','users'],['invalid','shield'],['rural','home']]) if (s.includes(p)) return ic;
  return 'file';
}
function tiposApply(cfg){   // atualiza as listas globais (TIPOS, cores, ícones) sem recarregar a página
  const all = [...cfg.fixos, ...cfg.custom].sort((a, b) => a.ordem - b.ordem);
  TIPOS = all.filter(t => t.ativo).map(t => t.chave); TIPO_LABELS = all.filter(t => t.ativo).map(t => t.label);
  TIPOS_MAP = {}; TIPO_CORES = {}; TIPO_ICONS = {};
  all.forEach(t => { TIPOS_MAP[t.chave] = t.label; TIPO_CORES[t.chave] = t.cor; TIPO_ICONS[t.chave] = t.icone; });
}
async function tpbLoad(){
  const seq = ++TPB.seq;
  const r = await fetch(API_URL+'?action=get_tipos_config').then(x => x.json()).catch(() => null);
  if (seq !== TPB.seq) return;
  if (!r || !r.success) { msEl('tpbFixos').innerHTML = '<div class="m-loading">Não foi possível carregar os tipos.</div>'; return; }
  TPB.cfg = r; tiposApply(r); tpbRender();
}
function tpbCard(t){
  const c = !t.fixo;
  return '<article class="tpb-card' + (t.ativo ? '' : ' off') + '" style="--tc:' + esc(t.cor) + '" data-k="' + esc(t.chave) + '">'
    + '<div class="tpb-ic">' + tpSvg(t.icone) + '<i title="Cor ' + esc(t.cor) + '"></i></div>'
    + '<div class="tpb-main"><b title="' + esc(t.label) + '">' + esc(t.label) + '</b><small>Ordem <em>' + t.ordem + '</em> · cor ' + esc(t.cor) + '</small></div>'
    + '<div class="tpb-b">' + (c ? '<button type="button" class="tpb-st" role="switch" aria-checked="' + t.ativo + '" data-act="tog" aria-label="Ativo"><i></i>' + (t.ativo ? 'Ativo' : 'Inativo') + '</button>' : '')
    + '<div class="row"><button type="button" class="btn btn-s btn-sm" data-act="edit">' + TM_IC.edit + ' Editar</button>' + (c ? '<button type="button" class="btn btn-d btn-sm" data-act="del">Remover</button>' : '') + '</div></div></article>';
}
function tpbRender(){
  const cfg = TPB.cfg;
  msEl('tpbFixos').innerHTML = cfg.fixos.map(tpbCard).join('');
  msEl('tpbCustom').innerHTML = cfg.custom.length ? cfg.custom.map(tpbCard).join('') : '<div class="ms-empty neutro" style="grid-column:1/-1">' + msInfo + '<div>Nenhum tipo extra cadastrado.<small>Use “Adicionar Novo Tipo” para criar benefícios como Pensão por Morte ou Aposentadoria por Invalidez.</small></div></div>';
}
function tpbFind(k){ return [...TPB.cfg.fixos, ...TPB.cfg.custom].find(t => t.chave === k); }
['tpbFixos', 'tpbCustom'].forEach(id => msEl(id).addEventListener('click', e => {
  const b = e.target.closest('[data-act]'); if (!b) return;
  const t = tpbFind(b.closest('.tpb-card').dataset.k); if (!t) return;
  if (b.dataset.act === 'edit') tpbOpen(t); else if (b.dataset.act === 'del') tpbDel(t); else tpbToggle(t);
}));
async function tpbToggle(t){
  const d = await tmApi({ action:'save_tipo_custom', id:t.id, label:t.label, ativo:t.ativo ? 0 : 1 });
  if (!d.success) { toast(d.message || 'Não foi possível alterar', true); return; }
  toast(t.ativo ? '“' + t.label + '” desativado' : '“' + t.label + '” ativado'); tpbLoad();
}
async function tpbDel(t){
  if (!await tmConfirm('Remover tipo?', '“' + t.label + '” deixa de existir para novos lançamentos. Os contratos já lançados com ele continuam nos números.', 'Remover')) return;
  const d = await tmApi({ action:'delete_tipo_custom', id:t.id });
  if (!d.success) { toast(d.message || 'Não foi possível remover', true); return; }
  toast(d.message); tpbLoad();
}
function tpbOpen(t){
  const cfg = TPB.cfg; if (!cfg) return;
  TPB.item = t; const fixo = !!(t && t.fixo), outros = !!(t && t.chave === 'outros'), total = cfg.fixos.length + cfg.custom.length;
  TPB.cor = t ? (t.cor_escolhida ? t.cor : null) : (TPB_SW.find(c => ![...cfg.fixos, ...cfg.custom].some(x => x.cor === c)) || TPB_SW[0]);
  TPB.icone = t && t.icone_escolhido ? t.icone : null; TPB.ativo = t ? t.ativo : true;
  msEl('tpbDlgT').textContent = t ? (fixo ? t.label : 'Editar tipo') : 'Novo tipo';
  msEl('tpbEyebrow').textContent = fixo ? 'Benefício padrão' : 'Benefício customizável';
  msEl('tpbNome').value = t ? t.label : ''; msEl('tpbNome').disabled = fixo;
  msEl('tpbOrdem').value = t ? t.ordem : total; msEl('tpbOrdem').disabled = outros; msEl('tpbOrdem').max = t ? total - 1 : total;
  msEl('tpbAtivoBox').hidden = fixo;
  msEl('tpbHint').textContent = outros ? '“Outros” é sempre o último da lista: cor e ícone podem mudar, a posição não.' : (fixo ? 'Benefício padrão: o nome não muda, mas cor, ícone e posição valem em todo o sistema.' : 'Cor, ícone e posição aparecem nos lançamentos, na Matriz, no Painel e no Portal do Closer.');
  msEl('tpbErr').textContent = ''; msEl('tpbOk').disabled = false;
  msEl('tpbSw').innerHTML = TPB_SW.map(c => '<button type="button" style="background:' + c + '" data-c="' + c + '" aria-label="Cor ' + c + '" aria-pressed="false"></button>').join('');
  msEl('tpbIcons').innerHTML = Object.keys(TIPO_ICONES).map(k => '<button type="button" data-i="' + k + '" aria-label="Ícone ' + k + '" aria-pressed="false">' + tpSvg(k) + '</button>').join('');
  tpbSync(); msEl('tpbDlg').showModal(); (fixo ? msEl('tpbCorHex') : msEl('tpbNome')).focus();
}
function tpbShownCor(){ const t = TPB.item; return TPB.cor || (t ? t.cor : '#94a3b8'); }
function tpbSync(){
  const cor = tpbShownCor(), ico = TPB.icone || (TPB.item && TPB.item.fixo ? TPB.item.icone : tpIconeDefault(msEl('tpbNome').value || (TPB.item ? TPB.item.label : '')));
  msEl('tpbCor').value = /^#[0-9a-f]{6}$/i.test(cor) ? cor : '#94a3b8'; msEl('tpbCorHex').value = TPB.cor || '';
  msEl('tpbCorHex').placeholder = TPB.cor ? '' : cor + ' (auto)';
  msEl('tpbSw').querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(!!TPB.cor && b.dataset.c === TPB.cor.toLowerCase())));
  msEl('tpbIcons').style.setProperty('--tc', cor);
  msEl('tpbIcons').querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.i === TPB.icone)));
  msEl('tpbCorAuto').hidden = !TPB.cor || !TPB.item; msEl('tpbIcoAuto').hidden = !TPB.icone;
  const at = msEl('tpbAtivo'); at.setAttribute('aria-checked', String(TPB.ativo)); at.querySelector('span').textContent = TPB.ativo ? 'Ativo' : 'Inativo';
  msEl('tpbPv').style.setProperty('--tc', cor);
  msEl('tpbPv').innerHTML = '<div class="tpb-ic">' + tpSvg(ico) + '<i></i></div><div class="tpb-main"><b>' + esc(msEl('tpbNome').value || (TPB.item ? TPB.item.label : 'Novo tipo')) + '</b><small>Ordem <em>' + esc(msEl('tpbOrdem').value || '–') + '</em> · cor ' + esc(cor) + '</small></div>';
}
msEl('tpbNome').addEventListener('input', tpbSync); msEl('tpbOrdem').addEventListener('input', tpbSync);
msEl('tpbSw').addEventListener('click', e => { const b = e.target.closest('[data-c]'); if (!b) return; TPB.cor = b.dataset.c; tpbSync(); });
msEl('tpbCor').addEventListener('input', e => { TPB.cor = e.target.value.toLowerCase(); tpbSync(); });
msEl('tpbCorHex').addEventListener('input', e => { let v = e.target.value.trim(); if (v && v[0] !== '#') v = '#' + v; if (/^#[0-9a-f]{6}$/i.test(v)) { TPB.cor = v.toLowerCase(); const c = msEl('tpbCor'); c.value = TPB.cor; msEl('tpbSw').querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.c === TPB.cor))); msEl('tpbIcons').style.setProperty('--tc', TPB.cor); msEl('tpbPv').style.setProperty('--tc', TPB.cor); msEl('tpbCorAuto').hidden = !TPB.item; } });
msEl('tpbIcons').addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (!b) return; TPB.icone = b.dataset.i; tpbSync(); });
function tpbAuto(w){ if (w === 'cor') TPB.cor = null; else TPB.icone = null; tpbSync(); }
function tpbToggleAtivo(){ TPB.ativo = !TPB.ativo; tpbSync(); }
async function tpbSave(e){
  e.preventDefault();
  const t = TPB.item, err = msEl('tpbErr'); err.textContent = '';
  const nome = msEl('tpbNome').value.trim();
  if (!t && !nome) { err.textContent = 'Informe o nome do tipo.'; msEl('tpbNome').focus(); return false; }
  if (!t || !t.fixo) { if (!nome) { err.textContent = 'Informe o nome do tipo.'; return false; } }
  const ordem = msEl('tpbOrdem').disabled ? '' : msEl('tpbOrdem').value;
  if (ordem !== '' && !(+ordem >= 1)) { err.textContent = 'A ordem deve ser 1 ou mais.'; return false; }
  const p = { cor:TPB.cor || '', icone:TPB.icone || '' };
  if (ordem !== '') p.ordem = ordem;
  if (t && t.fixo) { p.action = 'save_tipo_fixo'; p.chave = t.chave; }
  else { p.action = 'save_tipo_custom'; p.label = nome; p.ativo = TPB.ativo ? 1 : 0; if (t) p.id = t.id; }
  const btn = msEl('tpbOk'); btn.disabled = true;
  const d = await tmApi(p);
  if (!d.success) { err.textContent = d.message || 'Não foi possível salvar.'; btn.disabled = false; return false; }
  msEl('tpbDlg').close(); toast(d.message); await tpbLoad();
  if (MS.tab === 'desempate') tbcRender();
  return false;
}

/// ══════════════════════════════════════════════════════════════════════════
// RELATÓRIOS — "Performance de Closers" (aba padrão) e "Custo por Contrato"
// Os dados do mês chegam de uma vez (todas as semanas e o mix de benefícios): trocar de período, equipe ou
// benefício só redesenha a partir do estado local (pf), sem chamar o servidor. O PDF é gerado no servidor
// (api.php → pdf-simples.php); o CSV sai do mesmo estado, com a tabela completa do mês.
