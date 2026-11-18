// ══════════════════════════════════════════════════════════════════════════
// RANKINGS — placar de alta performance (pódio + leaderboard)
// ══════════════════════════════════════════════════════════════════════════
const RK_Y = ADMIN_BOOT.curYear, RK_M = ADMIN_BOOT.curMonth;
let rkScope = 'closer', rkPer = 'semana';
try {
  const s = localStorage.getItem('rkScope'), p = localStorage.getItem('rkPer');
  if (s === 'closer' || s === 'equipe') rkScope = s;
  if (p === 'semana' || p === 'mes') rkPer = p;
} catch(e) {}
let rkRef = '';              // '' = período atual · semana: 'ANO-MES-Nº' · mês: 'AAAA-MM'
let rkTb = null;             // critérios de desempate (mesma ordem usada pelo servidor)
let rkSeq = 0, rkBound = false;
const rkCache = {};
const rkCls = p => p < 40 ? 'lo' : (p < 80 ? 'mid' : 'hi');   // vermelho <40 · amarelo 40–79 · verde ≥80
const RK_CROWN = '<svg class="rk-crown" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 7l4.5 4L12 3l4.5 8L21 7l-2 12H5z"/><rect x="5" y="20" width="14" height="2" rx="1"/></svg>';
const RK_UP   = '<svg viewBox="0 0 10 10" width="10" height="10" aria-hidden="true"><path d="M5 1l4.5 8h-9z" fill="currentColor"/></svg>';
const RK_DOWN = '<svg viewBox="0 0 10 10" width="10" height="10" aria-hidden="true"><path d="M5 9L.5 1h9z" fill="currentColor"/></svg>';

// Clareia cores de equipe muito escuras (azul-marinho, preto…) pra continuarem visíveis no fundo escuro
function rkTone(hex){
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || ''); if (!m) return '#d4af37';
  const n = parseInt(m[1], 16), r = n >> 16, g = (n >> 8) & 255, b = n & 255;
  if ((0.2126 * r + 0.7152 * g + 0.0722 * b) / 255 >= 0.4) return '#' + m[1];
  const f = c => Math.round(c + (255 - c) * 0.55).toString(16).padStart(2, '0');
  return '#' + f(r) + f(g) + f(b);
}

// ── Controles (abas Closers/Equipes, toggle Semana/Mês, seletor de período) ──
function rkBind(){
  if (rkBound) return; rkBound = true;
  document.querySelectorAll('#tabRankings .rk-tab').forEach(b => b.addEventListener('click', () => {
    rkScope = b.dataset.scope; try { localStorage.setItem('rkScope', rkScope); } catch(e) {}
    rkSyncControls(); rkLoad();
  }));
  document.querySelectorAll('#tabRankings .rk-toggle button').forEach(b => b.addEventListener('click', () => {
    if (rkPer === b.dataset.per) return;
    rkPer = b.dataset.per; rkRef = ''; try { localStorage.setItem('rkPer', rkPer); } catch(e) {}
    rkSyncControls(); rkFillRef(); rkLoad();
  }));
  document.getElementById('rkRef').addEventListener('change', e => { rkRef = e.target.value; rkLoad(); });
}
function rkSyncControls(){
  document.querySelectorAll('#tabRankings .rk-tab').forEach(b => { const on = b.dataset.scope === rkScope; b.classList.toggle('active', on); b.setAttribute('aria-selected', on); });
  document.querySelectorAll('#tabRankings .rk-toggle button').forEach(b => { const on = b.dataset.per === rkPer; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on); });
}
function rkFillRef(){
  const sel = document.getElementById('rkRef'); if (!sel) return;
  const d = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) : '';
  let opts = [];
  if (rkPer === 'semana') {
    opts.push(['', 'Semana atual']);
    (monthWeeks || []).forEach(w => opts.push([RK_Y + '-' + RK_M + '-' + w.semana_num, w.label + ' · ' + d(w.start) + ' – ' + d(w.end)]));
  } else {
    opts.push(['', 'Mês atual']);
    for (let i = 1; i <= 11; i++) {
      const dt = new Date(RK_Y, RK_M - 1 - i, 1);
      opts.push([dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0'), ptMonths[dt.getMonth()] + ' ' + dt.getFullYear()]);
    }
  }
  sel.innerHTML = opts.map(([v, l]) => '<option value="' + v + '">' + esc(l) + '</option>').join('');
  sel.value = rkRef;
  if (sel.value !== rkRef) { rkRef = ''; sel.value = ''; }
}
function initRankingSelectors(){
  rkBind(); rkTb = null;          // recarrega os critérios de desempate (podem ter mudado na aba Metas)
  rkSyncControls(); rkFillRef();
}
function renderRankings(){ rkLoad(); }

// ── Dados ───────────────────────────────────────────────────────────────────
function rkParseRef(){
  if (rkPer === 'semana') {
    if (!rkRef) return { cur:true, ano:curSemAno, mes:curSemMes, num:curSemNum };
    const [a, m, n] = rkRef.split('-').map(Number); return { cur:false, ano:a, mes:m, num:n };
  }
  return { cur:!rkRef, ym: rkRef || (RK_Y + '-' + String(RK_M).padStart(2, '0')) };
}
// período atual: usa exatamente o que a TV mostra (get_data), na mesma ordem
function rkCurrentRows(scope, per){
  const sem = per === 'semana';
  if (scope === 'closer') {
    return (sem ? allClosers : allClosersMensal).map(v => ({
      id:Number(v.id), nome:v.name || '', equipe_id:v.equipe_id ? Number(v.equipe_id) : null,
      vendas:tmNum(sem ? v.vendas : v.vendas_mes), meta:tmNum(sem ? (v.meta_s_eff || v.metas) : v.meta_m_val),
      score:tmNum(sem ? v.score : v.score_mensal), tipos:(sem ? v.tipo_totals : v.mes_tipo_totals) || {}, conv:tmNum(sem ? v.conv_rate : v.conv_rate_mes) }));
  }
  return (sem ? allTeams : allTeamsMensal).map(t => ({
    id:Number(t.id), nome:t.nome || '', vendas:tmNum(sem ? t.vendas : t.vendas_mes), meta:tmNum(sem ? t.meta_s_eff : t.meta_m_eff),
    score:tmNum(sem ? t.score : t.score_mensal), tipos:(sem ? t.tipo_totals : t.mes_tipo_totals) || {}, conv:0 }));
}
// períodos passados / anterior (tendência): endpoint já existente, normalizado pro mesmo formato
async function rkFetchRows(scope, per, ref, fresh){
  const key = [scope, per, ref.ym || (ref.ano + '-' + ref.mes + '-' + ref.num)].join('|');
  const hit = rkCache[key];
  if (!fresh && hit && Date.now() - hit.t < 60000) return hit.rows;
  const qs = per === 'semana'
    ? 'type=semana&periodo=' + ref.num + '&ano=' + ref.ano + '&mes=' + ref.mes + '&ref_type=' + scope
    : 'type=mes&periodo=' + ref.ym + '&ref_type=' + scope;
  let rows = null;
  try {
    const d = await fetch(API_URL+'?action=get_rankings_filtered&' + qs).then(r => r.json());
    if (d.success && Array.isArray(d.data)) rows = d.data.map(r => per === 'semana'
      ? { id:Number(r.id), nome:r.name || '', equipe_id:r.equipe_id ? Number(r.equipe_id) : null, ex_closer:!!r.ex_closer, vendas:tmNum(r.vendas), meta:tmNum(r.meta_eff != null ? r.meta_eff : r.meta), score:tmNum(r.score), tipos:r.tipo_totals || {}, conv:tmNum(r.conv_rate) }
      : { id:Number(r.id), nome:r.name || '', equipe_id:r.equipe_id ? Number(r.equipe_id) : null, ex_closer:!!r.ex_closer, vendas:tmNum(r.vendas_mes), meta:tmNum(r.meta_mes), score:tmNum(r.score_mensal), tipos:r.mes_tipo_totals || {}, conv:tmNum(r.conv_rate_mes) });
  } catch(e) { console.error(e); }
  if (rows) rkCache[key] = { t:Date.now(), rows };
  return rows;
}
async function rkPrevRef(per, ref){
  if (per === 'mes') {
    const [y, m] = ref.ym.split('-').map(Number), d = new Date(y, m - 2, 1);
    return { ym: d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') };
  }
  if (!ref.num) return null;
  if (ref.num > 1) return { ano:ref.ano, mes:ref.mes, num:ref.num - 1 };
  const d = new Date(ref.ano, ref.mes - 2, 1), py = d.getFullYear(), pm = d.getMonth() + 1;   // 1ª semana → última do mês anterior
  try {
    const j = await fetch(API_URL+'?action=get_month_weeks&ano=' + py + '&mes=' + pm).then(r => r.json());
    const w = j.data || []; if (!w.length) return null;
    return { ano:py, mes:pm, num:w[w.length - 1].semana_num };
  } catch(e) { return null; }
}
async function rkEnsureTb(){
  if (rkTb) return rkTb;
  try { const d = await fetch(API_URL+'?action=get_tiebreaker').then(r => r.json()); rkTb = (d.success && Array.isArray(d.data)) ? d.data : [...DEFAULT_TB_ORDER]; }
  catch(e) { rkTb = [...DEFAULT_TB_ORDER]; }
  return rkTb;
}
function rkEnrich(rows){
  rows.forEach(r => {
    if (rkScope === 'closer') {
      const c = allClosers.find(x => Number(x.id) === r.id);
      if (c) { r.imageUrl = c.imageUrl || null; r.color = c.color || '#d4af37'; if (c.equipe_id) r.equipe_id = Number(c.equipe_id); }
      const e = r.equipe_id ? allEquipes.find(x => Number(x.id) === r.equipe_id) : null;
      r.team = e ? { nome:e.nome, cor:e.cor || '#d4af37' } : null;
    } else {
      const e = allEquipes.find(x => Number(x.id) === r.id);
      r.imageUrl = e ? (e.imageUrl || null) : null; r.color = e ? (e.cor || '#d4af37') : '#d4af37'; r.members = e ? (e.total_closers || 0) : 0;
    }
  });
  return rows;
}

// ── Desempate: replica a regra do servidor (tbCompareEntry) pra explicar o porquê ──
function rkTbVal(r, entry){
  if (entry && typeof entry === 'object' && entry.grupo) return entry.grupo.reduce((s, t) => s + (Number(r.tipos[t]) || 0), 0);
  if (entry === 'conv_rate') return Number(r.conv) || 0;
  return Number(r.tipos[entry]) || 0;
}
function rkWhy(a, b){
  for (const entry of (rkTb || [])) {
    const va = rkTbVal(a, entry), vb = rkTbVal(b, entry);
    if (entry === 'conv_rate' ? Math.abs(va - vb) > 0.001 : va !== vb) return { entry, va, vb, alpha:false };
  }
  return { alpha:true };
}
// marca quem venceu / perdeu o desempate entre vizinhos com a MESMA pontuação (0% não conta: ninguém fechou nada)
function rkAnnotate(rows){
  rows.forEach(r => { r.tie = null; });
  for (let i = 0; i < rows.length - 1; i++) {
    const a = rows[i], b = rows[i + 1];
    if (a.score !== b.score || a.score === 0) continue;
    const w = rkWhy(a, b);
    if (!a.tie) a.tie = Object.assign({ kind:'won', vs:b.nome }, w);
    const lastOfGroup = !rows[i + 2] || rows[i + 2].score !== b.score;
    if (lastOfGroup && !b.tie) b.tie = Object.assign({ kind:'lost', vs:a.nome }, w);
  }
}
function rkTieHtml(t){
  if (!t) return '';
  const wrap = (mod, head, sub) => '<span class="rk-tie-wrap ' + mod + '">'
    + '<span class="rk-tie-ico" tabindex="0" role="button" aria-label="' + esc(head) + '">'+ICO.scale+'</span>'
    + '<span class="rk-tie" role="tooltip">' + esc(head) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span></span>';
  if (t.alpha) return wrap('alpha', 'Todos os critérios empataram', 'Vale a ordem alfabética');
  const isConv = t.entry === 'conv_rate', lab = tbLabel(t.entry);
  const f = v => isConv ? String(v).replace('.', ',') + '%' : v + '×';
  const val = v => isConv ? lab + ' ' + f(v) : f(v) + ' ' + lab;
  const mine = t.kind === 'won' ? t.va : t.vb, other = t.kind === 'won' ? t.vb : t.va;
  return wrap(t.kind === 'lost' ? 'lost' : 'won',
    (t.kind === 'won' ? 'Desempatou por: ' : 'Perdeu o desempate: ') + val(mine),
    'Contra ' + t.vs + ': ' + val(other));
}

// ── Peças visuais ───────────────────────────────────────────────────────────
function rkBadgeHtml(r){
  if (rkScope === 'equipe') {
    const n = r.members || 0;
    return '<span class="rk-badge" style="--bc:' + rkTone(r.color) + '"><i></i>' + n + ' closer' + (n === 1 ? '' : 's') + '</span>';
  }
  return r.team ? '<span class="rk-badge" style="--bc:' + rkTone(r.team.cor) + '"><i></i>' + esc(r.team.nome) + '</span>'
                : '<span class="rk-badge none"><i></i>Sem equipe</span>';
}
function rkAvatarHtml(r){
  const ini = rkScope === 'equipe' ? (r.nome || '?').charAt(0).toUpperCase() : tmInitials(r.nome);
  return r.imageUrl ? tmImg(r.imageUrl, ini) : esc(ini);
}
function rkTrendHtml(r, pos, prevMap){
  if (!prevMap) return '<span class="rk-trend flat" title="Sem dados do período anterior para comparar">–</span>';
  const pp = prevMap.get(r.id);
  if (pp == null) return '<span class="rk-trend new" title="Não estava no ranking anterior">NOVO</span>';
  const d = pp - pos;
  if (d > 0) return '<span class="rk-trend up" title="Subiu ' + d + ' posição(ões) em relação ao período anterior (era #' + pp + ')">' + RK_UP + d + '</span>';
  if (d < 0) return '<span class="rk-trend down" title="Caiu ' + (-d) + ' posição(ões) em relação ao período anterior (era #' + pp + ')">' + RK_DOWN + (-d) + '</span>';
  return '<span class="rk-trend flat" title="Mesma posição do período anterior">=</span>';
}
function rkPodiumCard(r, pos, prevMap){
  const cls = rkCls(r.score), w = Math.min(100, r.score);
  return '<article class="rk-pc p' + pos + '" aria-label="' + pos + 'º lugar">'
    + (pos === 1 ? RK_CROWN : '<span class="rk-medal">' + pos + 'º</span>')
    + '<span class="rk-ptrend">' + rkTrendHtml(r, pos, prevMap) + '</span>'
    + '<div class="rk-pav">' + rkAvatarHtml(r) + '</div>'
    + '<div class="rk-pname" title="' + esc(r.nome) + '">' + esc(r.nome) + '</div>'
    + rkBadgeHtml(r) + rkTieHtml(r.tie)
    + '<div class="rk-pbar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + w + '"><i class="' + cls + '" style="width:' + w + '%"></i></div>'
    + '<div class="rk-pstat"><span><b>' + r.vendas + '/' + r.meta + '</b> Contratos</span><em class="rk-pct ' + cls + '">' + r.score + '%</em></div>'
    + (r.score >= 100 ? '<span class="rk-hit">'+ICO.target+' Meta batida</span>' : '')
    + '</article>';
}
function rkRow(r, pos, prevMap){
  const cls = rkCls(r.score), w = Math.min(100, r.score), left = Math.max(0, r.meta - r.vendas);
  const tint = r.score >= 80 ? ' hi' : (r.score < 30 ? ' lo' : '');
  return '<div class="rk-row' + tint + (pos <= 3 ? ' t' + pos : '') + (r.ex_closer ? ' ex' : '') + '">'
    + '<div class="rk-pos"><span class="rk-num">' + (r.ex_closer ? '—' : '#' + pos) + '</span>' + rkTrendHtml(r, pos, prevMap) + '</div>'
    + '<div class="rk-id"><div class="rk-av' + (rkScope === 'equipe' ? ' sq' : '') + '" style="--ac:' + rkTone(rkScope === 'equipe' ? r.color : (r.color || '#d4af37')) + '">' + rkAvatarHtml(r) + '</div>'
      + '<div class="rk-who"><div class="rk-name" title="' + esc(r.nome) + '">' + esc(r.nome) + '</div><div class="rk-meta">' + rkBadgeHtml(r) + rkTieHtml(r.tie) + (r.ex_closer ? '<span class="rk-ex">ex-closer</span>' : '') + '</div></div></div>'
    + '<div class="rk-bar-wrap"><div class="rk-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + w + '"><i class="' + cls + '" style="width:' + w + '%"></i></div>'
      + '<div class="rk-sub"><span><b>' + r.vendas + '/' + r.meta + '</b> contratos</span><span>' + (left > 0 ? 'faltam ' + left : 'meta batida') + '</span></div></div>'
    + '<div class="rk-score ' + cls + '">' + r.score + '%' + (r.score >= 100 ? '<small>Meta</small>' : '') + '</div>'
    + '</div>';
}
function rkPeriodLabel(ref){
  if (rkPer === 'mes') { const [y, m] = ref.ym.split('-').map(Number); return ptMonths[m - 1] + ' ' + y; }
  let a = null, b = null;
  if (ref.cur && weekBoundsData) { a = weekBoundsData.start; b = weekBoundsData.end; }
  else { const w = (monthWeeks || []).find(x => Number(x.semana_num) === ref.num); if (w) { a = w.start; b = w.end; } }
  const d = s => s.slice(8, 10) + '/' + s.slice(5, 7);
  return 'Semana' + (ref.num ? ' S' + ref.num : '') + (a ? ' · ' + d(a) + ' – ' + d(b) : '');
}

// ── Render ──────────────────────────────────────────────────────────────────
function rkRender(rows, prev, ref){
  const board = document.getElementById('rkBoard'), sum = document.getElementById('rkSummary');
  const unit = rkScope === 'closer' ? 'closers' : 'equipes';
  if (!rows.length) {
    sum.innerHTML = '<span class="rk-per">' + esc(rkPeriodLabel(ref)) + '</span>';
    board.innerHTML = '<div class="rk-empty">Nenhum dado de ' + unit + ' neste período.</div>'; return;
  }
  rkEnrich(rows); rkAnnotate(rows);
  const prevMap = prev && prev.some(r => r.vendas > 0) ? new Map(prev.map((r, i) => [r.id, i + 1])) : null;
  const act = rows.filter(r => !r.ex_closer), hit = act.filter(r => r.score >= 100).length, total = rows.reduce((s, r) => s + r.vendas, 0);   // total inclui ex-closers
  sum.innerHTML = '<span class="rk-per">' + esc(rkPeriodLabel(ref)) + '</span>'
    + '<span class="rk-goalsum' + (hit ? '' : ' none') + '"><b>' + hit + '</b> de ' + act.length + ' ' + unit + ' bateram a meta</span>'
    + '<span><b>' + total + '</b> contratos</span>';

  const first = rkScope === 'closer' ? Math.min(3, act.length) : 0;       // pódio só para closers ATIVOS
  let html = '';
  if (first) html += '<div class="rk-podium">' + rows.slice(0, first).map((r, i) => rkPodiumCard(r, i + 1, prevMap)).join('') + '</div>';
  if (rows.length > first) {
    const k = rows.findIndex(r => r.score < 100);       // 1ª posição abaixo da meta
    let line = false;
    html += '<div class="rk-list">';
    for (let i = first; i < rows.length; i++) {
      if (!line && k > 0 && i >= k) { html += '<div class="rk-goal-line" role="separator"><span>'+ICO.target+' Linha da meta · 100%</span></div>'; line = true; }
      html += rkRow(rows[i], i + 1, prevMap);
    }
    html += '</div>';
  }
  board.innerHTML = html;
}
async function rkLoad(){
  const board = document.getElementById('rkBoard'); if (!board) return;
  const seq = ++rkSeq, ref = rkParseRef();
  if (!board.querySelector('.rk-podium,.rk-list')) board.innerHTML = '<div class="m-loading">Carregando...</div>';
  try {
    await rkEnsureTb();
    // closers: vem da API de ranking (inclui ex-closers, marcados); equipes: lista da TV, como antes
    const rows = ref.cur && rkScope !== 'closer' ? rkCurrentRows(rkScope, rkPer) : await rkFetchRows(rkScope, rkPer, ref, ref.cur);
    if (seq !== rkSeq) return;
    if (rows === null) { board.innerHTML = '<div class="rk-empty">Não foi possível carregar este período.</div>'; return; }
    const prevRef = await rkPrevRef(rkPer, ref);
    const prev = prevRef ? await rkFetchRows(rkScope, rkPer, prevRef) : null;
    if (seq !== rkSeq) return;
    rkRender(rows, prev, ref);
  } catch(e) {
    console.error(e);
    if (seq === rkSeq) board.innerHTML = '<div class="rk-empty">Erro ao montar o ranking.</div>';
  }
}

// ── Investimento mensal ────────────────────────────────────────────────────
async function loadInvestimento(){
  try{
    const d=await fetch(API_URL+'?action=get_data').then(r=>r.json());
    const el=document.getElementById('investimentoInput');
    if(el && d.investimento_mes!=null) el.value=parseFloat(d.investimento_mes)||0;
  }catch(e){}
}
async function saveInvestimento(){
  const el=document.getElementById('investimentoInput');
  const val=parseFloat(el?.value)||0;
  const fd=new FormData();
  fd.append('action','save_investimento');
  fd.append('valor',val);
  try{
    const d=await fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json());
    if(d.success) toast('Investimento salvo!'); else toast(d.message||'Erro',true);
  }catch(e){ toast('Erro de conexão',true); }
}

// ── Gráficos ───────────────────────────────────────────────────────────────
const charts = {};
function destroyChart(id){if(charts[id]){charts[id].destroy();delete charts[id];}}

function setChartPeriod(p, btn){
  chartPeriod=p;
  document.querySelectorAll('.chart-period-btn').forEach(b=>{b.classList.remove('btn-p','active');b.classList.add('btn-s');});
  btn.classList.remove('btn-s');btn.classList.add('btn-p','active');
  document.getElementById('gfWeekRow').style.display=(p==='week')?'flex':'none';
  document.getElementById('byCloserTitle').textContent='Por Closer ('+(p==='month'?'Mês':p==='year'?'Ano':'Semana')+')';
  loadAllCharts();
}

async function gfLoadWeeks(){
  const lbl=document.getElementById('gfMonthLabel');
  const sel=document.getElementById('gfWeekSel');
  if(lbl) lbl.textContent=ptMonths[gfMonth-1].substring(0,3)+' '+gfYear;
  try{
    const d=await fetch(`${API_URL}?action=get_month_weeks&ano=${gfYear}&mes=${gfMonth}`).then(r=>r.json());
    if(!d.success) return;
    gfWeeks=d.data||[];
    sel.innerHTML='<option value="">Semana atual</option>';
    gfWeeks.forEach(w=>{
      const o=document.createElement('option');
      o.value=`${gfYear},${gfMonth},${w.semana_num}`;
      o.textContent=`S${w.semana_num}: ${w.data_inicio} ~ ${w.data_fim}`;
      sel.appendChild(o);
    });
  }catch(e){console.error(e);}
}

function gfChangeMonth(d){
  gfMonth+=d;
  if(gfMonth>12){gfMonth=1;gfYear++;}
  if(gfMonth<1){gfMonth=12;gfYear--;}
  gfLoadWeeks().then(()=>loadAllCharts());
}

function populateGfCloser(){
  const sel=document.getElementById('gfCloser'); if(!sel) return;
  const cur=sel.value;
  sel.innerHTML='<option value="">Todos</option>'+[...allClosers].sort((a,b)=>a.name.localeCompare(b.name)).map(v=>`<option value="${v.id}" ${String(v.id)===String(cur)?'selected':''}>${esc(v.name)}</option>`).join('');
}

async function loadAllCharts(){
  const closer=document.getElementById('gfCloser')?.value||'';
  let url=`${API_URL}?action=get_chart_data&period=${chartPeriod}`+(closer?`&vendedor_id=${closer}`:'');
  if(chartPeriod==='week'){
    const wSel=document.getElementById('gfWeekSel')?.value||'';
    if(wSel){const[a,m,s]=wSel.split(',');url+=`&ano=${a}&mes=${m}&semana_num=${s}`;}
  }
  try{
    const d=await fetch(url).then(r=>r.json()); if(!d.success) return;
    renderMainChart(d);
    renderTiposChart('chartTipos',d.tipos);
    renderByCloserChart(d);
    renderTeamCharts();
  }catch(e){console.error(e);}
}

function renderTeamCharts(){
  // Equipes — Semana
  destroyChart('eqSemana');
  const ctxS=document.getElementById('chartEqSemana');
  if(ctxS&&allTeams.length){
    const sorted=[...allTeams].sort((a,b)=>b.vendas-a.vendas);
    charts['eqSemana']=new Chart(ctxS.getContext('2d'),{type:'bar',data:{labels:sorted.map(t=>t.nome),datasets:[{label:'Contratos',data:sorted.map(t=>t.vendas),backgroundColor:GOLD_PALETTE,borderRadius:4}]},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'rgba(255,255,255,.5)',font:{size:9}},grid:{color:'rgba(255,255,255,.05)'},beginAtZero:true},y:{ticks:{color:'rgba(255,255,255,.6)',font:{size:10}},grid:{color:'rgba(255,255,255,.04)'}}}}});
  } else if(ctxS&&!allTeams.length) ctxS.parentElement.innerHTML+='<p style="color:rgba(212,175,55,.4);font-size:11px;text-align:center;padding:12px">Nenhuma equipe cadastrada</p>';

  // Equipes — Mês
  destroyChart('eqMes');
  const ctxM=document.getElementById('chartEqMes');
  if(ctxM&&allTeamsMensal.length){
    const sortedM=[...allTeamsMensal].sort((a,b)=>b.vendas_mes-a.vendas_mes);
    charts['eqMes']=new Chart(ctxM.getContext('2d'),{type:'bar',data:{labels:sortedM.map(t=>t.nome),datasets:[{label:'Contratos',data:sortedM.map(t=>t.vendas_mes),backgroundColor:GOLD_PALETTE,borderRadius:4}]},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'rgba(255,255,255,.5)',font:{size:9}},grid:{color:'rgba(255,255,255,.05)'},beginAtZero:true},y:{ticks:{color:'rgba(255,255,255,.6)',font:{size:10}},grid:{color:'rgba(255,255,255,.04)'}}}}});
  }
}

// ── Time: carga de closers em férias / arquivados / apoio dos alertas ───────
async function loadDeletedClosers(){
  try {
    const j = await fetch(API_URL+'?action=get_deleted_vendedores').then(r => r.json());
    tmDeleted = j.success ? j.data : [];
  } catch(e) { console.error(e); }
  tmRender();
}
async function restoreCloser(id){
  const d = await tmApi({ action:'restore_vendedor', id });
  if (d.success) { toast(d.message); await tmReloadAll(); } else toast(d.message, true);
}
async function loadSuspendedClosers(){
  try {
    const j = await fetch(API_URL+'?action=get_suspended_vendedores').then(r => r.json());
    tmSuspended = j.success ? j.data : [];
  } catch(e) { console.error(e); }
  tmRender();
}
async function reactivateSuspended(id){ return tmEndFeriasFor(id); }
// último fechamento de cada closer (alerta "sem fechamentos") + férias agendadas
async function tmLoadOverview(){
  try {
    const j = await fetch(API_URL+'?action=get_time_overview').then(r => r.json());
    if (j.success) { tmLastSale = j.last_sale || {}; tmFerias = j.ferias || {}; tmToday = j.today || ''; }
  } catch(e) { console.error(e); }
  tmRender();
}

function renderMainChart(d){
  destroyChart('main');
  const ctx=document.getElementById('chartMain'); if(!ctx) return;
  const labels=d.labels||[];
  const wSel=document.getElementById('gfWeekSel');
  const wLabel=(wSel&&wSel.selectedIndex>0)?wSel.options[wSel.selectedIndex].textContent.split(':')[0]:'Semana Atual';
  const title=chartPeriod==='week'?wLabel:chartPeriod==='month'?'Mês Atual':'Ano Atual';
  document.getElementById('mainChartTitle').textContent='Contratos — '+title;

  let datasets=[];
  if(chartPeriod==='week'&&d.vendors){
    const vNames=d.vendorNames||{};
    Object.entries(d.vendors).forEach(([vid,vals],i)=>{
      const vn=vNames[vid]; if(!vn) return;
      datasets.push({label:vn.name,data:vals,backgroundColor:GOLD_PALETTE[i%GOLD_PALETTE.length]+'cc',borderColor:GOLD_PALETTE[i%GOLD_PALETTE.length],borderWidth:1,borderRadius:4});
    });
  } else {
    datasets=[{label:'Contratos',data:d.data||[],backgroundColor:'rgba(212,175,55,.55)',borderColor:'#d4af37',borderWidth:2,borderRadius:4,fill:true}];
  }
  charts['main']=new Chart(ctx.getContext('2d'),{type:'bar',data:{labels,datasets},options:{responsive:true,plugins:{legend:{labels:{color:'rgba(255,255,255,.65)',font:{size:10}}}},scales:{x:{ticks:{color:'rgba(255,255,255,.5)',font:{size:9}},grid:{color:'rgba(255,255,255,.05)'}},y:{ticks:{color:'rgba(255,255,255,.5)',font:{size:9}},grid:{color:'rgba(255,255,255,.05)'},beginAtZero:true}}}});
}

function renderTiposChart(canvasId, tiposData){
  destroyChart(canvasId);
  const ctx=document.getElementById(canvasId); if(!ctx) return;
  const labels=[], vals=[];
  // Standard tipos in order (excluding "outros")
  TIPOS.filter(t=>t!=='outros').forEach(tipo=>{
    const v=tiposData?.[tipo]||0; if(v>0){labels.push(TIPO_LABELS[TIPOS.indexOf(tipo)]);vals.push(v);}
  });
  // Expanded "outros" by description
  Object.entries(tiposData||{}).forEach(([key,v])=>{
    if(key.startsWith('outros:')&&v>0){labels.push(key.replace('outros:',''));vals.push(v);}
  });
  // Plain "outros" without description
  if((tiposData?.['outros']||0)>0){labels.push('Outros');vals.push(tiposData['outros']);}
  if(!vals.length||vals.every(v=>v===0)){ctx.parentElement.innerHTML+='<p style="color:rgba(212,175,55,.4);font-size:11px;text-align:center">Sem dados de benefício</p>';return;}
  charts[canvasId]=new Chart(ctx.getContext('2d'),{type:'doughnut',data:{labels,datasets:[{data:vals,backgroundColor:GOLD_PALETTE.slice(0,labels.length),borderColor:'rgba(0,0,0,.4)',borderWidth:2}]},options:{responsive:true,plugins:{legend:{labels:{color:'rgba(255,255,255,.65)',font:{size:10}},position:'bottom'}}}});
}

function renderByCloserChart(d){
  destroyChart('byCloser');
  const ctx=document.getElementById('chartByCloser'); if(!ctx) return;
  let sorted, valueKey='vendas';
  if(d && chartPeriod==='week' && d.vendors && d.vendorNames){
    // Sum all days for each vendor from the chart data
    const arr=Object.entries(d.vendors).map(([vid,vals])=>{
      const vn=d.vendorNames[vid];if(!vn)return null;
      return{name:vn.name,total:vals.reduce((s,v)=>s+v,0),deleted:vn.deleted||false};
    }).filter(Boolean).sort((a,b)=>b.total-a.total).slice(0,10);
    const barColors=arr.map((v,i)=>v.deleted?'rgba(180,60,60,0.6)':GOLD_PALETTE[i%GOLD_PALETTE.length]);
    charts['byCloser']=new Chart(ctx.getContext('2d'),{type:'bar',data:{labels:arr.map(v=>v.name),datasets:[{label:'Contratos',data:arr.map(v=>v.total),backgroundColor:barColors,borderRadius:4}]},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'rgba(255,255,255,.5)',font:{size:9}},grid:{color:'rgba(255,255,255,.05)'},beginAtZero:true},y:{ticks:{color:'rgba(255,255,255,.6)',font:{size:10}},grid:{color:'rgba(255,255,255,.04)'}}}}});
  } else {
    const key=chartPeriod==='month'?'vendas_mes':'vendas';
    const sorted=[...allClosers].sort((a,b)=>(b[key]||b.vendas)-(a[key]||a.vendas)).slice(0,10);
    charts['byCloser']=new Chart(ctx.getContext('2d'),{type:'bar',data:{labels:sorted.map(v=>v.name),datasets:[{label:'Contratos',data:sorted.map(v=>v[key]||v.vendas),backgroundColor:GOLD_PALETTE,borderRadius:4}]},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'rgba(255,255,255,.5)',font:{size:9}},grid:{color:'rgba(255,255,255,.05)'},beginAtZero:true},y:{ticks:{color:'rgba(255,255,255,.6)',font:{size:10}},grid:{color:'rgba(255,255,255,.04)'}}}}});
  }
}

// ── Painel ──
function populatePnCloser(){ /* o Painel carrega os próprios dados (get_painel) */ }

// ── Visão do Time ──────────────────────────────────────────────────────────
function showVision(v,btn){
  document.querySelectorAll('.vs-tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.vs-panel').forEach(p=>p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById(v==='week'?'vsWeek':'vsMonth').classList.add('active');
  if(v==='month'&&!monthDataLoaded) loadMonthData();
}
function renderWeekTeam(){
  const wrap = document.getElementById('vsWeekWrap');
  if(wrap) vsWRender(wrap, allClosers, null);
}

// ── Visão do Time — seletor de semana ─────────────────────────────────────
async function vsWLoadWeeks() {
  const lbl = document.getElementById('vsWLabel');
  if(lbl) lbl.textContent = ptMonths[vsWMonth-1].substring(0,3)+' '+vsWYear;
  const wD = await fetch(`${API_URL}?action=get_month_weeks&ano=${vsWYear}&mes=${vsWMonth}`).then(r=>r.json());
  vsWWeeks = wD.success ? wD.data : [];
  const sel = document.getElementById('vsWSel'); if(!sel) return;
  sel.innerHTML = '<option value="">Semana atual</option>' +
    vsWWeeks.map(w=>`<option value="${w.semana_num}">S${w.semana_num}: ${w.data_inicio.substring(5).replace('-','/')}~${w.data_fim.substring(5).replace('-','/')}</option>`).join('');
}
function vsWChangeMonth(d){
  vsWMonth+=d; if(vsWMonth>12){vsWMonth=1;vsWYear++;}if(vsWMonth<1){vsWMonth=12;vsWYear--;}
  vsWLoadWeeks().then(()=>vsWLoadData());
}
function vsWSelect(){ vsWLoadData(); }
async function vsWLoadData(){
  const wrap = document.getElementById('vsWeekWrap'); if(!wrap) return;
  const val   = document.getElementById('vsWSel')?.value||'';
  const inclD = document.getElementById('vsWShowDel')?.checked;
  // For "semana atual" without ex-closers: use already-loaded allClosers (no extra request)
  if(!val && !inclD){ vsWRender(wrap, allClosers, null); return; }
  // Resolve which semana_num to use for "semana atual"
  const sem = val || curSemNum;
  const ano = val ? vsWYear  : curSemAno;
  const mes = val ? vsWMonth : curSemMes;
  if(!sem){ vsWRender(wrap, allClosers, null); return; }
  wrap.innerHTML='<div class="m-loading">Carregando...</div>';
  try{
    const inclStr = inclD ? '&include_deleted=1' : '';
    const j=await fetch(`${API_URL}?action=get_week_summary&ano=${ano}&mes=${mes}&semana_num=${sem}${inclStr}`).then(r=>r.json());
    if(!j.success){wrap.innerHTML='<div class="m-loading">Erro ao carregar</div>';return;}
    vsWRender(wrap, j.data, j);
  }catch{wrap.innerHTML='<div class="m-loading">Erro de conexão</div>';}
}
function vsWRender(wrap, closers, weekMeta){
  if(!closers||!closers.length){wrap.innerHTML='<div class="m-loading">Nenhum dado</div>';return;}
  const labels = weekMeta ? weekMeta.labels : weekLabels;
  const hdrs = labels.map(l=>`<th>${l}</th>`).join('');
  const rows = closers.map(v=>{
    const src=v.imageUrl||(v.img?`https://i.pravatar.cc/150?img=${v.img}`:null);
    const av=src?`<img src="${src}" loading="lazy">`:`${(v.name||'?').charAt(0)}`;
    const pct=v.score; const pc=pct>=80?'g':pct>=50?'m':'l';
    const days=(v.daily||[]).map(qty=>`<td><div class="dcell ${qty>0?'has':'emp'}">${qty||''}</div></td>`).join('');
    const delStyle=v.deleted?'opacity:0.55;background:rgba(255,255,255,0.02)':'';
    const delBadge=v.deleted?'<span style="font-size:8px;color:#a67c1e;background:rgba(166,124,30,.15);border:1px solid rgba(166,124,30,.35);border-radius:3px;padding:1px 4px;margin-left:4px;vertical-align:middle">Ex</span>':'';
    return`<tr style="${delStyle}"><td class="nc"><div class="t-av"><div class="t-av-img">${av}</div><div class="t-nm">${v.name}${delBadge}${v.equipe_nome?`<span style="font-size:9px;color:rgba(212,175,55,.55)"> · ${v.equipe_nome}</span>`:''}</div></div></td>${days}<td><span class="t-tot">${v.vendas}</span></td><td style="color:rgba(212,175,55,.55);font-size:10px">${v.metas}</td><td><span class="t-pct ${pc}">${pct}%</span></td></tr>`;
  }).join('');
  wrap.innerHTML=`<table class="tt"><thead><tr><th class="nc">Closer</th>${hdrs}<th>Total</th><th>Meta</th><th>Score</th></tr></thead><tbody>${rows}</tbody></table>`;
}
function changeMonth(d){
  viewMonth+=d; if(viewMonth>12){viewMonth=1;viewYear++;}if(viewMonth<1){viewMonth=12;viewYear--;}
  monthDataLoaded=false; loadMonthData();
}
async function loadMonthData(){
  const wrap=document.getElementById('mTableWrap'),lbl=document.getElementById('mLabel');
  if(lbl) lbl.textContent=ptMonths[viewMonth-1]+' '+viewYear;
  wrap.innerHTML='<div class="m-loading">Carregando...</div>';
  const inclDel=document.getElementById('showDeletedClosers')?.checked?'&include_deleted=1':'';
  try{
    const j=await fetch(`${API_URL}?action=get_monthly_summary&month=${viewMonth}&year=${viewYear}${inclDel}`).then(r=>r.json());
    if(!j.success){wrap.innerHTML='<div class="m-loading">Erro ao carregar</div>';return;}
    monthDataLoaded=true;
    const days=j.daysInMonth;
    const hdrs=Array.from({length:days},(_,i)=>`<th>${String(i+1).padStart(2,'0')}</th>`).join('');
    const rows=j.data.map(v=>{
      const src=v.imageUrl||(v.img?`https://i.pravatar.cc/150?img=${v.img}`:null);
      const av=src?`<img src="${src}" loading="lazy">`:`${v.name.charAt(0)}`;
      const pct=v.metas?Math.min(100,Math.round((v.total/v.metas)*100)):0;
      const pc=pct>=80?'g':pct>=50?'m':'l';
      const cells=v.daily.map(qty=>`<td><div class="dcell ${qty>0?'has':'emp'}">${qty||''}</div></td>`).join('');
      const delStyle=v.deleted?'opacity:0.55;background:rgba(255,255,255,0.02)':'';
      const delBadge=v.deleted?'<span style="font-size:8px;color:#a67c1e;background:rgba(166,124,30,.15);border:1px solid rgba(166,124,30,.35);border-radius:3px;padding:1px 4px;margin-left:4px;vertical-align:middle">Ex</span>':'';
      return`<tr style="${delStyle}"><td class="nc"><div class="t-av"><div class="t-av-img">${av}</div><div class="t-nm">${v.name}${delBadge}</div></div></td>${cells}<td><span class="t-tot">${v.total}</span></td><td style="color:rgba(212,175,55,.55);font-size:10px">${v.metas}</td><td><span class="t-pct ${pc}">${pct}%</span></td></tr>`;
    }).join('');
    wrap.innerHTML=`<table class="tt"><thead><tr><th class="nc">Closer</th>${hdrs}<th>Total</th><th>Meta</th><th>Score</th></tr></thead><tbody>${rows}</tbody></table>`;
  }catch{wrap.innerHTML='<div class="m-loading">Erro de conexão</div>';}
}

