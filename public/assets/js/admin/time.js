// ══════════════════════════════════════════════════════════════════════════
// TIME — equipes (esquerda) × closers (direita)
// ══════════════════════════════════════════════════════════════════════════
const TM_RISK_DAYS = 3;   // alerta "sem fechamentos há N dias" a partir de N dias corridos
const TM_CROWN = '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M3 7l4.5 4L12 4l4.5 7L21 7l-2 12H5z"/></svg>';
const TM_GRIP  = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>';
const TM_DOTS  = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>';
const TM_IC = {   // ícones do menu (SVG, herdam a cor do texto)
  edit:  '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
  move:  '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3l4 4-4 4"/><path d="M3 7h18"/><path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/></svg>',
  trash: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg>',
  back:  '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg>',
  to:    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>'
};
const TM_MOTIVOS = { demissao:'Demissão', transferencia:'Transferência', ferias_prolongadas:'Férias prolongadas' };

let tmSelTeam = '';                       // 'all' | 'none' | id da equipe
try { tmSelTeam = localStorage.getItem('tmTeam') || ''; } catch(e) {}
let tmFilter = 'all', tmSearch = '';
const tmSelected = new Set();             // ids de closers marcados (ação em lote)
let tmSuspended = [], tmDeleted = [];     // closers em férias / arquivados (vêm da API)
let tmLastSale = {}, tmFerias = {}, tmToday = '';
let tmMenuState = null;

const tmEl  = id => document.getElementById(id);
const tmNum = n => Number(n) || 0;
const tmNorm = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const tmDay = d => d ? d.slice(8,10) + '/' + d.slice(5,7) : '';
const tmDayFull = d => d ? d.slice(8,10) + '/' + d.slice(5,7) + '/' + d.slice(0,4) : '';
function tmTodayISO(){
  if (tmToday) return tmToday;
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}
function tmUTC(s){ const [y,m,d] = s.split('-').map(Number); return Date.UTC(y, m-1, d); }
function tmDiffDays(a, b){ return Math.round((tmUTC(b) - tmUTC(a)) / 86400000); }
function tmAddDays(iso, n){ return new Date(tmUTC(iso) + n * 86400000).toISOString().slice(0,10); }
function tmInitials(n){
  const p = String(n || '?').trim().split(/\s+/);
  return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length-1][0] : '')).toUpperCase();
}
// <img> do avatar; se o arquivo não existir (ou falhar), cai para as iniciais
function tmImg(src, ini){ return '<img src="' + esc(src) + '" alt="" draggable="false" loading="lazy" data-ini="' + esc(ini) + '" onerror="tmImgFail(this)">'; }
function tmImgFail(img){ const p = img.parentNode; if (p) p.textContent = img.dataset.ini || '?'; }
async function tmApi(params){
  try {
    const bd = new URLSearchParams();
    Object.entries(params).forEach(([k,v]) => bd.append(k, v));
    return await fetch(API_URL, { method:'POST', body:bd }).then(r => r.json());
  } catch(e) { return { success:false, message:'Erro de conexão' }; }
}
async function tmReloadAll(){
  await fetchData();
  await Promise.all([loadSuspendedClosers(), loadDeletedClosers(), tmLoadOverview(), tmLoadAcessos()]);
}

// ── Modelo: closers ativos (get_data) + em férias (get_suspended_vendedores) — só dados de cadastro ──
function tmCloserList(){
  const out = allElenco.map(v => ({
    id:Number(v.id), nome:v.name || '', equipe_id:v.equipe_id ? Number(v.equipe_id) : null,
    color:v.color || '#d4af37', imageUrl:v.imageUrl || null,
    vac:false, fer:tmFerias[v.id] || null }));
  tmSuspended.forEach(s => out.push({
    id:Number(s.id), nome:s.nome || '', equipe_id:s.equipe_id ? Number(s.equipe_id) : null,
    color:s.cor || '#d4af37', imageUrl:s.imageUrl || null,
    vac:true, fer:{ inicio:s.ferias_inicio || null, fim:s.ferias_fim || null, desde:(s.suspended_at || '').slice(0,10) } }));
  return out;
}
function tmScope(all){
  if (tmSelTeam === 'all' || tmSelTeam === '') return all;   // '' = seleção ainda pendente
  if (tmSelTeam === 'none') return all.filter(c => !c.equipe_id);
  return all.filter(c => String(c.equipe_id) === String(tmSelTeam));
}
function tmEnsureSel(all){
  // equipes ainda não carregaram (get_equipes mais lento que get_suspended/deleted no boot):
  // deixa a seleção pendente em vez de travar em "Todos" e perder o padrão "1ª equipe"
  if (!allEquipes.length) return;
  const first = allEquipes.length ? String(allEquipes[0].id) : 'all';
  const ok = tmSelTeam === 'all' || (tmSelTeam === 'none' && all.some(c => !c.equipe_id))
          || allEquipes.some(e => String(e.id) === String(tmSelTeam));
  if (!ok) tmSelTeam = first;
}

// ── Render ──────────────────────────────────────────────────────────────────
function tmRender(){
  if (!tmEl('tmTeams')) return;
  const all = tmCloserList();
  tmEnsureSel(all);
  [...tmSelected].forEach(id => { if (!all.some(c => c.id === id)) tmSelected.delete(id); });
  tmRenderTeams(all); tmRenderMain(all); tmRenderArchive(); shellRefresh();
}

function tmRenderTeams(all){
  tmEl('tmTeamCount').textContent = allEquipes.length;
  const semEq = all.filter(c => !c.equipe_id).length;
  let h = '<button type="button" class="tm-all' + (tmSelTeam === 'all' ? ' active' : '') + '" data-team="all">Todos os closers <span>' + all.length + '</span></button>';
  h += allEquipes.map(eq => {
    const act = String(tmSelTeam) === String(eq.id), n = eq.total_closers || 0;
    const av = eq.imageUrl ? tmImg(eq.imageUrl, (eq.nome || '?').charAt(0).toUpperCase()) : esc((eq.nome || '?').charAt(0).toUpperCase());
    const lead = eq.lider_nome
      ? '<div class="tm-lead">' + TM_CROWN + '<span>' + esc(eq.lider_nome) + '</span></div>'
      : '<div class="tm-lead none">' + TM_CROWN + '<span>Sem líder definido</span></div>';
    return '<div class="tm-team' + (act ? ' active' : '') + '" style="--tc:' + esc(eq.cor || '#d4af37') + '" data-team="' + eq.id + '" data-drop="' + eq.id + '" role="button" tabindex="0" aria-pressed="' + act + '">'
      + '<div class="tm-team-top"><div class="tm-team-av">' + av + '</div>'
      + '<div class="tm-team-id"><div class="tm-team-name" title="' + esc(eq.nome) + '">' + esc(eq.nome) + '</div>' + lead + (Number(eq.oculta_tv) === 1 ? '<div class="tm-tvoff" title="Esta equipe e os closers dela não aparecem na TV">Fora da TV</div>' : '')
      + '<div class="tm-team-stats"><span><b>' + n + '</b> closer' + (n === 1 ? '' : 's') + '</span>' + tmAccCountsHtml(tmAccCounts(all, Number(eq.id))) + '</div></div>'
      + '<button type="button" class="tm-dotsbtn" data-act="team-menu" aria-label="Ações da equipe ' + esc(eq.nome) + '" aria-haspopup="menu">' + TM_DOTS + '</button></div>'
      + '</div>';
  }).join('');
  if (semEq) {
    const act = tmSelTeam === 'none';
    h += '<div class="tm-team' + (act ? ' active' : '') + '" style="--tc:#717171" data-team="none" data-drop="none" role="button" tabindex="0" aria-pressed="' + act + '">'
      + '<div class="tm-team-top"><div class="tm-team-av">?</div><div class="tm-team-id"><div class="tm-team-name">Sem equipe</div><div class="tm-lead none"><span>Solte aqui para desvincular</span></div>'
      + '<div class="tm-team-stats"><span><b>' + semEq + '</b> closer' + (semEq === 1 ? '' : 's') + '</span></div></div></div></div>';
  }
  tmEl('tmTeams').innerHTML = h;

  const sel = tmEl('tmTeamSel');
  sel.innerHTML = '<option value="all">Todos os closers (' + all.length + ')</option>'
    + allEquipes.map(e => '<option value="' + e.id + '">' + esc(e.nome) + ' (' + (e.total_closers || 0) + ')</option>').join('')
    + (semEq ? '<option value="none">Sem equipe (' + semEq + ')</option>' : '');
  sel.value = tmSelTeam;

  const bm = tmEl('tmBulkMove');
  bm.innerHTML = '<option value="">Mover para…</option>' + allEquipes.map(e => '<option value="' + e.id + '">' + esc(e.nome) + '</option>').join('') + '<option value="none">Sem equipe</option>';
}

function tmRenderMain(all){
  const scope = tmScope(all);
  const eq = allEquipes.find(e => String(e.id) === String(tmSelTeam));
  const viewAll = !eq && tmSelTeam !== 'none';                      // vendo closers de várias equipes → mostra a equipe em cada card
  tmEl('tmHeadName').textContent = eq ? eq.nome : (tmSelTeam === 'none' ? 'Sem equipe' : 'Todos os closers');
  tmEl('tmHeadDot').style.background = eq ? (eq.cor || '#d4af37') : '#d4af37';
  let sub = scope.length + ' closer' + (scope.length === 1 ? '' : 's');
  if (eq) sub += eq.lider_nome ? ' · Líder: ' + eq.lider_nome : ' · sem líder definido';
  tmEl('tmHeadSub').textContent = sub;

  if (tmFilter !== 'ferias' && !TM_ACC_FILTERS.includes(tmFilter)) tmFilter = 'all';   // Todos · Férias · status de acesso ao portal
  const reqs = tmReqsInScope();
  const cnt = { all:scope.length, ferias:scope.filter(c => c.vac).length,
    ativo:scope.filter(c => tmAccState(c) === 'ativo').length,
    pendente:scope.filter(c => tmAccState(c) === 'pendente').length + reqs.filter(a => a.status === 'pendente').length,
    sem:scope.filter(c => tmAccState(c) === 'sem').length,
    rejeitado:scope.filter(c => tmAccState(c) === 'rejeitado').length + reqs.filter(a => a.status === 'rejeitado').length };
  const chip = ([k, label]) => '<button type="button" class="tm-chip' + (tmFilter === k ? ' active' : '') + '" data-filter="' + k + '" role="tab" aria-selected="' + (tmFilter === k) + '">'
    + label + ' <b>' + cnt[k] + '</b></button>';
  tmEl('tmChips').innerHTML = [['all','Todos'], ['ferias',''+ICO.sun+' Férias']].map(chip).join('') + '<span class="tm-chip-sep" aria-hidden="true"></span>'
    + [['ativo',''+ICO.dotOk+' Ativos'], ['pendente',''+ICO.dotWait+' Pendentes'], ['sem',''+ICO.dotNone+' Sem acesso'], ['rejeitado',''+ICO.dotBad+' Rejeitados']].map(chip).join('');

  const q = tmNorm(tmSearch);
  const list = scope.filter(c => {
    if (q && !tmNorm(c.nome).includes(q)) return false;
    if (tmFilter === 'ferias') return c.vac;
    if (TM_ACC_FILTERS.includes(tmFilter)) return tmAccState(c) === tmFilter;
    return true;
  });
  const leaders = new Set(allEquipes.filter(e => e.lider_id).map(e => String(e.lider_id)));
  list.sort((a, b) => {
    if (a.vac !== b.vac) return a.vac ? 1 : -1;                     // quem está de férias vai pro fim
    const la = leaders.has(String(a.id)) ? 0 : 1, lb = leaders.has(String(b.id)) ? 0 : 1;
    if (la !== lb) return la - lb;                                  // líderes primeiro
    return a.nome.localeCompare(b.nome, 'pt-BR');
  });
  tmEl('tmGrid').innerHTML = list.length
    ? list.map(c => tmCard(c, leaders.has(String(c.id)), viewAll)).join('')
    : '<div class="tm-empty">' + (scope.length
        ? 'Nenhum closer encontrado com esse filtro.'
        : (eq ? 'Nenhum closer nesta equipe ainda.<br>Arraste um closer até o card da equipe ou use <strong>+ Adicionar Closer</strong>.' : 'Nenhum closer cadastrado.')) + '</div>';

  tmRenderReqs();
  const visIds = list.map(c => c.id);
  tmEl('tmSelAll').checked = visIds.length > 0 && visIds.every(id => tmSelected.has(id));
  tmUpdateBulk();
}

// Card de closer: só identidade e status (líder / férias) + ações. Sem métricas de meta ou contratos.
function tmCard(c, isLead, showTeam){
  const sel = tmSelected.has(c.id);
  const acc = tmAccMap.get(c.id) || null, accSt = acc ? acc.status : 'sem';
  const av = c.imageUrl ? tmImg(c.imageUrl, tmInitials(c.nome)) : esc(tmInitials(c.nome));
  const role = isLead ? '<span class="tm-role lead">' + TM_CROWN + ' Líder</span>' : '<span class="tm-role">Closer</span>';
  const eq = showTeam && c.equipe_id ? allEquipes.find(e => Number(e.id) === c.equipe_id) : null;
  const team = showTeam ? (eq ? '<span class="rk-badge" style="--bc:' + rkTone(eq.cor) + '"><i></i>' + esc(eq.nome) + '</span>' : '<span class="rk-badge none"><i></i>Sem equipe</span>') : '';
  let vac = '';
  if (c.vac) {
    const f = c.fer || {};
    vac = '<span class="tm-vacb" title="Fora do ranking">'+ICO.sun+' Férias' + (f.fim ? ' · Até ' + tmDay(f.fim) : (f.desde ? ' · desde ' + tmDay(f.desde) : '')) + '</span>';
  } else if (c.fer && c.fer.inicio) {
    vac = '<span class="tm-vacb sched" title="Férias agendadas">'+ICO.sun+' Agendadas · ' + tmDay(c.fer.inicio) + (c.fer.fim ? '–' + tmDay(c.fer.fim) : '') + '</span>';
  }
  return '<article class="tm-cl' + (sel ? ' selected' : '') + (c.vac ? ' vac' : '') + '" data-id="' + c.id + '" data-acc="' + accSt + '" style="--ac:' + esc(c.color) + '">'
    + tmAccBadge(accSt, acc, true)
    + '<input type="checkbox" class="tm-chk" data-act="sel"' + (sel ? ' checked' : '') + ' aria-label="Selecionar ' + esc(c.nome) + '">'
    + '<div class="tm-av">' + av + '</div>'
    + '<div class="tm-cl-main"><div class="tm-name" title="' + esc(c.nome) + '">' + esc(c.nome) + '</div><div class="tm-badges">' + role + team + vac + tmAccKeyBadge(acc) + '</div></div>'
    + '<span class="tm-grip" draggable="true" data-act="grip" title="Arraste até uma equipe para transferir">' + TM_GRIP + '</span>'
    + '<button type="button" class="tm-dotsbtn" data-act="menu" aria-label="Ações de ' + esc(c.nome) + '" aria-haspopup="menu">' + TM_DOTS + '</button>'
    + '</article>';
}

function tmRenderArchive(){
  const list = tmEl('tmExList'); if (!list) return;
  tmEl('tmArchiveCount').textContent = tmDeleted.length;
  list.innerHTML = tmDeleted.length ? tmDeleted.map(v => {
    const av = v.imageUrl ? tmImg(v.imageUrl, tmInitials(v.nome)) : esc(tmInitials(v.nome));
    const when = v.deleted_at ? tmDayFull(v.deleted_at.substring(0, 10)) : '';
    const why = TM_MOTIVOS[v.desligamento_motivo] || '';
    return '<div class="tm-ex"><div class="tm-av" style="--ac:' + esc(v.cor || '#d4af37') + '">' + av + '</div>'
      + '<div class="tm-ex-info"><div class="tm-ex-name">' + esc(v.nome) + '</div><div class="tm-ex-sub">' + (v.equipe_nome ? esc(v.equipe_nome) + ' · ' : '') + 'Desligado em ' + when + '</div></div>'
      + '<span class="tm-reason">' + (why ? esc(why) : 'Sem motivo registrado') + '</span>'
      + '<button type="button" class="btn btn-p btn-sm" onclick="restoreCloser(' + Number(v.id) + ')">Reativar</button></div>';
  }).join('') : '<div class="tm-empty" style="padding:22px">Nenhum ex-closer.</div>';
}

// ── Seleção, filtros, busca ─────────────────────────────────────────────────
function tmSelectTeam(v){
  tmSelTeam = String(v); tmSelected.clear();
  try { localStorage.setItem('tmTeam', tmSelTeam); } catch(e) {}
  tmRender();
}
// Escolhe a equipe SEM renderizar — para quando ela acabou de ser criada e ainda não veio do servidor
// (o reload seguinte valida a escolha; renderizar antes faria tmEnsureSel voltar pra 1ª equipe).
function tmPreselect(v){
  tmSelTeam = String(v); tmSelected.clear();
  try { localStorage.setItem('tmTeam', tmSelTeam); } catch(e) {}
}
function tmSetFilter(k){ tmFilter = k; tmRender(); }
function tmSetSearch(v){ tmSearch = v; tmRender(); }
function tmUpdateBulk(){
  const n = tmSelected.size;
  tmEl('tmBulk').hidden = n === 0;
  tmEl('tmBulkN').textContent = n;
}
function tmClearSel(){ tmSelected.clear(); tmRender(); }
function tmSelectAllVisible(on){
  const ids = [...document.querySelectorAll('#tmGrid .tm-cl')].map(a => Number(a.dataset.id));
  ids.forEach(id => on ? tmSelected.add(id) : tmSelected.delete(id));
  tmRender();
}

// ── Menu dos 3 pontinhos ────────────────────────────────────────────────────
function tmMenuHtml(items){
  return items.filter(Boolean).map(it => it === 'sep' ? '<div class="tm-menu-sep"></div>'
    : it.title ? '<div class="tm-menu-title">' + esc(it.title) + '</div>'
    : '<button type="button" class="tm-mi' + (it[3] ? ' ' + it[3] : '') + '" role="menuitem" data-m="' + it[0] + '"' + (it[4] !== undefined ? ' data-v="' + esc(it[4]) + '"' : '') + '><i>' + it[1] + '</i>' + esc(it[2]) + '</button>').join('');
}
function tmShowMenu(anchor, html){
  const menu = tmEl('tmMenu');
  menu.innerHTML = html; menu.hidden = false;
  const r = anchor.getBoundingClientRect(), mw = menu.offsetWidth, mh = menu.offsetHeight;
  let left = Math.min(window.innerWidth - mw - 8, Math.max(8, r.right - mw));
  let top = r.bottom + 6; if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 6);
  menu.style.left = left + 'px'; menu.style.top = top + 'px';
  document.querySelectorAll('.tm-dotsbtn[aria-expanded]').forEach(b => b.removeAttribute('aria-expanded'));
  anchor.setAttribute('aria-expanded', 'true');
  const first = menu.querySelector('.tm-mi'); if (first) first.focus();
}
function tmCloseMenu(){
  tmEl('tmMenu').hidden = true; tmMenuState = null;
  document.querySelectorAll('.tm-dotsbtn[aria-expanded]').forEach(b => b.removeAttribute('aria-expanded'));
}
function tmOpenCloserMenu(id, anchor){
  const c = tmCloserList().find(x => x.id === id); if (!c) return;
  const eq = allEquipes.find(e => String(e.id) === String(c.equipe_id));
  const isLead = !!eq && String(eq.lider_id) === String(c.id);
  tmMenuState = { kind:'cl', id, anchor, equipe_id:c.equipe_id };
  tmShowMenu(anchor, tmMenuHtml([
    ['edit', TM_IC.edit, 'Editar'],
    c.equipe_id ? [isLead ? 'unlead' : 'lead', TM_CROWN, isLead ? 'Remover liderança' : 'Tornar líder da equipe'] : null,
    ['ferias', ''+ICO.sun+'', c.vac ? 'Editar férias…' : (c.fer ? 'Editar férias agendadas…' : 'Definir férias…')],
    c.vac ? ['endferias', TM_IC.back, 'Encerrar férias agora'] : null,
    ['access', ''+ICO.shield+'', 'Gerenciar acesso…'],
    ['move', TM_IC.move, 'Mover para…'],
    'sep',
    ['del', TM_IC.trash, 'Excluir…', 'danger']
  ]));
}
function tmOpenTeamMenu(id, anchor){
  tmMenuState = { kind:'team', id, anchor };
  tmShowMenu(anchor, tmMenuHtml([['team-edit', TM_IC.edit, 'Editar equipe'], 'sep', ['team-del', TM_IC.trash, 'Excluir equipe…', 'danger']]));
}
function tmMenuAction(m, v){
  const st = tmMenuState; if (!st) return;
  if (m === 'move') {
    const c = tmCloserList().find(x => x.id === st.id);
    const items = [{ title:'Mover para…' }];
    allEquipes.forEach(e => { if (String(e.id) !== String(c && c.equipe_id)) items.push(['to', TM_IC.to, e.nome, '', String(e.id)]); });
    if (c && c.equipe_id) items.push(['to', TM_IC.to, 'Sem equipe', '', '']);
    tmShowMenu(st.anchor, tmMenuHtml(items));
    return;
  }
  if (m === 'access') { tmShowMenu(st.anchor, tmMenuHtml(tmAccMenuItems(st.id))); return; }
  const id = st.id, eqId = st.equipe_id;
  tmCloseMenu();
  if (m === 'edit') tmOpenCloserDrawer(id);
  else if (m === 'lead') tmSetLeader(eqId, id);
  else if (m === 'unlead') tmSetLeader(eqId, 0);
  else if (m === 'ferias') tmOpenFerias(id);
  else if (m === 'endferias') tmEndFeriasFor(id);
  else if (m === 'del') tmOpenDesligar(id);
  else if (m === 'to') tmMove([id], v);
  else if (m === 'team-edit') tmOpenTeamDrawer(id);
  else if (m === 'team-del') deleteEquipe(id);
  else if (m.indexOf('acc-') === 0) tmAccAction(m, id);
}

// ── Ações: mover (drag & drop / menu / lote), líder ─────────────────────────
async function tmMove(ids, teamId){
  const cur = tmCloserList();
  const dest = teamId === '' || teamId === 'none' ? '' : String(teamId);
  ids = ids.filter(id => { const c = cur.find(x => x.id === id); return c && String(c.equipe_id || '') !== dest; });
  if (!ids.length) return;
  const losesLead = allEquipes.filter(e => e.lider_id && ids.some(i => String(i) === String(e.lider_id)) && String(e.id) !== dest);
  const d = await tmApi({ action:'move_vendedor', ids:ids.join(','), equipe_id:dest });
  if (d.success) {
    toast(d.message + (losesLead.length ? ' — liderança da equipe anterior removida' : ''));
    tmSelected.clear();
    await tmReloadAll();
  } else toast(d.message, true);
}
async function tmSetLeader(eqId, lid){
  const d = await tmApi({ action:'set_lider_equipe', equipe_id:eqId, lider_id:lid });
  if (d.success) { toast(d.message); await fetchData(); } else toast(d.message, true);
}
function tmBulkMove(sel){
  const v = sel.value; sel.value = '';
  if (v === '') return;
  tmMove([...tmSelected], v);
}

// ── Férias com período ──────────────────────────────────────────────────────
let tmFerId = null;
function tmOpenFerias(id){
  const c = tmCloserList().find(x => x.id === id); if (!c) return;
  tmFerId = id;
  const f = c.fer || {}, today = tmTodayISO();
  tmEl('tmFerName').textContent = c.nome;
  tmEl('tmFerStart').value = f.inicio || f.desde || today;
  tmEl('tmFerEnd').value = f.fim || '';
  tmEl('tmFerNoEnd').checked = c.vac && !f.fim;
  const eb = tmEl('tmFerEndBtn');
  eb.hidden = !(c.vac || f.inicio);
  eb.textContent = c.vac ? 'Encerrar férias agora' : 'Cancelar férias agendadas';
  tmFerasHint();
  tmEl('tmFeriasDlg').showModal();
}
function tmFerPreset(n){
  const s = tmEl('tmFerStart').value || tmTodayISO();
  tmEl('tmFerStart').value = s;
  tmEl('tmFerEnd').value = tmAddDays(s, n - 1);
  tmEl('tmFerNoEnd').checked = false;
  tmFerasHint();
}
function tmFerasHint(){
  const s = tmEl('tmFerStart').value, e = tmEl('tmFerEnd').value, no = tmEl('tmFerNoEnd').checked, h = tmEl('tmFerHint');
  tmEl('tmFerEnd').disabled = no;
  h.classList.remove('warn');
  if (!s) { h.textContent = ''; return; }
  const today = tmTodayISO();
  if (!no && !e) { h.textContent = 'Informe a data de retorno ou marque “Sem data de retorno definida”.'; return; }
  if (!no && e < s) { h.classList.add('warn'); h.textContent = 'A data final não pode ser anterior à data inicial.'; return; }
  if (!no && e < today) { h.classList.add('warn'); h.textContent = 'A data final já passou.'; return; }
  const dur = !no ? tmDiffDays(s, e) + 1 : null;
  const dtxt = dur ? dur + ' dia' + (dur > 1 ? 's' : '') : '';
  if (s > today) h.textContent = 'Agendadas: o closer continua no ranking até ' + tmDay(s) + (no ? ' e depois fica fora até você encerrar as férias.' : ' e fica fora de ' + tmDay(s) + ' a ' + tmDay(e) + ' (' + dtxt + ').');
  else h.textContent = no ? 'O closer sai do ranking agora e só volta quando você encerrar as férias.'
                          : 'Fora do ranking de ' + tmDay(s) + ' a ' + tmDay(e) + ' (' + dtxt + '). Volta automaticamente no dia seguinte.';
}
async function tmSaveFerias(e){
  e.preventDefault();
  const s = tmEl('tmFerStart').value, no = tmEl('tmFerNoEnd').checked, en = no ? '' : tmEl('tmFerEnd').value;
  if (!s) { toast('Informe a data de início', true); return false; }
  if (!no && !en) { toast('Informe a data de retorno', true); return false; }
  const d = await tmApi({ action:'set_ferias', id:tmFerId, inicio:s, fim:en });
  if (d.success) { toast(d.message); tmEl('tmFeriasDlg').close(); await tmReloadAll(); } else toast(d.message, true);
  return false;
}
async function tmEndFerias(){
  const d = await tmApi({ action:'reactivate_suspended', id:tmFerId });
  if (d.success) { toast(d.message); tmEl('tmFeriasDlg').close(); await tmReloadAll(); } else toast(d.message, true);
}
async function tmEndFeriasFor(id){
  const d = await tmApi({ action:'reactivate_suspended', id });
  if (d.success) { toast(d.message); await tmReloadAll(); } else toast(d.message, true);
}

// ── Desligamento (arquivo morto) ────────────────────────────────────────────
let tmDesId = null;
function tmOpenDesligar(id){
  const c = tmCloserList().find(x => x.id === id); if (!c) return;
  tmDesId = id;
  tmEl('tmDesName').textContent = c.nome;
  tmEl('tmDesMotivo').value = '';
  tmEl('tmDesOk').disabled = true;
  tmEl('tmDesligarDlg').showModal();
}
async function tmConfirmDesligar(e){
  e.preventDefault();
  const m = tmEl('tmDesMotivo').value;
  if (!m) return false;
  const d = await tmApi({ action:'delete_vendedor', id:tmDesId, motivo:m });
  if (d.success) {
    toast(d.message); tmEl('tmDesligarDlg').close(); tmSelected.delete(tmDesId);
    await tmReloadAll();
  } else toast(d.message, true);
  return false;
}

// ── Meta em lote ────────────────────────────────────────────────────────────
let tmBulkMode = 'mes';
function tmOpenBulkMeta(mode){
  if (!tmSelected.size) return;
  tmBulkMode = mode;
  tmEl('tmMetaTitle').textContent = mode === 'mes' ? 'Definir meta do mês' : 'Definir meta semanal';
  tmEl('tmMetaLabel').textContent = mode === 'mes' ? 'Meta do mês (contratos)' : 'Meta da semana (contratos)';
  const inp = tmEl('tmMetaInput'); inp.value = ''; inp.min = mode === 'mes' ? 0 : 1;
  tmEl('tmMetaHint').textContent = 'Será aplicada aos ' + tmSelected.size + ' closer(s) selecionado(s)' + (mode === 'mes' ? '. Use 0 para voltar ao cálculo automático.' : '.');
  tmEl('tmMetaDlg').showModal(); inp.focus();
}
async function tmSaveBulkMeta(e){
  e.preventDefault();
  const v = tmEl('tmMetaInput').value;
  if (v === '') return false;
  const p = { action:'bulk_update_vendedores', ids:[...tmSelected].join(',') };
  p[tmBulkMode === 'mes' ? 'meta_mensal' : 'metas'] = v;
  const d = await tmApi(p);
  if (d.success) { toast(d.message); tmEl('tmMetaDlg').close(); await tmReloadAll(); } else toast(d.message, true);
  return false;
}

// ── Confirmação genérica (promessa) ─────────────────────────────────────────
function tmConfirm(title, msg, okLabel){
  return new Promise(res => {
    const dlg = tmEl('tmConfirmDlg');
    tmEl('tmConfTitle').textContent = title; tmEl('tmConfMsg').textContent = msg;
    tmEl('tmConfOk').textContent = okLabel || 'Confirmar';
    dlg.returnValue = '';
    dlg.addEventListener('close', () => res(dlg.returnValue === 'ok'), { once:true });
    dlg.showModal();
  });
}

// ── Eventos (delegação) + drag & drop ───────────────────────────────────────
function tmDragIds(e){
  try { return JSON.parse(e.dataTransfer.getData('application/x-tm-ids') || '[]'); } catch(err) { return []; }
}
function tmDragCleanup(){
  document.body.classList.remove('tm-dragging');
  document.querySelectorAll('.tm-cl.dragging').forEach(x => x.classList.remove('dragging'));
  document.querySelectorAll('.tm-team.drop').forEach(x => x.classList.remove('drop'));
}
function tmInitEvents(){
  const teams = tmEl('tmTeams'), grid = tmEl('tmGrid');
  if (!teams || teams.dataset.bound) return;
  teams.dataset.bound = '1';

  teams.addEventListener('click', e => {
    const m = e.target.closest('[data-act="team-menu"]');
    if (m) { e.stopPropagation(); const card = m.closest('[data-team]'); tmOpenTeamMenu(Number(card.dataset.team), m); return; }
    const t = e.target.closest('[data-team]'); if (t) tmSelectTeam(t.dataset.team);
  });
  teams.addEventListener('keydown', e => {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.tm-team')) { e.preventDefault(); tmSelectTeam(e.target.dataset.team); }
  });
  const isOurDrag = e => e.dataTransfer && [...e.dataTransfer.types].includes('application/x-tm-ids');
  teams.addEventListener('dragover', e => {
    const t = e.target.closest('[data-drop]'); if (!t || !isOurDrag(e)) return;
    e.preventDefault(); e.dataTransfer.dropEffect = 'move'; t.classList.add('drop');
  });
  teams.addEventListener('dragleave', e => {
    const t = e.target.closest('[data-drop]'); if (t && !t.contains(e.relatedTarget)) t.classList.remove('drop');
  });
  teams.addEventListener('drop', e => {
    const t = e.target.closest('[data-drop]'); if (!t || !isOurDrag(e)) return;
    e.preventDefault(); t.classList.remove('drop');
    const ids = tmDragIds(e); tmDragCleanup();
    tmMove(ids, t.dataset.drop === 'none' ? '' : t.dataset.drop);
  });

  grid.addEventListener('dragstart', e => {
    const g = e.target.closest('.tm-grip');
    if (!g) { e.preventDefault(); return; }
    const card = g.closest('.tm-cl'), id = Number(card.dataset.id);
    const ids = tmSelected.has(id) ? [...tmSelected] : [id];     // arrastar um marcado leva todos os marcados
    e.dataTransfer.setData('application/x-tm-ids', JSON.stringify(ids));
    e.dataTransfer.setData('text/plain', ids.join(','));
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setDragImage(card, 24, 24); } catch(err) {}
    // só depois do dragstart: mexer no estilo aqui dentro suja a imagem do "fantasma" (e pode abortar o arrasto no Chrome)
    setTimeout(() => {
      document.body.classList.add('tm-dragging');
      ids.forEach(i => { const el = grid.querySelector('.tm-cl[data-id="' + i + '"]'); if (el) el.classList.add('dragging'); });
    }, 0);
  });
  document.addEventListener('dragend', tmDragCleanup);

  grid.addEventListener('click', e => {
    const b = e.target.closest('[data-act="menu"]');
    if (b) { e.stopPropagation(); tmOpenCloserMenu(Number(b.closest('.tm-cl').dataset.id), b); }
  });
  grid.addEventListener('change', e => {
    if (!e.target.matches('[data-act="sel"]')) return;
    const id = Number(e.target.closest('.tm-cl').dataset.id);
    if (e.target.checked) tmSelected.add(id); else tmSelected.delete(id);
    e.target.closest('.tm-cl').classList.toggle('selected', e.target.checked);
    const visIds = [...grid.querySelectorAll('.tm-cl')].map(a => Number(a.dataset.id));
    tmEl('tmSelAll').checked = visIds.length > 0 && visIds.every(i => tmSelected.has(i));
    tmUpdateBulk();
  });
  tmEl('tmChips').addEventListener('click', e => { const c = e.target.closest('[data-filter]'); if (c) tmSetFilter(c.dataset.filter); });

  const menu = tmEl('tmMenu');
  menu.addEventListener('click', e => { const b = e.target.closest('[data-m]'); if (b) { e.stopPropagation(); tmMenuAction(b.dataset.m, b.dataset.v); } });
  document.addEventListener('pointerdown', e => { if (!menu.hidden && !menu.contains(e.target) && !e.target.closest('.tm-dotsbtn')) tmCloseMenu(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !menu.hidden) tmCloseMenu(); });
  window.addEventListener('resize', tmCloseMenu);
  window.addEventListener('scroll', tmCloseMenu, true);

  // clicar no fundo escurecido fecha o dialog (só se o clique COMEÇOU no fundo também)
  document.querySelectorAll('.tm-dlg').forEach(dlg => {
    let down = null;
    dlg.addEventListener('mousedown', e => { down = e.target; });
    dlg.addEventListener('click', e => {
      if (e.target !== dlg || down !== dlg) return;
      if (dlg.id === 'tmDrawerCl') resetClForm(); else if (dlg.id === 'tmDrawerEq') resetEqForm(); else dlg.close('cancel');
    });
  });
}

// ══════════════════════════════════════════════════════════════════════════
// EQUIPES — ACESSO AO PORTAL DO CLOSER (status, aprovar/rejeitar/revogar, convite, token de senha)
// Dados: api.php?action=get_acessos. "SEM ACESSO" não é um status guardado: é o closer sem conta.
// Pedidos de closers que ainda não existem no ranking (sem vínculo) aparecem no painel "Solicitações de acesso".
// ══════════════════════════════════════════════════════════════════════════
let tmAcc = [], tmConvites = {}, tmAccMap = new Map(), tmAccCur = null;
const TM_ACC_FILTERS = ['ativo','pendente','sem','rejeitado'];
const TM_ACC = {
  ativo:     { ic:''+ICO.dotOk+'', label:'Ativo',      cls:'ok' },
  pendente:  { ic:''+ICO.dotWait+'', label:'Pendente',   cls:'wait' },
  rejeitado: { ic:''+ICO.dotBad+'', label:'Rejeitado',  cls:'bad' },
  sem:       { ic:''+ICO.dotNone+'', label:'Sem acesso', cls:'none' }
};
const tmDT = s => s ? tmDayFull(String(s).slice(0,10)) + ' às ' + String(s).slice(11,16) : '';
const tmAccState = c => { const a = tmAccMap.get(c.id); return a ? a.status : 'sem'; };

async function tmLoadAcessos(){
  try {
    const d = await fetch(API_URL+'?action=get_acessos').then(r => r.json());
    if (!d.success) return;
    tmAcc = d.data || []; tmConvites = d.convites || {};
    tmAccMap = new Map(tmAcc.filter(a => a.vendedor_id).map(a => [a.vendedor_id, a]));
    tmRender();
  } catch(e) {}
}
function tmAccTip(st, a){
  if (st === 'ativo')    return 'Acesso liberado' + (a.ultimo_login ? ' · último acesso em ' + tmDT(a.ultimo_login) : ' · ainda não entrou no portal');
  if (st === 'pendente') return 'Aguardando aprovação desde ' + tmDayFull(String(a.status_em || a.criado_em).slice(0,10));
  if (st === 'rejeitado') {
    const rev = /revogado/i.test(a.status_motivo || '');
    return (rev ? 'Acesso revogado em ' : 'Rejeitado em ') + tmDayFull(String(a.status_em || a.criado_em).slice(0,10)) + (!rev && a.status_motivo ? ' — ' + a.status_motivo : '');
  }
  return 'Ainda não se registrou';
}
function tmAccBadge(st, a, corner){
  const m = TM_ACC[st];
  return '<span class="tm-acc ' + m.cls + (corner ? ' corner' : '') + '" data-acc="' + st + '" title="' + esc(tmAccTip(st, a)) + '">' + m.ic + ' ' + m.label + '</span>';
}
function tmAccKeyBadge(a){
  if (!a || a.status !== 'ativo') return '';
  if (a.token_ativo_ate) return '<span class="tm-acc key" title="Token de redefinição válido até ' + esc(tmDT(a.token_ativo_ate)) + '">'+ICO.key+' Token ativo</span>';
  if (a.reset_pedido_em) return '<span class="tm-acc key attn" title="Pediu nova senha em ' + esc(tmDT(a.reset_pedido_em)) + ' — gere um token em Gerenciar acesso">'+ICO.key+' Pediu nova senha</span>';
  return '';
}
// contadores no card da equipe: verde 3 | amarelo 1 (só o que for > 0). Inclui pedidos ainda sem vínculo à equipe.
function tmAccCounts(all, teamId){
  const c = { ativo:0, pendente:0, rejeitado:0, sem:0 };
  all.filter(x => x.equipe_id === teamId).forEach(x => c[tmAccState(x)]++);
  tmAcc.filter(a => !a.vendedor_id && a.equipe_id === teamId).forEach(a => { if (c[a.status] !== undefined) c[a.status]++; });
  return c;
}
function tmAccCountsHtml(c){
  const p = ['ativo','pendente','rejeitado','sem'].filter(k => c[k] > 0).map(k => '<span title="' + TM_ACC[k].label + '">' + TM_ACC[k].ic + ' ' + c[k] + '</span>');
  return p.length ? '<span class="tm-acc-cnt" aria-label="Acessos ao portal">' + p.join(' <i>|</i> ') + '</span>' : '';
}
// pedidos SEM vínculo dentro do escopo (equipe escolhida na coluna da esquerda)
function tmReqsInScope(){
  return tmAcc.filter(a => !a.vendedor_id && (tmSelTeam === 'all' || tmSelTeam === '' ? true : tmSelTeam === 'none' ? !a.equipe_id : String(a.equipe_id) === String(tmSelTeam)));
}
function tmRenderReqs(){
  const box = tmEl('tmReqs'); if (!box) return;
  const q = tmNorm(tmSearch);
  const want = tmFilter === 'rejeitado' ? 'rejeitado' : (tmFilter === 'all' || tmFilter === 'pendente') ? 'pendente' : null;
  const list = want ? tmReqsInScope().filter(a => a.status === want && (!q || tmNorm(a.nome + ' ' + a.email).includes(q))) : [];
  box.hidden = !list.length;
  if (!list.length) { box.innerHTML = ''; return; }
  box.innerHTML = '<div class="tm-reqs-h"><span>' + (want === 'pendente' ? ''+ICO.clock+' Solicitações de acesso' : ''+ICO.dotBad+' Pedidos rejeitados') + ' (' + list.length + ')</span><small>Cadastros feitos por closers que ainda não estão vinculados a ninguém do ranking</small></div>'
    + list.map(a => {
      const eq = allEquipes.find(e => Number(e.id) === a.equipe_id);
      const btns = a.status === 'pendente'
        ? '<button type="button" class="btn btn-s btn-sm" data-ra="edit">'+TM_IC.edit+' Editar</button><button type="button" class="btn btn-p btn-sm" data-ra="approve">'+ICO.check+' Aprovar acesso</button><button type="button" class="btn btn-s btn-sm" data-ra="reject">'+ICO.xcircle+' Rejeitar</button>'
        : '<button type="button" class="btn btn-s btn-sm" data-ra="reconsider">'+ICO.refresh+' Reconsiderar</button><button type="button" class="btn btn-d btn-sm" data-ra="delete">Excluir</button>';
      return '<article class="tm-req" data-acc-id="' + a.id + '"><div class="tm-av">' + esc(tmInitials(a.nome)) + '</div>'
        + '<div class="tm-req-main"><div class="tm-name" title="' + esc(a.nome) + '">' + esc(a.nome) + '</div>'
        + '<div class="tm-req-sub">' + esc(a.email) + ' · ' + (eq ? 'equipe ' + esc(eq.nome) : 'sem equipe') + ' · ' + tmDayFull(String(a.criado_em).slice(0,10)) + '</div>'
        + '<div class="tm-badges">' + tmAccBadge(a.status, a, false) + '</div></div><div class="tm-req-b">' + btns + '</div></article>';
    }).join('');
}

// ── Ações ────────────────────────────────────────────────────────────────────
async function tmAccCall(params, okMsg){
  const d = await tmApi(params);
  if (d.success) { toast(okMsg || d.message); await tmReloadAll(); }
  else toast(d.message || 'Não foi possível concluir', true);
  return d;
}
function tmAccById(id){ return tmAcc.find(a => a.id === Number(id)) || null; }
function tmAccName(a){ const c = a && a.vendedor_id ? tmCloserList().find(x => x.id === a.vendedor_id) : null; return c ? c.nome : (a ? a.nome : ''); }
async function tmAccRevoke(id){
  const a = tmAccById(id); if (!a) return;
  if (!await tmConfirm('Revogar acesso?', tmAccName(a) + ' perde o acesso ao portal imediatamente. O histórico de ações é mantido e dá para reconsiderar depois.', 'Revogar acesso')) return;
  tmAccCall({ action:'acesso_revogar', id });
}
function tmAccReconsider(id){ tmAccCall({ action:'acesso_reconsiderar', id }, 'Pedido reaberto — aprove ou rejeite novamente'); }
async function tmAccDelete(id){
  const a = tmAccById(id); if (!a) return;
  if (!await tmConfirm('Excluir pedido?', 'O cadastro de ' + a.nome + ' (' + a.email + ') será apagado. O closer e o histórico de ações não são afetados.', 'Excluir pedido')) return;
  tmAccCall({ action:'acesso_excluir', id });
}

// Aprovar: pedido já vinculado = 1 confirmação; sem vínculo = escolher o closer (ou criar um novo)
function tmAccApprove(id){
  const a = tmAccById(id); if (!a) return;
  tmAccCur = a;
  const eq = allEquipes.find(e => Number(e.id) === a.equipe_id);
  tmEl('tmAccWho').innerHTML = '<div class="tm-acc-who"><b>' + esc(a.nome) + '</b><span>' + esc(a.email) + '</span><small>Pediu acesso em ' + esc(tmDT(a.criado_em)) + (eq ? ' · equipe ' + esc(eq.nome) : '') + '</small></div>';
  const box = tmEl('tmAccLink'), hint = tmEl('tmAccHint');
  if (a.vendedor_id) {
    box.hidden = true;
    hint.innerHTML = 'Conta vinculada ao closer <b>' + esc(tmAccName(a)) + '</b>. Ao aprovar, ele já pode entrar no portal e registrar ações.';
  } else {
    box.hidden = false;
    const free = tmCloserList().filter(c => !tmAccMap.has(c.id));
    const mine = free.filter(c => c.equipe_id === a.equipe_id), other = free.filter(c => c.equipe_id !== a.equipe_id);
    const opt = c => '<option value="' + c.id + '">' + esc(c.nome) + '</option>';
    tmEl('tmAccSel').innerHTML = '<option value="">Selecione o closer…</option>'
      + (mine.length ? '<optgroup label="Da equipe pedida">' + mine.map(opt).join('') + '</optgroup>' : '')
      + (other.length ? '<optgroup label="Outras equipes">' + other.map(opt).join('') + '</optgroup>' : '');
    tmEl('tmAccTeam').textContent = eq ? eq.nome : '(sem equipe)';
    document.querySelector('input[name="tmAccModo"][value="existente"]').checked = !!free.length;
    document.querySelector('input[name="tmAccModo"][value="novo"]').checked = !free.length;
    document.querySelector('input[name="tmAccModo"][value="existente"]').disabled = !free.length;
    hint.innerHTML = 'Não achamos um closer com esse nome na equipe. Vincule a conta a quem ela pertence — ou crie um novo closer com este nome.';
    tmAccMode();
  }
  tmEl('tmAccOk').disabled = false;
  tmEl('tmAccDlg').showModal();
}
function tmAccMode(){
  const m = (document.querySelector('input[name="tmAccModo"]:checked') || {}).value;
  tmEl('tmAccSel').disabled = m !== 'existente';
}
async function tmAccConfirm(e){
  e.preventDefault();
  const a = tmAccCur; if (!a) return false;
  const p = { action:'acesso_aprovar', id:a.id };
  if (!a.vendedor_id) {
    const m = document.querySelector('input[name="tmAccModo"]:checked').value;
    if (m === 'novo') p.novo = 1;
    else { p.vendedor_id = tmEl('tmAccSel').value; if (!p.vendedor_id) { toast('Escolha o closer desta conta', true); return false; } }
  }
  const btn = tmEl('tmAccOk'); btn.disabled = true;
  const d = await tmApi(p);
  if (d.success) { tmEl('tmAccDlg').close(); toast(d.message); await tmReloadAll(); }
  else { toast(d.message || 'Não foi possível aprovar', true); btn.disabled = false; }
  return false;
}
let tmAccEditId = null;
function tmAccEditOpen(id){
  const a = tmAccById(id); if (!a) return;
  tmAccEditId = a.id;
  tmEl('tmAccEditNome').value = a.nome || '';
  tmEl('tmAccEditEmail').value = a.email || '';
  tmEl('tmAccEditEq').innerHTML = '<option value="">Sem equipe</option>' + allEquipes.map(e => '<option value="' + e.id + '">' + esc(e.nome) + '</option>').join('');
  tmEl('tmAccEditEq').value = a.equipe_id ? String(a.equipe_id) : '';
  const linked = !!a.vendedor_id;
  tmEl('tmAccEditEq').disabled = linked;
  tmEl('tmAccEditHint').textContent = linked ? 'Esta conta já está vinculada a um closer: a equipe é a dele e não muda aqui.' : 'A equipe escolhida aqui é a que vale ao criar o closer na aprovação.';
  tmEl('tmAccEditErr').textContent = '';
  tmEl('tmAccEditOk').disabled = false;
  tmEl('tmAccEditDlg').showModal();
}
async function tmAccEditSave(e){
  e.preventDefault();
  const btn = tmEl('tmAccEditOk'); btn.disabled = true; tmEl('tmAccEditErr').textContent = '';
  const d = await tmApi({ action:'acesso_editar', id:tmAccEditId, nome:tmEl('tmAccEditNome').value.trim(), email:tmEl('tmAccEditEmail').value.trim(), equipe_id:tmEl('tmAccEditEq').value });
  if (d.success) { tmEl('tmAccEditDlg').close(); toast(d.message); await tmReloadAll(); }
  else { tmEl('tmAccEditErr').textContent = d.message || 'Não foi possível salvar o pedido.'; btn.disabled = false; }
  return false;
}
function tmAccReject(id){
  const a = tmAccById(id); if (!a) return;
  tmAccCur = a;
  tmEl('tmRejWho').innerHTML = '<div class="tm-acc-who"><b>' + esc(tmAccName(a)) + '</b><span>' + esc(a.email) + '</span></div>';
  tmEl('tmRejMotivo').value = ''; tmEl('tmRejOk').disabled = false;
  tmEl('tmRejDlg').showModal();
}
async function tmRejConfirm(e){
  e.preventDefault();
  const a = tmAccCur; if (!a) return false;
  const btn = tmEl('tmRejOk'); btn.disabled = true;
  const d = await tmApi({ action:'acesso_rejeitar', id:a.id, motivo:tmEl('tmRejMotivo').value });
  if (d.success) { tmEl('tmRejDlg').close(); toast(d.message); await tmReloadAll(); }
  else { toast(d.message || 'Não foi possível rejeitar', true); btn.disabled = false; }
  return false;
}

// Convite (link de cadastro de uso único) e token de nova senha: o texto só aparece AGORA — não fica salvo em claro
const TM_SHARE = { val:'', msg:'' };
function tmShare(o){
  TM_SHARE.val = o.value; TM_SHARE.msg = o.msg;
  tmEl('tmShEye').textContent = o.eyebrow; tmEl('tmShTitle').textContent = o.title;
  tmEl('tmShWho').innerHTML = '<b>' + esc(o.who) + '</b>' + (o.sub ? '<span>' + esc(o.sub) + '</span>' : '');
  tmEl('tmShValL').textContent = o.valueLabel;
  const v = tmEl('tmShVal'); v.textContent = o.value; v.classList.toggle('token', !!o.big);
  tmEl('tmShCopy').textContent = o.copyLabel;
  tmEl('tmShNote').innerHTML = o.note;
  tmEl('tmShWa').href = 'https://wa.me/?text=' + encodeURIComponent(o.msg);
  tmEl('tmShareDlg').showModal();
}
async function tmCopy(text){
  try { await navigator.clipboard.writeText(text); return true; }
  catch(e) {
    try { const t = document.createElement('textarea'); t.value = text; t.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(t); t.select(); const ok = document.execCommand('copy'); t.remove(); return ok; }
    catch(e2) { return false; }
  }
}
async function tmShareCopy(what){ toast(await tmCopy(what === 'msg' ? TM_SHARE.msg : TM_SHARE.val) ? (what === 'msg' ? 'Mensagem copiada' : 'Copiado') : 'Não foi possível copiar — selecione o texto e copie', false); }
const tmAbs = rel => new URL(rel, location.href).href;
async function tmAccInvite(closerId){
  const c = tmCloserList().find(x => x.id === closerId); if (!c) return;
  const d = await tmApi({ action:'acesso_convite', vendedor_id:closerId });
  if (!d.success) { toast(d.message || 'Não foi possível gerar o convite', true); return; }
  const url = tmAbs(d.path), ate = tmDayFull(d.expira_em.slice(0,10));
  tmLoadAcessos();
  tmShare({ eyebrow:'Convite de cadastro', title:'Link de cadastro', who:c.nome, sub:'Equipe ' + ((allEquipes.find(e => Number(e.id) === c.equipe_id) || {}).nome || '—'),
    value:url, valueLabel:'Link (uso único)', copyLabel:'Copiar link',
    msg:'Olá, ' + c.nome.split(' ')[0] + '! Faça seu cadastro no Portal do Closer por este link (vale até ' + ate + '): ' + url + ' — depois do cadastro, aguarde a aprovação.',
    note:'O link vale até <b>' + ate + '</b> e só pode ser usado uma vez. Gerar outro convite para este closer invalida este. O cadastro ainda passa pela sua aprovação.' });
}
async function tmAccResetToken(id){
  const a = tmAccById(id); if (!a) return;
  const d = await tmApi({ action:'acesso_reset_token', id });
  if (!d.success) { toast(d.message || 'Não foi possível gerar o token', true); return; }
  const url = tmAbs('/closer/recuperar?tab=token'), ate = tmDT(d.expira_em);
  tmLoadAcessos();
  tmShare({ eyebrow:'Redefinição de senha', title:'Token de nova senha', who:d.nome, sub:d.email,
    value:d.token, big:true, valueLabel:'Token (aparece só agora)', copyLabel:'Copiar token',
    msg:'Olá, ' + d.nome.split(' ')[0] + '! Seu token para criar uma nova senha: ' + d.token + ' (vale até ' + ate + '). Acesse ' + url + ' e informe seu e-mail, o token e a nova senha.',
    note:'Válido até <b>' + esc(ate) + '</b> e de uso único. Envie ao closer por um canal seguro. Gerar outro token invalida este.' });
}
async function tmAccPortalLink(){ toast(await tmCopy(tmAbs('/closer/login')) ? 'Link do portal copiado' : 'Não foi possível copiar', false); }

// Menu "Gerenciar acesso" (itens mudam conforme o status)
function tmAccMenuItems(closerId){
  const a = tmAccMap.get(closerId) || null, st = a ? a.status : 'sem', it = [{ title:'Acesso ao portal' }];
  if (st === 'sem')       it.push(['acc-invite', ''+ICO.mail+'', 'Enviar convite de registro']);
  if (st === 'pendente')  it.push(['acc-approve', ''+ICO.check+'', 'Aprovar acesso'], ['acc-reject', ''+ICO.xcircle+'', 'Rejeitar acesso', 'danger']);
  if (st === 'ativo')     it.push(['acc-revoke', ''+ICO.lock+'', 'Revogar acesso', 'danger'], ['acc-reset', ''+ICO.key+'', a.reset_pedido_em ? 'Gerar token (pediu nova senha)' : 'Gerar token de nova senha'], ['acc-portal', ''+ICO.link+'', 'Copiar link do portal']);
  if (st === 'rejeitado') it.push(['acc-reconsider', ''+ICO.refresh+'', 'Reconsiderar (volta a pendente)'], ['acc-delete', ''+ICO.trash+'', 'Excluir pedido', 'danger']);
  return it;
}
function tmAccAction(m, closerId){
  const a = tmAccMap.get(closerId) || null;
  if (m === 'acc-invite') return tmAccInvite(closerId);
  if (!a) return;
  if (m === 'acc-approve') return tmAccApprove(a.id);
  if (m === 'acc-reject') return tmAccReject(a.id);
  if (m === 'acc-revoke') return tmAccRevoke(a.id);
  if (m === 'acc-reconsider') return tmAccReconsider(a.id);
  if (m === 'acc-reset') return tmAccResetToken(a.id);
  if (m === 'acc-portal') return tmAccPortalLink();
  if (m === 'acc-delete') return tmAccDelete(a.id);
}
// botões dos cartões "Solicitações de acesso" (pedidos sem vínculo)
document.addEventListener('click', e => {
  const b = e.target.closest('#tmReqs [data-ra]'); if (!b) return;
  const id = Number(b.closest('.tm-req').dataset.accId), act = b.dataset.ra;
  if (act === 'approve') tmAccApprove(id); else if (act === 'edit') tmAccEditOpen(id); else if (act === 'reject') tmAccReject(id);
  else if (act === 'reconsider') tmAccReconsider(id); else if (act === 'delete') tmAccDelete(id);
});

// ── Equipes: drawer de criar/editar + exclusão ──────────────────────────────
function resetEqFields(){
  tmEl('eqId').value = '';
  tmEl('eqNome').value = '';
  tmEl('eqCor').value = '#d4af37';
  tmEl('eqOcultaTv').checked = false;
  fillEqLider(null, '', '');
  removeImg('eqFoto', 'eqUploadArea', 'eqPlaceholder', 'eqPreviewWrap');
}
function resetEqForm(){
  resetEqFields();
  const d = tmEl('tmDrawerEq'); if (d.open) d.close();
}
function tmOpenTeamDrawer(id){
  resetEqFields();
  const eq = id ? allEquipes.find(e => e.id == id) : null;
  if (id && !eq) return;
  if (eq) {
    tmEl('eqId').value = eq.id;
    tmEl('eqNome').value = eq.nome;
    tmEl('eqCor').value = eq.cor || '#d4af37';
    tmEl('eqOcultaTv').checked = Number(eq.oculta_tv) === 1;
    fillEqLider(eq.id, eq.lider_id, eq.lider_nome);
    if (eq.imageUrl) setPreview(eq.imageUrl, 'eqPreviewImg', 'eqUploadArea', 'eqPlaceholder', 'eqPreviewWrap');
  }
  tmEl('eqEyebrow').textContent = eq ? 'Editar equipe' : 'Nova equipe';
  tmEl('eqDrawerTitle').textContent = eq ? eq.nome : 'Criar equipe';
  tmEl('eqSubmitBtn').textContent = eq ? 'Salvar' : 'Criar Equipe';
  tmEl('tmDrawerEq').showModal();
  tmEl('eqNome').focus();
}
function editEquipe(id){ tmOpenTeamDrawer(id); }
async function submitEquipe(e){
  e.preventDefault();
  const id = tmEl('eqId').value;
  const fd = new FormData();
  fd.append('action', id ? 'update_equipe' : 'add_equipe');
  if (id) fd.append('id', id);
  fd.append('nome', tmEl('eqNome').value);
  fd.append('cor', tmEl('eqCor').value);
  fd.append('lider_id', tmEl('eqLider').value || 0);
  fd.append('oculta_tv', tmEl('eqOcultaTv').checked ? 1 : 0);
  const foto = tmEl('eqFoto').files[0]; if (foto) fd.append('foto', foto);
  try {
    const d = await fetch(API_URL, { method:'POST', body:fd }).then(r => r.json());
    if (d.success) {
      toast(d.message);
      if (!id && d.id) tmPreselect(d.id);
      resetEqForm(); await tmReloadAll();
    } else toast(d.message, true);
  } catch { toast('Erro de conexão', true); }
  return false;
}
// Preenche o select de líder com os closers da própria equipe. Um líder que
// esteja de férias some do get_data, então é reinserido a partir do nome que o
// get_equipes devolveu — senão abrir a edição zeraria o líder sem querer.
function fillEqLider(equipeId, selectedId, selectedNome){
  const sel = tmEl('eqLider'); if (!sel) return;
  const membros = equipeId ? allElenco.filter(c => String(c.equipe_id) === String(equipeId)) : [];
  let html = '<option value="">— sem líder —</option>' +
    membros.map(c => `<option value="${c.id}">${esc(c.name || c.nome || '')}</option>`).join('');
  if (selectedId && !membros.some(c => String(c.id) === String(selectedId)))
    html += `<option value="${selectedId}">${esc(selectedNome || 'closer fora da lista')}</option>`;
  sel.innerHTML = html;
  sel.value = selectedId ? String(selectedId) : '';
}
async function deleteEquipe(id){
  const eq = allEquipes.find(e => e.id == id); if (!eq) return;
  const n = eq.total_closers || 0;
  const ok = await tmConfirm('Excluir equipe “' + eq.nome + '”?',
    n ? 'Os ' + n + ' closer(s) desta equipe ficarão sem equipe. Nenhum contrato é apagado.' : 'Esta ação não pode ser desfeita.', 'Excluir equipe');
  if (!ok) return;
  const d = await tmApi({ action:'delete_equipe', id });
  if (d.success) { toast(d.message); await tmReloadAll(); } else toast(d.message, true);
}

// ── Closers: drawer de criar/editar ─────────────────────────────────────────
function populateClEqSel(){
  const sel = tmEl('clEquipe'); if (!sel) return;
  const cur = sel.value;
  sel.innerHTML = '<option value="">Sem equipe</option>' + allEquipes.map(eq => `<option value="${eq.id}" ${String(eq.id) === String(cur) ? 'selected' : ''}>${esc(eq.nome)}</option>`).join('');
}
function resetClFields(){
  tmEl('clId').value = '';
  tmEl('clNome').value = '';
  tmEl('clEquipe').value = '';
  tmEl('clCor').value = '#d4af37';
  removeImg('clFoto', 'clUploadArea', 'clPlaceholder', 'clPreviewWrap');
}
function resetClForm(){
  resetClFields();
  const d = tmEl('tmDrawerCl'); if (d.open) d.close();
}
function tmOpenCloserDrawer(id){
  resetClFields(); populateClEqSel();
  const c = id ? tmCloserList().find(x => x.id === Number(id)) : null;
  if (id && !c) return;
  if (c) {
    tmEl('clId').value = c.id;
    tmEl('clNome').value = c.nome;
    tmEl('clCor').value = c.color || '#d4af37';
    tmEl('clEquipe').value = c.equipe_id || '';
    if (c.imageUrl) setPreview(c.imageUrl, 'clPreviewImg', 'clUploadArea', 'clPlaceholder', 'clPreviewWrap');
  } else if (/^\d+$/.test(tmSelTeam)) {
    tmEl('clEquipe').value = tmSelTeam;
  }
  tmEl('clEyebrow').textContent = c ? 'Editar closer' : 'Novo closer';
  tmEl('clDrawerTitle').textContent = c ? c.nome : 'Adicionar closer';
  tmEl('clSubmitBtn').textContent = c ? 'Salvar' : 'Adicionar Closer';
  tmEl('tmDrawerCl').showModal();
  tmEl('clNome').focus();
}
function editCloser(id){ tmOpenCloserDrawer(id); }
async function submitCloser(e){
  e.preventDefault();
  const id = tmEl('clId').value;
  const fd = new FormData();
  fd.append('action', id ? 'update_vendedor' : 'add_vendedor');
  if (id) fd.append('id', id);
  fd.append('nome', tmEl('clNome').value);
  fd.append('cor', tmEl('clCor').value);
  const eq = tmEl('clEquipe').value; fd.append('equipe_id', eq);
  const foto = tmEl('clFoto').files[0]; if (foto) fd.append('foto', foto);
  try {
    const d = await fetch(API_URL, { method:'POST', body:fd }).then(r => r.json());
    if (d.success) {
      toast(d.message);
      if (!id) tmPreselect(eq || 'none');        // mostra o closer recém-criado
      resetClForm(); await tmReloadAll();
    } else toast(d.message, true);
  } catch { toast('Erro de conexão', true); }
  return false;
}

