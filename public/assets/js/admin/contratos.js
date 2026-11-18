// ══════════════════════════════════════════════════════════════════════════
// CONTRATOS — Registro e Feed (venda rápida + timeline do dia) · Matriz de Auditoria
// dados: api.php?action=get_feed · feed_add · feed_update · feed_delete · get_matriz · save_tipos
// ══════════════════════════════════════════════════════════════════════════
const cvEl = id => document.getElementById(id);
const cvSvg = p => '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
const cvIco = (k, label) => tpIcon(k);   // ícone escolhido para o benefício (Metas & Regras → Tipos de Benefício)
const CV = { tab:'feed', day:'', hoje:'', seq:0, newId:null, edit:null, closer:'', ben:'', saving:false, items:[], sum:null };
try { const t = localStorage.getItem('cvTab'); if (t === 'feed' || t === 'matriz') CV.tab = t; } catch(e) {}
const CV_DOW = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
const cvDM  = iso => iso ? iso.slice(8,10) + '/' + iso.slice(5,7) : '';
const cvDow = iso => CV_DOW[new Date(iso + 'T12:00:00').getDay()];
const cvCor = k => tpCor(k);
const cvHoje = () => CV.hoje || tmTodayISO();
async function cvApi(action, params){
  try {
    const r = await fetch(API_URL, { method:'POST', body:new URLSearchParams(Object.assign({ action }, params)) });
    return await r.json();
  } catch(e) { return { success:false, message:'Erro de conexão' }; }
}

// ── abas ────────────────────────────────────────────────────────────────────
function cvShow(){ cvPopulate(); cvTab(CV.tab, true); }
async function cvTab(t, force){
  if (!force && t === CV.tab) return;
  if (CV.tab === 'matriz' && t !== 'matriz' && !(await cvMxGuard())) return;
  CV.tab = t; try { localStorage.setItem('cvTab', t); } catch(e) {}
  [['feed','Feed'], ['matriz','Matriz']].forEach(([k, n]) => {
    const on = k === t;
    cvEl('cvTab' + n).classList.toggle('active', on); cvEl('cvTab' + n).setAttribute('aria-selected', String(on));
    cvEl('cvPane' + n).hidden = !on;
  });
  if (t === 'feed') cvFeedLoad(); else cvMxEnter();
}

// ── feed do dia ─────────────────────────────────────────────────────────────
function cvDay(n){ const nd = tmAddDays(CV.day || cvHoje(), n); if (nd > cvHoje()) return; cvFeedLoad(nd); }
function cvDayToday(){ cvFeedLoad(cvHoje()); }
function cvDayLabel(iso){ if (iso === cvHoje()) return 'Hoje'; if (iso === tmAddDays(cvHoje(), -1)) return 'Ontem'; return cvDow(iso); }
async function cvFeedLoad(day){
  const seq = ++CV.seq;
  if (day) CV.day = day;
  const r = await fetch(API_URL+'?action=get_feed' + (CV.day ? '&data=' + CV.day : '')).then(x => x.json()).catch(() => null);
  if (seq !== CV.seq) return;
  if (!r || !r.success) { cvEl('cvFeed').innerHTML = '<li class="cv-empty"><b>Não foi possível carregar o feed</b>Tente novamente em instantes.</li>'; return; }
  CV.hoje = r.hoje; CV.day = r.data; CV.items = r.items; CV.sum = r;
  cvFeedRender();
}
function cvItemHtml(it){
  return '<li class="cv-item' + (it.id === CV.newId ? ' cv-new' : '') + '" data-id="' + it.id + '" style="--tc:' + esc(it.cor) + '">'
    + '<time class="cv-time"' + (it.hora ? ' datetime="' + esc(CV.day + 'T' + it.hora) + '"' : ' title="Lançado antes do feed existir — sem horário"') + '>' + (it.hora ? esc(it.hora) : '—') + '</time>'
    + '<span class="cv-dot" aria-hidden="true"></span>'
    + '<div class="cv-card"><span class="cv-ib" aria-hidden="true">' + cvIco(it.tipo, it.label) + '</span>'
    + '<div class="cv-main"><span class="cv-who" title="' + esc(it.closer) + '">' + esc(it.closer) + '</span>'
    + '<span class="cv-what">' + esc(it.label) + (it.desc ? '<em> · ' + esc(it.desc) + '</em>' : '') + (it.origem === 'matriz' ? '<span class="cv-tag">via matriz</span>' : '') + '</span></div>'
    + '<span class="cv-q" aria-label="' + it.qtd + (it.qtd === 1 ? ' contrato' : ' contratos') + '">×' + it.qtd + '</span>'
    + '<button type="button" class="cv-kebab" data-id="' + it.id + '" aria-haspopup="menu" aria-expanded="false" aria-label="Ações: ' + esc(it.closer) + ', ' + esc(it.label) + '">' + TM_DOTS + '</button></div></li>';
}
function cvFeedRender(){
  const r = CV.sum; if (!r) return;
  const hojeDia = CV.day === CV.hoje;
  cvEl('cvDayLbl').innerHTML = esc(cvDayLabel(CV.day)) + '<small>' + esc(cvDow(CV.day).slice(0, 3) + ' ' + cvDM(CV.day)) + '</small>';
  cvEl('cvNextDay').disabled = CV.day >= CV.hoje;
  cvEl('cvFeed').innerHTML = r.items.length ? r.items.map(cvItemHtml).join('')
    : '<li class="cv-empty"><b>Nenhuma venda ' + (hojeDia ? 'registrada hoje' : 'neste dia') + '</b>' + (r.total_dia ? 'Há ' + r.total_dia + (r.total_dia === 1 ? ' contrato lançado direto no total do dia, sem benefício informado.' : ' contratos lançados direto no total do dia, sem benefício informado.') : 'Toque em “Registrar contrato rápido” para lançar a primeira.') + '</li>';
  CV.newId = null;
  cvSumRender(r);
}
function cvCount(el, to){
  const from = parseInt(el.textContent, 10) || 0;
  if (from === to || window.matchMedia('(prefers-reduced-motion:reduce)').matches) { el.textContent = to; return; }
  const t0 = performance.now(), dur = 380;
  (function step(t){ const p = Math.min(1, (t - t0) / dur); el.textContent = Math.round(from + (to - from) * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); })(t0);
}
function cvSumRender(r){
  const hojeDia = CV.day === CV.hoje;
  cvEl('cvSumT').textContent = hojeDia ? 'Resumo do dia' : 'Resumo de ' + cvDM(CV.day);
  cvCount(cvEl('cvSumN'), r.total_dia);
  cvEl('cvSumL').textContent = (r.total_dia === 1 ? 'contrato ' : 'contratos ') + (hojeDia ? 'hoje' : CV.day === tmAddDays(CV.hoje, -1) ? 'ontem' : 'em ' + cvDM(CV.day));
  cvEl('cvSumChips').innerHTML = r.por_tipo.map(t => '<span class="cv-chipb" style="--tc:' + esc(t.cor) + '"><i></i>' + esc(t.label) + ' <b>' + t.qtd + '</b></span>').join('');
  const n = cvEl('cvSumNote'); n.hidden = !(r.sem_tipo > 0);
  if (r.sem_tipo > 0) n.textContent = r.sem_tipo + (r.sem_tipo === 1 ? ' contrato sem benefício informado' : ' contratos sem benefício informado') + ' (lançados direto no total do dia).';
}

// ── menu ⋮ do lançamento ────────────────────────────────────────────────────
let cvMenuId = null;
function cvMenuClose(refocus){
  const m = cvEl('cvMenu'); if (m.hidden) return;
  m.hidden = true;
  const b = document.querySelector('.cv-kebab[aria-expanded="true"]');
  document.querySelectorAll('.cv-kebab[aria-expanded="true"]').forEach(x => x.setAttribute('aria-expanded', 'false'));
  if (refocus && b) b.focus();
}
function cvMenuOpen(btn, id){
  const m = cvEl('cvMenu');
  if (!m.hidden && cvMenuId === id) { cvMenuClose(); return; }
  cvMenuClose(); cvMenuId = id; m.hidden = false; btn.setAttribute('aria-expanded', 'true');
  const r = btn.getBoundingClientRect(), w = m.offsetWidth, h = m.offsetHeight;
  m.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)) + 'px';
  m.style.top = (r.bottom + h + 12 > window.innerHeight ? Math.max(8, r.top - h - 6) : r.bottom + 6) + 'px';
  m.querySelector('button').focus();
}
function cvEdit(id){ const it = CV.items.find(x => x.id === id); if (it) cvOpen(it); }
async function cvDel(id){
  const it = CV.items.find(x => x.id === id); if (!it) return;
  const ok = await tmConfirm('Excluir esta venda?', it.closer + ' · ' + it.label + ' ×' + it.qtd + (it.hora ? ' · ' + it.hora : '') + '. Os totais do dia serão ajustados.', 'Excluir');
  if (!ok) return;
  const li = document.querySelector('.cv-item[data-id="' + id + '"]'); if (li) li.classList.add('cv-out');
  const r = await cvApi('feed_delete', { id });
  if (!r.success) { if (li) li.classList.remove('cv-out'); toast(r.message || 'Não foi possível excluir', true); return; }
  toast('Venda excluída');
  setTimeout(() => cvAfterChange(), 260);
}
// depois de qualquer mudança: recarrega o que está na tela e atualiza ranking/painel
async function cvAfterChange(day){
  if (day) CV.day = day;
  const onTab = cvEl('tabContratos').classList.contains('active');
  const job = !onTab ? null : CV.tab === 'feed' ? cvFeedLoad() : (MX.dirty.size ? null : cvMxLoad());
  fetchData(); pnReload();
  if (job) await job;
}

// ── modal "Venda rápida" (novo e edição) ───────────────────────────────────
function cvLast(){ try { const v = localStorage.getItem('cvLastCloser'); return allElenco.some(c => String(c.id) === v) ? v : ''; } catch(e) { return ''; } }
function cvClosersList(){
  const l = [...allElenco].sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'));
  if (CV.edit && !l.some(c => String(c.id) === String(CV.edit.vendedor_id))) l.push({ id:CV.edit.vendedor_id, name:CV.edit.closer, equipe_nome:CV.edit.equipe });
  return l;
}
function cvQtyVal(){ const n = parseInt(cvEl('cvQty').value, 10); return isNaN(n) ? 0 : n; }
function cvStep(d){ cvEl('cvQty').value = Math.max(1, Math.min(99, (cvQtyVal() || 1) + d)); cvRecap(); }
function cvSyncChips(){
  const v = cvEl('cvDate').value;
  document.querySelectorAll('#cvDlg .cv-chip').forEach(b => b.setAttribute('aria-pressed', String(v === tmAddDays(cvHoje(), +b.dataset.d))));
}
function cvBensRender(){
  const list = TIPOS.map((k, i) => ({ k, label:TIPO_LABELS[i], cor:cvCor(k) }));
  if (CV.edit && !TIPOS.includes(CV.edit.tipo)) list.push({ k:CV.edit.tipo, label:CV.edit.label, cor:CV.edit.cor });
  cvEl('cvBens').innerHTML = list.map(b => '<button type="button" class="cv-ben" role="radio" aria-checked="' + (b.k === CV.ben) + '" data-k="' + esc(b.k) + '" style="--bc:' + esc(b.cor) + '">' + cvIco(b.k, b.label) + '<span>' + esc(b.label) + '</span></button>').join('');
  cvEl('cvDesc').hidden = CV.ben !== 'outros';
}
function cvBensMark(){
  cvEl('cvBens').querySelectorAll('.cv-ben').forEach(b => b.setAttribute('aria-checked', String(b.dataset.k === CV.ben)));
  cvEl('cvDesc').hidden = CV.ben !== 'outros';
}
function cvCbRender(q){
  const n = tmNorm(q), l = cvClosersList().filter(c => !n || tmNorm(c.name + ' ' + (c.equipe_nome || '')).includes(n));
  cvEl('cvCbList').innerHTML = l.length ? l.map(c => '<li class="cv-cb-it" role="option" data-id="' + c.id + '" aria-selected="' + (String(c.id) === CV.closer) + '"><span class="cv-av" aria-hidden="true">' + esc(tmInitials(c.name)) + '</span><span><b>' + esc(c.name) + '</b><small>' + esc(c.equipe_nome || 'Sem equipe') + '</small></span></li>').join('')
    : '<li class="cv-cb-empty">Nenhum closer encontrado.</li>';
}
function cvCbSet(){
  const c = cvClosersList().find(x => String(x.id) === CV.closer);
  const t = cvEl('cvCbTxt'); t.textContent = c ? c.name : 'Selecione o closer'; t.classList.toggle('ph', !c);
  cvEl('cvCbAv').textContent = c ? tmInitials(c.name) : '?';
}
function cvCbToggle(open){
  cvEl('cvCbPop').hidden = !open; cvEl('cvCbBtn').setAttribute('aria-expanded', String(open));
  if (open) { cvEl('cvCbQ').value = ''; cvCbRender(''); cvEl('cvCbQ').focus(); const s = cvEl('cvCbList').querySelector('[aria-selected="true"]'); if (s) s.scrollIntoView({ block:'nearest' }); }
}
function cvCbPick(id){
  CV.closer = String(id); cvCbSet(); cvCbToggle(false); cvRecap(); cvEl('cvErr').textContent = '';
  const b = cvEl('cvBens').querySelector('.cv-ben'); if (b) b.focus();
}
function cvCbHl(d){
  const its = [...cvEl('cvCbList').querySelectorAll('.cv-cb-it')]; if (!its.length) return;
  let i = its.findIndex(x => x.classList.contains('hl')); its.forEach(x => x.classList.remove('hl'));
  i = Math.max(0, Math.min(its.length - 1, i + d)); its[i].classList.add('hl'); its[i].scrollIntoView({ block:'nearest' });
}
function cvRecap(){
  const c = cvClosersList().find(x => String(x.id) === CV.closer), q = cvQtyVal(), date = cvEl('cvDate').value;
  const i = TIPOS.indexOf(CV.ben), label = i >= 0 ? TIPO_LABELS[i] : (CV.edit && CV.edit.tipo === CV.ben ? CV.edit.label : '');
  const ok = !!c && !!CV.ben && q >= 1 && q <= 99 && !!date;
  cvEl('cvRecap').innerHTML = ok ? '<b>' + esc(c.name) + '</b> · ' + esc(label) + ' · <b>×' + q + '</b> · ' + cvDM(date) : 'Escolha o closer e o benefício para registrar.';
  cvEl('cvOk').disabled = !ok; cvEl('cvAgain').disabled = !ok;
}
function cvOpen(item){
  if (!allElenco.length) { toast('Closers ainda carregando — tente em instantes', true); return; }
  pdClose(false); bellClose(false); sbCloseDrawer(false); cvMenuClose();
  const hoje = cvHoje(); CV.edit = item && item.id ? item : null; CV.saving = false;
  cvEl('cvDlgTitle').textContent = CV.edit ? 'Editar contrato' : 'Contrato rápido';
  cvEl('cvOk').textContent = CV.edit ? 'Salvar alterações' : 'Registrar contrato';
  cvEl('cvAgain').hidden = !!CV.edit;
  const di = cvEl('cvDate'); di.max = hoje; di.value = CV.edit ? CV.day : hoje;
  CV.closer = CV.edit ? String(CV.edit.vendedor_id) : cvLast();
  CV.ben = CV.edit ? CV.edit.tipo : '';
  cvEl('cvQty').value = CV.edit ? CV.edit.qtd : 1;
  cvEl('cvDesc').value = CV.edit ? (CV.edit.desc || '') : '';
  cvEl('cvErr').textContent = '';
  cvBensRender(); cvCbSet(); cvCbToggle(false); cvSyncChips(); cvRecap();
  const dlg = cvEl('cvDlg'); if (!dlg.open) dlg.showModal();
  (CV.closer ? cvEl('cvBens').querySelector('.cv-ben') : cvEl('cvCbBtn')).focus();
}
function cvClose(){ const d = cvEl('cvDlg'); if (d.open) d.close(); }
async function cvSubmit(e, again){
  if (e) e.preventDefault();
  if (CV.saving) return false;
  const q = cvQtyVal(), data = cvEl('cvDate').value;
  const err = !data ? 'Informe a data.' : data > cvHoje() ? 'Não é possível registrar vendas em data futura.' : !CV.closer ? 'Escolha o closer.' : !CV.ben ? 'Escolha o benefício.' : !(q >= 1 && q <= 99) ? 'A quantidade deve ser de 1 a 99.' : '';
  if (err) { cvEl('cvErr').textContent = err; return false; }
  CV.saving = true; cvEl('cvOk').disabled = true; cvEl('cvAgain').disabled = true; cvEl('cvErr').textContent = '';
  const p = { vendedor_id:CV.closer, data_registro:data, tipo:CV.ben, quantidade:q, descricao:CV.ben === 'outros' ? cvEl('cvDesc').value.trim() : '' };
  const r = CV.edit ? await cvApi('feed_update', Object.assign({ id:CV.edit.id }, p)) : await cvApi('feed_add', p);
  CV.saving = false;
  if (!r.success) { cvEl('cvErr').textContent = r.message || 'Não foi possível salvar.'; cvRecap(); return false; }
  try { localStorage.setItem('cvLastCloser', CV.closer); } catch(x) {}
  const c = cvClosersList().find(x => String(x.id) === CV.closer), i = TIPOS.indexOf(CV.ben);
  toast(CV.edit ? 'Venda atualizada' : '+' + q + ' ' + (i >= 0 ? TIPO_LABELS[i] : CV.ben) + (c ? ' · ' + c.name : ''));
  CV.newId = CV.edit ? CV.edit.id : r.id;
  if (again && !CV.edit) {                       // mantém data e closer; limpa benefício e quantidade para o próximo lançamento
    CV.ben = ''; cvEl('cvQty').value = 1; cvEl('cvDesc').value = ''; cvBensMark(); cvRecap();
    const b = cvEl('cvBens').querySelector('.cv-ben'); if (b) b.focus();
  } else cvClose();
  cvAfterChange(data);
  return false;
}

// ── matriz de auditoria ─────────────────────────────────────────────────────
const MX = { year:viewYear, month:viewMonth, weeks:[], widx:0, vid:'0', data:null, seq:0, dirty:new Set() };
function cvPopulate(){
  const sel = cvEl('cvMxCloser'), cur = sel.value || '0';
  sel.innerHTML = '<option value="0">Todos os closers (consolidado)</option>' + [...allElenco].sort((a, b) => a.name.localeCompare(b.name, 'pt-BR')).map(v => '<option value="' + v.id + '">' + esc(v.name) + '</option>').join('');
  sel.value = [...sel.options].some(o => o.value === cur) ? cur : '0'; MX.vid = sel.value;
}
async function cvMxGuard(){
  const n = MX.dirty.size; if (!n) return true;
  return await tmConfirm('Descartar alterações?', n + (n === 1 ? ' célula alterada ainda não foi salva.' : ' células alteradas ainda não foram salvas.'), 'Descartar');
}
function cvMxEnter(){
  if (MX.dirty.size) return;                     // edições pendentes continuam na tela
  if (!MX.weeks.length) cvMxLoadWeeks(); else cvMxLoad();
}
async function cvMxMonth(d){
  if (!(await cvMxGuard())) return;
  MX.month += d; if (MX.month > 12) { MX.month = 1; MX.year++; } if (MX.month < 1) { MX.month = 12; MX.year--; }
  cvMxLoadWeeks();
}
async function cvMxLoadWeeks(){
  cvEl('cvMxMonth').textContent = ptMonths[MX.month - 1] + ' ' + MX.year;
  const d = await fetch(API_URL+'?action=get_month_weeks&ano=' + MX.year + '&mes=' + MX.month).then(r => r.json()).catch(() => null);
  MX.weeks = (d && d.data) || [];
  const b = cvEl('cvMxBadge');
  if (d && d.configured) { b.textContent = 'Configurado'; b.style.cssText = 'display:inline-block;border-color:rgba(232,200,74,.3);background:rgba(232,200,74,.07);color:#e8c84a;'; }
  else { b.textContent = 'Auto-calculado'; b.style.cssText = 'display:inline-block;border-color:rgba(212,175,55,.2);background:rgba(212,175,55,.05);color:rgba(212,175,55,.65);'; }
  const today = cvHoje(); MX.widx = Math.max(0, MX.weeks.length - 1);
  for (let i = 0; i < MX.weeks.length; i++) {
    if (MX.weeks[i].data_inicio <= today && MX.weeks[i].data_fim >= today) { MX.widx = i; break; }
    if (MX.weeks[i].data_inicio <= today) MX.widx = i;
  }
  cvMxWeekTabs(); cvMxLoad();
}
function cvMxWeekTabs(){
  const el = cvEl('cvMxWeeks');
  el.innerHTML = MX.weeks.length ? MX.weeks.map((w, i) => '<button type="button" class="vs-tab' + (i === MX.widx ? ' active' : '') + '" aria-pressed="' + (i === MX.widx) + '" onclick="cvMxWeek(' + i + ')">S' + w.semana_num + ': ' + cvDM(w.data_inicio) + '–' + cvDM(w.data_fim) + '</button>').join('')
    : '<div style="color:rgba(212,175,55,.5);font-size:11px;align-self:center">Nenhuma semana para este mês. Configure na aba Calendário.</div>';
}
async function cvMxWeek(i){ if (!(await cvMxGuard())) return; MX.widx = i; cvMxWeekTabs(); cvMxLoad(); }
async function cvMxCloser(sel){ if (!(await cvMxGuard())) { sel.value = MX.vid; return; } MX.vid = sel.value; cvMxLoad(); }
async function cvMxLoad(){
  const w = MX.weeks[MX.widx], dates = (w && w.dates) || [];
  if (!dates.length) { MX.data = null; MX.dirty.clear(); cvMxRender(); return; }
  const seq = ++MX.seq;
  const r = await fetch(API_URL+'?action=get_matriz&start=' + dates[0] + '&end=' + dates[dates.length - 1] + '&vendedor_id=' + MX.vid).then(x => x.json()).catch(() => null);
  if (seq !== MX.seq) return;
  if (!r || !r.success) { cvEl('cvMx').innerHTML = '<tbody><tr><td style="padding:30px;color:var(--muted)">Não foi possível carregar a matriz.</td></tr></tbody>'; return; }
  MX.data = r; MX.dirty.clear(); CV.hoje = r.hoje || CV.hoje;
  cvMxRender();
}
function cvMxRender(){
  const d = MX.data, w = MX.weeks[MX.widx], tbl = cvEl('cvMx'), ro = MX.vid === '0';
  cvEl('cvMxNote').innerHTML = ro ? '<b>Visão consolidada</b> de todos os closers — somente leitura. Escolha um closer para editar os números.'
    : 'Edite os números e clique em <b>Salvar alterações</b>. As células alteradas ficam em amarelo.';
  cvEl('cvMxBar').hidden = ro;
  if (!d || !w || !w.dates || !w.dates.length) { tbl.innerHTML = '<tbody><tr><td style="padding:30px;color:var(--muted)">Nenhuma semana configurada para este mês.</td></tr></tbody>'; cvMxStatus(); return; }
  const dates = w.dates, today = cvHoje();
  let h = '<thead><tr><th class="c0" scope="col">Tipo de benefício</th>' + dates.map(dt => '<th scope="col" class="' + (dt === today ? 'today' : '') + '">' + cvDM(dt) + '<small>' + cvDow(dt).slice(0, 3) + '</small></th>').join('') + '<th scope="col" class="tot">Total da semana</th></tr></thead><tbody>';
  d.tipos.forEach(t => {
    h += '<tr data-k="' + esc(t.k) + '"><th scope="row" class="c0" style="--tc:' + esc(t.cor) + '" title="' + esc(t.label) + '"><i class="tpi">' + tpIcon(t.k) + '</i>' + esc(t.label) + (t.inativo ? ' <em>(inativo)</em>' : '') + '</th>';
    dates.forEach((dt, i) => {
      const q = (d.cells[dt] && d.cells[dt][t.k]) || 0, fixo = ro || t.inativo;
      h += '<td class="' + (dt === today ? 'today' : '') + '">' + (fixo
        ? '<span class="cv-ro' + (q ? '' : ' zero') + '" data-k="' + esc(t.k) + '" data-i="' + i + '">' + q + '</span>'
        : '<input class="cv-cell' + (q ? '' : ' zero') + '" type="number" min="0" max="999" inputmode="numeric" value="' + q + '" data-k="' + esc(t.k) + '" data-i="' + i + '" data-o="' + q + '" aria-label="' + esc(t.label) + ' em ' + cvDM(dt) + '">') + '</td>';
    });
    h += '<td class="tot" data-rt="' + esc(t.k) + '">0</td></tr>';
    if (t.k === 'outros' && !ro) {
      h += '<tr class="desc"><th scope="row" class="c0">↳ Descrição</th>' + dates.map((dt, i) => '<td class="' + (dt === today ? 'today' : '') + '"><input class="cv-desc" type="text" maxlength="100" placeholder="qual?" value="' + esc(d.desc[dt] || '') + '" data-o="' + esc(d.desc[dt] || '') + '" data-i="' + i + '" aria-label="Descrição de Outros em ' + cvDM(dt) + '"></td>').join('') + '<td class="tot"></td></tr>';
    }
  });
  if (dates.some(dt => d.sem_tipo[dt])) {          // informativo: contratos lançados direto no total do dia (fora da soma abaixo)
    h += '<tr class="sem"><th scope="row" class="c0" title="Contratos lançados direto no total do dia, sem benefício. Ao salvar o dia, o total passa a ser a soma dos benefícios.">Sem benefício informado</th>' + dates.map((dt, i) => '<td data-si="' + i + '">' + (d.sem_tipo[dt] || '') + '</td>').join('') + '<td></td></tr>';
  }
  h += '</tbody><tfoot><tr><th scope="row" class="c0">TOTAL DO DIA</th>' + dates.map((dt, i) => '<td class="' + (dt === today ? 'today' : '') + '" data-ct="' + i + '">0</td>').join('') + '<td class="grand" data-grand>0</td></tr>';
  tbl.innerHTML = h + '</tfoot>';
  cvMxTotals(); cvMxStatus();
}
function cvMxTotals(){
  const rows = {}, cols = {}; let all = 0;
  cvEl('cvMx').querySelectorAll('[data-k][data-i]').forEach(el => {
    const v = Math.max(0, parseInt(el.value !== undefined && el.tagName === 'INPUT' ? el.value : el.textContent, 10) || 0);
    rows[el.dataset.k] = (rows[el.dataset.k] || 0) + v; cols[el.dataset.i] = (cols[el.dataset.i] || 0) + v; all += v;
  });
  cvEl('cvMx').querySelectorAll('[data-si]').forEach(td => { const v = parseInt(td.textContent, 10) || 0; cols[td.dataset.si] = (cols[td.dataset.si] || 0) + v; all += v; });
  cvEl('cvMx').querySelectorAll('[data-rt]').forEach(td => { td.textContent = rows[td.dataset.rt] || 0; });
  cvEl('cvMx').querySelectorAll('[data-ct]').forEach(td => { td.textContent = cols[td.dataset.ct] || 0; });
  const g = cvEl('cvMx').querySelector('[data-grand]'); if (g) g.textContent = all;
}
function cvMxStatus(){
  const n = MX.dirty.size, ro = MX.vid === '0';
  const s = cvEl('cvMxStatus'); s.textContent = n ? n + (n === 1 ? ' alteração não salva' : ' alterações não salvas') : 'Sem alterações'; s.classList.toggle('d', n > 0);
  cvEl('cvMxSave').disabled = ro || !n; cvEl('cvMxDiscard').disabled = ro || !n;
}
async function cvMxDiscard(){ if (!MX.dirty.size) return; if (!(await cvMxGuard())) return; cvMxLoad(); }
async function cvMxSave(){
  if (MX.vid === '0' || !MX.dirty.size) return;
  const w = MX.weeks[MX.widx], dates = (w && w.dates) || [];
  const dias = [...new Set([...MX.dirty].map(x => +x.split('|')[1]))].sort((a, b) => a - b);
  cvEl('cvMxSave').disabled = true;
  let salvos = 0;
  for (const i of dias) {
    const bd = new URLSearchParams({ action:'save_tipos', vendedor_id:MX.vid, data_registro:dates[i] });
    cvEl('cvMx').querySelectorAll('.cv-cell[data-i="' + i + '"]').forEach(inp => bd.append('tipos[' + inp.dataset.k + ']', String(Math.max(0, parseInt(inp.value, 10) || 0))));
    const dsc = cvEl('cvMx').querySelector('.cv-desc[data-i="' + i + '"]'); bd.append('outros_desc', dsc ? dsc.value.trim() : '');
    const r = await fetch(API_URL, { method:'POST', body:bd }).then(x => x.json()).catch(() => ({ success:false, message:'Erro de conexão' }));
    if (!r.success) { toast((r.message || 'Erro ao salvar') + ' (' + cvDM(dates[i]) + ')', true); break; }
    salvos++;
  }
  if (salvos) toast('Matriz salva (' + salvos + (salvos === 1 ? ' dia)' : ' dias)'));
  MX.dirty.clear(); await cvMxLoad(); fetchData(); pnReload();
}

// ── eventos ─────────────────────────────────────────────────────────────────
cvEl('cvFeed').addEventListener('click', e => { const b = e.target.closest('.cv-kebab'); if (b) cvMenuOpen(b, +b.dataset.id); });
cvEl('cvMenu').addEventListener('click', e => { const b = e.target.closest('button[data-act]'); if (!b) return; const id = cvMenuId; cvMenuClose(); if (b.dataset.act === 'edit') cvEdit(id); else cvDel(id); });
cvEl('cvMenu').addEventListener('keydown', e => {
  const bs = [...cvEl('cvMenu').querySelectorAll('button')], i = bs.indexOf(document.activeElement);
  if (e.key === 'ArrowDown') { e.preventDefault(); bs[(i + 1) % bs.length].focus(); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); bs[(i - 1 + bs.length) % bs.length].focus(); }
  else if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); cvMenuClose(true); }
});
document.addEventListener('pointerdown', e => { if (!cvEl('cvMenu').hidden && !cvEl('cvMenu').contains(e.target) && !e.target.closest('.cv-kebab')) cvMenuClose(); });
window.addEventListener('scroll', () => cvMenuClose(), true);
window.addEventListener('resize', () => cvMenuClose());

cvEl('cvBens').addEventListener('click', e => {
  const b = e.target.closest('.cv-ben'); if (!b) return;
  CV.ben = b.dataset.k; cvBensMark(); cvRecap(); cvEl('cvErr').textContent = '';
  if (CV.ben === 'outros') cvEl('cvDesc').focus();
});
cvEl('cvMinus').addEventListener('click', () => cvStep(-1));
cvEl('cvPlus').addEventListener('click', () => cvStep(1));
cvEl('cvQty').addEventListener('input', cvRecap);
cvEl('cvQty').addEventListener('blur', () => { cvEl('cvQty').value = Math.max(1, Math.min(99, cvQtyVal() || 1)); cvRecap(); });
cvEl('cvQty').addEventListener('focus', e => e.target.select());
cvEl('cvDate').addEventListener('input', () => { cvSyncChips(); cvRecap(); cvEl('cvErr').textContent = ''; });
document.querySelectorAll('#cvDlg .cv-chip').forEach(b => b.addEventListener('click', () => { cvEl('cvDate').value = tmAddDays(cvHoje(), +b.dataset.d); cvSyncChips(); cvRecap(); cvEl('cvErr').textContent = ''; }));
cvEl('cvCbBtn').addEventListener('click', () => cvCbToggle(cvEl('cvCbPop').hidden));
cvEl('cvCbQ').addEventListener('input', e => cvCbRender(e.target.value));
cvEl('cvCbQ').addEventListener('keydown', e => {
  if (e.key === 'ArrowDown') { e.preventDefault(); cvCbHl(1); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); cvCbHl(-1); }
  else if (e.key === 'Enter') { e.preventDefault(); const it = cvEl('cvCbList').querySelector('.cv-cb-it.hl') || cvEl('cvCbList').querySelector('.cv-cb-it'); if (it) cvCbPick(it.dataset.id); }
  else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cvCbToggle(false); cvEl('cvCbBtn').focus(); }   // um Esc fecha a lista (o campo de busca, sozinho, só limparia o texto)
});
cvEl('cvCbList').addEventListener('click', e => { const li = e.target.closest('.cv-cb-it'); if (li) cvCbPick(li.dataset.id); });
cvEl('cvDlg').addEventListener('cancel', e => { if (!cvEl('cvCbPop').hidden) { e.preventDefault(); cvCbToggle(false); cvEl('cvCbBtn').focus(); } });   // Esc fecha primeiro a lista de closers
cvEl('cvDlg').addEventListener('close', () => { cvCbToggle(false); });

cvEl('cvMx').addEventListener('input', e => {
  const t = e.target;
  if (t.classList.contains('cv-cell')) {
    let v = parseInt(t.value, 10); if (isNaN(v) || v < 0) v = 0; if (v > 999) { v = 999; t.value = '999'; }
    const key = t.dataset.k + '|' + t.dataset.i, dirty = v !== +t.dataset.o;
    t.classList.toggle('dirty', dirty); t.classList.toggle('zero', v === 0 && !dirty);
    if (dirty) MX.dirty.add(key); else MX.dirty.delete(key);
  } else if (t.classList.contains('cv-desc')) {
    const key = 'desc|' + t.dataset.i, dirty = t.value !== t.dataset.o;
    t.classList.toggle('dirty', dirty);
    if (dirty) MX.dirty.add(key); else MX.dirty.delete(key);
  } else return;
  cvMxTotals(); cvMxStatus();
});
cvEl('cvMx').addEventListener('focusin', e => { if (e.target.classList.contains('cv-cell')) e.target.select(); });
cvEl('cvMx').addEventListener('focusout', e => { const t = e.target; if (t.classList.contains('cv-cell') && t.value === '') t.value = '0'; });
cvEl('cvMx').addEventListener('keydown', e => {
  const t = e.target; if (!t.classList.contains('cv-cell')) return;
  const dir = e.key === 'ArrowUp' ? -1 : (e.key === 'ArrowDown' || e.key === 'Enter') ? 1 : 0; if (!dir) return;
  e.preventDefault();
  const col = [...cvEl('cvMx').querySelectorAll('.cv-cell[data-i="' + t.dataset.i + '"]')], j = col.indexOf(t) + dir;
  if (col[j]) col[j].focus();
});
window.addEventListener('beforeunload', e => { if (MX.dirty.size) { e.preventDefault(); e.returnValue = ''; } });

