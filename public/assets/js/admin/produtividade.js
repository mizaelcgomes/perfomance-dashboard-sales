// ══════════════════════════════════════════════════════════════════════════
// PRODUTIVIDADE — funil Ligações → Follow-ups → Leads quentes → Contratos (dados: get_produtividade)
// Conversão = Contratos ÷ leads únicos × 100 (leads únicos = números de cliente distintos com ≥ 1 ação). Média = ações ÷ leads únicos. Variação = ações do período vs. o mesmo nº de dias imediatamente antes
// (período em andamento é comparado só pelos dias já decorridos). Alertas só valem para closers com portal ativo.
// ══════════════════════════════════════════════════════════════════════════
const PR = { per:'hoje', from:'', to:'', eq:'', cl:'', data:null, sort:{ k:'conv', dir:-1 }, open:null, seq:0, detSeq:0, ready:false, evo:'semanal', det:null };
try { const v = localStorage.getItem('prEvo'); if (v === 'diaria' || v === 'semanal') PR.evo = v; } catch(e) {}      // evolução do closer: diária ou semanal (lembra a escolha)
const PR_PER = { hoje:'Hoje', semana:'Semana', mes:'Mês', custom:'Personalizado' };
try { const s = localStorage.getItem('prPer'); if (PR_PER[s] && s !== 'custom') PR.per = s; } catch(e) {}
const PR_TIPOS = { ligacao:{ l:'Ligação', i:''+ICO.phone+'', c:'#60a5fa' }, followup:{ l:'Follow-up', i:''+ICO.refresh+'', c:'#a78bfa' }, lead_quente:{ l:'Lead quente', i:''+ICO.flame+'', c:'#fb923c' } };
const prFmtDT = s => s ? String(s).slice(8,10) + '/' + String(s).slice(5,7) + ' ' + String(s).slice(11,16) : '—';
const prNum = n => Number(n).toLocaleString('pt-BR', { maximumFractionDigits:1 });

function prLoad(){ prInit(); prFillFilters(); prSyncSeg(); prReload(); }
function prInit(){
  if (PR.ready) return; PR.ready = true;
  const t = tmTodayISO(); PR.to = t; PR.from = t.slice(0,8) + '01';
  gid('prFrom').value = PR.from; gid('prTo').value = PR.to;
  gid('prSeg').addEventListener('click', e => { const b = e.target.closest('[data-per]'); if (b) prSetPer(b.dataset.per); });
  ['prFrom','prTo'].forEach(id => gid(id).addEventListener('change', () => { PR.from = gid('prFrom').value; PR.to = gid('prTo').value; if (PR.per === 'custom' && PR.from && PR.to) { PR.open = null; prReload(); } }));
  gid('prEq').addEventListener('change', () => { PR.eq = gid('prEq').value; PR.cl = ''; PR.open = null; prFillFilters(); prReload(); });
  gid('prCl').addEventListener('change', () => { PR.cl = gid('prCl').value; PR.open = null; prReload(); });
  const tbl = gid('prTbl');
  tbl.addEventListener('click', e => {
    const s = e.target.closest('[data-sort]');
    if (s) { const k = s.dataset.sort; PR.sort = { k, dir: PR.sort.k === k ? -PR.sort.dir : (k === 'nome' ? 1 : -1) }; prRenderTable(); return; }
    const all = e.target.closest('[data-all]'); if (all) { prAllOpen(Number(all.dataset.all)); return; }
    const row = e.target.closest('.pr-row'); if (row) prToggle(Number(row.dataset.id));
  });
  tbl.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.pr-row')) { e.preventDefault(); prToggle(Number(e.target.dataset.id)); } });
}
function prSyncSeg(){
  document.querySelectorAll('#prSeg [data-per]').forEach(b => { const on = b.dataset.per === PR.per; b.classList.toggle('active', on); b.setAttribute('aria-selected', on); });
  gid('prCustom').hidden = PR.per !== 'custom';
}
function prSetPer(p){
  if (!PR_PER[p]) return;
  PR.per = p; PR.open = null; prSyncSeg();
  try { if (p !== 'custom') localStorage.setItem('prPer', p); } catch(e) {}
  if (p === 'custom' && !(PR.from && PR.to)) return;
  prReload();
}
function prFillFilters(){
  const eqs = typeof allEquipes !== 'undefined' ? allEquipes : [], cls = (typeof allClosers !== 'undefined' ? allClosers : []).filter(c => !PR.eq || String(c.equipe_id) === String(PR.eq));
  gid('prEq').innerHTML = '<option value="">Todas as equipes</option>' + eqs.map(e => '<option value="' + e.id + '">' + esc(e.nome) + '</option>').join('');
  gid('prEq').value = PR.eq;
  gid('prCl').innerHTML = '<option value="">Todos os closers</option>' + [...cls].sort((a,b) => a.name.localeCompare(b.name, 'pt-BR')).map(c => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join('');
  if (PR.cl && !cls.some(c => String(c.id) === String(PR.cl))) PR.cl = '';
  gid('prCl').value = PR.cl;
}
async function prReload(){
  const seq = ++PR.seq, pane = gid('tabProdutividade');
  const qs = new URLSearchParams({ action:'get_produtividade', periodo:PR.per });
  if (PR.per === 'custom') { qs.set('from', PR.from); qs.set('to', PR.to); }
  if (PR.eq) qs.set('equipe_id', PR.eq);
  if (PR.cl) qs.set('closer_id', PR.cl);
  pane.setAttribute('aria-busy', 'true');
  try {
    const d = await fetch(API_URL+'?' + qs).then(r => r.json());
    if (seq !== PR.seq) return;
    if (!d.success) { if (/autenticad/i.test(d.message || '')) { location.href = '/login'; return; } throw new Error(d.message || 'Erro'); }
    PR.data = d; prRender();
    if (window.prLidCarregar) window.prLidCarregar();       // seção Líderes (assets/js/lider-admin.js): mesmo período e equipe do filtro
  } catch(e) {
    if (seq !== PR.seq) return;
    gid('prKpis').innerHTML = '<div class="pr-empty" style="grid-column:1/-1">Não foi possível carregar a produtividade.<br><button type="button" class="btn btn-p btn-sm" style="margin-top:10px" onclick="prReload()">Tentar de novo</button></div>';
  } finally { if (seq === PR.seq) pane.removeAttribute('aria-busy'); }
}
function prRangeLabel(r){
  if (r.periodo === 'hoje') return 'Hoje · <b>' + pnDM(r.start) + '</b>';
  if (r.periodo === 'mes') return 'Mês de <b>' + ptMonths[Number(r.start.slice(5,7)) - 1] + ' ' + r.start.slice(0,4) + '</b>';
  return (r.periodo === 'semana' ? 'Semana · ' : '') + '<b>' + pnDM(r.start) + ' a ' + pnDM(r.end) + '</b>';
}
function prRender(){
  const d = PR.data, r = d.range;
  gid('prRangeLbl').innerHTML = prRangeLabel(r) + ' · comparando com ' + (r.dias === 1 ? '<b>' + pnDM(r.prev_start) + '</b>' : '<b>' + pnDM(r.prev_start) + ' a ' + pnDM(r.prev_end) + '</b>');
  const note = gid('prNote');
  if (r.vazio) { note.hidden = false; note.innerHTML = 'Este período ainda não começou — não há ações para mostrar.'; }
  else if (!d.com_conta) { note.hidden = false; note.innerHTML = '<b>Nenhum closer com acesso ativo ao portal ainda.</b> Convide-os em <a href="#" onclick="showTab(\'equipes\');return false" style="color:var(--gr-strong)">Equipes → Gerenciar acesso</a> para começar a registrar ações.'; }
  else if (d.participantes < d.ranking.length) { note.hidden = false; note.innerHTML = 'Indicadores e funil consideram os <b>' + d.participantes + '</b> closer' + (d.participantes === 1 ? '' : 's') + ' que usam o portal (de ' + d.ranking.length + '). Quem não usa aparece como “Sem portal” e não entra na conversão.'; }
  else note.hidden = true;
  prRenderKpis(); prRenderFunnel(); prRenderTable();
}
function prDelta(cur, prev, pp){
  if (cur === null || prev === null) return '';
  if (pp) { const x = Math.round((cur - prev) * 10) / 10; return x === 0 ? '<span class="pr-dl flat">= 0 pp</span>' : '<span class="pr-dl ' + (x > 0 ? 'up' : 'down') + '">' + (x > 0 ? '▲ +' : '▼ ') + prNum(x) + ' pp</span>'; }
  if (prev === 0) return cur > 0 ? '<span class="pr-dl up">▲ novo</span>' : '<span class="pr-dl flat">—</span>';
  const p = Math.round((cur - prev) / prev * 100);
  return p === 0 ? '<span class="pr-dl flat">= 0%</span>' : '<span class="pr-dl ' + (p > 0 ? 'up' : 'down') + '">' + (p > 0 ? '▲ +' : '▼ ') + p + '%</span>';
}
function prRenderKpis(){
  const d = PR.data, k = d.kpis, p = d.prev;
  const card = (lbl, val, col, delta, foot) => '<div class="pr-kpi" style="--kc:' + col + '"><div class="pr-kpi-l">' + lbl + '</div><div class="pr-kpi-v">' + val + '</div><div class="pr-kpi-f">' + delta + '<span>' + foot + '</span></div></div>';
  const ant = n => 'antes: ' + n;
  gid('prKpis').innerHTML =
      card(''+ICO.phone+' Ligações', k.ligacoes, 'var(--pr-lig)', prDelta(k.ligacoes, p.ligacoes), ant(p.ligacoes))
    + card(''+ICO.refresh+' Follow-ups', k.followups, 'var(--pr-fu)', prDelta(k.followups, p.followups), ant(p.followups))
    + card(''+ICO.flame+' Leads quentes', k.leads_quentes, 'var(--pr-lq)', prDelta(k.leads_quentes, p.leads_quentes), ant(p.leads_quentes))
    + card(''+ICO.target+' Contratos', k.contratos, 'var(--pr-ct)', prDelta(k.contratos, p.contratos), ant(p.contratos))
    + card('Taxa de conversão', k.conversao === null ? '—' : prNum(k.conversao) + '<small>%</small>', 'var(--gr)', prDelta(k.conversao, p.conversao, true), k.contratos + ' contratos ÷ ' + k.leads_unicos + ' leads únicos')
    + card('Média de ações por lead', k.media_acoes_lead === null ? '—' : prNum(k.media_acoes_lead), 'var(--pr-lig)', prDelta(k.media_acoes_lead, p.media_acoes_lead), k.acoes + ' ações ÷ ' + k.leads_unicos + ' leads únicos');
}
function prRenderFunnel(){
  const f = PR.data.funil, max = Math.max(1, ...f.map(x => x.qtd)), cols = ['#60a5fa', '#a78bfa', '#fb923c', '#34d399'];
  gid('prFunnelSub').textContent = f[0].qtd ? f[0].qtd + ' ligações no período' : '';
  gid('prFunnel').innerHTML = '<div class="pr-fn">' + f.map((s, i) =>
      (s.k === 'contratos' ? '<div class="pr-fn-step" aria-label="' + (s.pct_leads === null ? 'sem leads únicos no período' : prNum(s.pct_leads) + '% dos leads únicos') + '">↓ ' + (s.pct_leads === null ? '<em>—</em>' : '<em>' + prNum(s.pct_leads) + '%</em> dos leads únicos') + '</div>' : '')
    + '<div class="pr-fn-bar" style="--w:' + Math.max(44, Math.round(s.qtd / max * 100)) + '%;--fc:' + cols[i] + '"><b>' + esc(s.label) + '</b><span>' + s.qtd + '</span></div>').join('') + '</div>';
}
const PR_AL_ICO = { inativo:''+ICO.alert+'', queda:''+ICO.trendDown+'', sem_contrato:''+ICO.target+'' };
function prRowHtml(r, i){
  const av = r.imageUrl ? tmImg(r.imageUrl, tmInitials(r.nome)) : esc(tmInitials(r.nome));
  const bd = [];
  if (r.equipe_nome) bd.push('<span class="rk-badge" style="--bc:' + rkTone(r.equipe_cor) + '"><i></i>' + esc(r.equipe_nome) + '</span>');
  if (r.ferias) bd.push('<span class="pr-al muted">'+ICO.sun+' Férias</span>');
  else if (!r.tem_conta) bd.push('<span class="pr-al muted" title="Ainda não tem acesso ativo ao portal">Sem portal</span>');
  r.alertas.forEach(a => bd.push('<span class="pr-al ' + a.k + '" title="' + esc(a.d) + '">' + PR_AL_ICO[a.k] + ' ' + esc(a.t) + '</span>'));
  const n = v => '<span class="pr-n' + (v ? '' : ' zero') + '">' + v + '</span>';
  const vr = r.variacao === null ? (r.total > 0 ? '<span class="pr-dl up">▲ novo</span>' : '<span class="pr-dl flat">—</span>') : (r.variacao === 0 ? '<span class="pr-dl flat">= 0%</span>' : '<span class="pr-dl ' + (r.variacao > 0 ? 'up' : 'down') + '">' + (r.variacao > 0 ? '▲ +' : '▼ ') + r.variacao + '%</span>');
  const open = PR.open === r.id;
  return '<div class="pr-item' + (open ? ' open' : '') + '" data-item="' + r.id + '">'
    + '<div class="pr-row" role="row" tabindex="0" data-id="' + r.id + '" aria-expanded="' + open + '" aria-label="' + esc(r.nome) + ': ver detalhes">'
    + '<span class="pr-rk">' + (i + 1) + '</span>'
    + '<div class="pr-cl"><div class="pn-av" style="--ac:' + rkTone(r.cor) + '">' + av + '</div><div class="pr-cl-t"><div class="pr-nm" title="' + esc(r.nome) + '">' + esc(r.nome) + '</div><div class="pr-bd">' + bd.join('') + '</div></div></div>'
    + '<div class="c" data-l="Ligações">' + n(r.ligacoes) + '</div><div class="c" data-l="Follow-ups">' + n(r.followups) + '</div><div class="c" data-l="Leads quentes">' + n(r.leads_quentes) + '</div>'
    + '<div class="c" data-l="Conversão"><span class="pr-cv">' + (r.conv === null ? '—' : prNum(r.conv) + '%') + '<small>' + r.contratos + ' contrato' + (r.contratos === 1 ? '' : 's') + ' · ' + r.leads + ' lead' + (r.leads === 1 ? '' : 's') + ' único' + (r.leads === 1 ? '' : 's') + '</small></span></div>'
    + '<div class="c" data-l="Variação">' + vr + '</div></div>'
    + (open ? '<div class="pr-detail" id="prDet" role="region" aria-label="Detalhes de ' + esc(r.nome) + '"><div class="pr-empty" style="grid-column:1/-1">Carregando…</div></div>' : '')
    + '</div>';
}
function prSorted(rows){
  const { k, dir } = PR.sort;
  const val = r => k === 'nome' ? r.nome.toLowerCase() : k === 'conv' ? (r.conv === null ? -1 : r.conv) : k === 'variacao' ? (r.variacao === null ? -9999 : r.variacao) : r[k];
  return rows.slice().sort((a, b) => {
    const va = val(a), vb = val(b);
    let c = typeof va === 'string' ? va.localeCompare(vb, 'pt-BR') : va - vb;
    if (c === 0 && k === 'conv') c = (a.contratos - b.contratos) || (a.total - b.total);
    if (c === 0) c = a.nome.localeCompare(b.nome, 'pt-BR');
    return c * dir;
  });
}
function prRenderTable(){
  const d = PR.data; if (!d) return;
  const rows = prSorted(d.ranking), alerts = d.ranking.filter(r => r.alertas.length).length;
  gid('prTblSub').textContent = rows.length + ' closer' + (rows.length === 1 ? '' : 's') + (alerts ? ' · ' + alerts + ' com alerta' : '');
  const th = (k, label, cls) => '<button type="button" data-sort="' + k + '" class="' + (cls || '') + '" aria-sort="' + (PR.sort.k === k ? (PR.sort.dir < 0 ? 'descending' : 'ascending') : 'none') + '">' + label + '</button>';
  const head = '<div class="pr-th" role="row"><span>#</span>' + th('nome', 'Closer') + th('ligacoes', ''+ICO.phone+' Ligações', 'c') + th('followups', ''+ICO.refresh+' Follow-ups', 'c') + th('leads_quentes', ''+ICO.flame+' Leads quentes', 'c') + th('conv', ''+ICO.target+' Taxa conv.', 'c') + th('variacao', 'Variação', 'c') + '</div>';
  gid('prTbl').innerHTML = rows.length ? head + rows.map(prRowHtml).join('') : '<div class="pr-empty">Nenhum closer encontrado para este filtro.</div>';
  if (PR.open) { const r = rows.find(x => x.id === PR.open); if (r) prFillDetail(PR.open); else PR.open = null; }
}
function prToggle(id){
  PR.open = PR.open === id ? null : id;
  prRenderTable();
  if (PR.open) { const el = document.querySelector('#prTbl .pr-item.open'); if (el) el.scrollIntoView({ block:'nearest', behavior:'smooth' }); }
}
async function prFillDetail(id){
  const seq = ++PR.detSeq, r0 = PR.data && PR.data.range, ref = String((r0 && (r0.eff_end || r0.start)) || tmTodayISO());      // a evolução mostra o mês em que o período termina
  let d;
  try { d = await fetch(API_URL+'?action=get_produtividade_closer&closer_id=' + id + '&ano=' + ref.slice(0,4) + '&mes=' + Number(ref.slice(5,7))).then(r => r.json()); } catch(e) { d = { success:false }; }
  if (seq !== PR.detSeq || PR.open !== id) return;
  const box = gid('prDet'); if (!box) return;
  if (!d.success) { box.innerHTML = '<div class="pr-empty" style="grid-column:1/-1">Não foi possível carregar os detalhes.</div>'; return; }
  PR.det = d;
  box.innerHTML = '<div><div class="pr-evo-h"><h4 id="prEvoT"></h4><div class="pn-seg pr-evo-seg" id="prEvoSeg" role="tablist" aria-label="Evolução diária ou semanal"><button type="button" role="tab" data-evo="diaria">Diária</button><button type="button" role="tab" data-evo="semanal">Semanal</button></div></div><div class="pr-chartbox"><canvas id="prDetChart" role="img"></canvas></div></div>'
    + '<div><h4>Últimas ações</h4><div class="pr-last">' + (d.ultimas.length ? d.ultimas.map(a => { const t = PR_TIPOS[a.tipo] || { i:'•', l:a.tipo };
        return '<div class="pr-last-r"><time>' + prFmtDT(a.registrado_em) + '</time><b>' + esc(a.mascarado) + (a.observacao ? ' <span style="font-weight:500;color:var(--muted)" title="' + esc(a.observacao) + '">· ' + esc(a.observacao.length > 22 ? a.observacao.slice(0,22) + '…' : a.observacao) + '</span>' : '') + '</b><span class="pr-ty">' + t.i + ' ' + esc(t.l) + '</span></div>'; }).join('')
      : '<div class="pr-empty">Este closer ainda não registrou ações.</div>') + '</div></div>'
    + '<div class="pr-detail-f"><button type="button" class="btn btn-s btn-sm" data-all="' + id + '">Ver todas as ações</button></div>';
  gid('prEvoSeg').addEventListener('click', e => { const b = e.target.closest('[data-evo]'); if (b) prEvoSet(b.dataset.evo); });
  prEvoChart();
}
// Evolução do closer: Diária (cada dia do mês) ou Semanal (as semanas configuradas do mês, as mesmas do Painel).
// Dias e semanas que ainda não chegaram ficam sem ponto; a semana em andamento conta só até hoje.
function prEvoSet(m){
  if (m !== 'diaria' && m !== 'semanal') return;
  PR.evo = m; try { localStorage.setItem('prEvo', m); } catch(e) {}
  prEvoChart();
}
const PR_DOW = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
function prEvoChart(){
  const d = PR.det, cv = gid('prDetChart'); if (!d || !cv) return;
  const dia = PR.evo === 'diaria', pts = dia ? d.dias : d.semanas, mesRef = ptMonths[d.mes - 1] + ' ' + d.ano;
  document.querySelectorAll('#prEvoSeg [data-evo]').forEach(b => { const on = b.dataset.evo === PR.evo; b.classList.toggle('active', on); b.setAttribute('aria-selected', on); });
  gid('prEvoT').textContent = 'Evolução ' + (dia ? 'diária' : 'semanal') + ' · ' + mesRef;
  cv.setAttribute('aria-label', 'Evolução ' + (dia ? 'diária' : 'semanal') + ' de ' + d.closer.nome + ' em ' + mesRef);
  destroyChart('prDet');
  if (typeof Chart === 'undefined') { cv.parentNode.innerHTML = '<div class="pr-empty">Gráfico indisponível (biblioteca não carregou).</div>'; return; }
  const lb = dia ? pts.map(x => String(Number(x.data.slice(8)))) : pts.map(w => 'S' + w.num);
  const ds = (label, key, color, dash) => ({ label, data:pts.map(x => x[key]), borderColor:color, backgroundColor:color, borderWidth:2.2, cubicInterpolationMode:'monotone', pointRadius:dia ? 2 : 3.5, pointHoverRadius:5, borderDash:dash || [], spanGaps:false });
  const titulo = it => { const x = pts[it[0].dataIndex];
    return dia ? PR_DOW[new Date(x.data + 'T12:00:00').getDay()] + ' ' + pnDM(x.data) + (x.data === d.hoje ? ' · hoje' : '')
               : 'Semana ' + x.num + ' · ' + pnDM(x.inicio) + ' a ' + pnDM(x.fim) + (x.parcial ? ' · em andamento' : ''); };
  charts.prDet = new Chart(cv.getContext('2d'), {
    type:'line',
    data:{ labels:lb, datasets:[ ds('Ligações', 'ligacoes', '#60a5fa'), ds('Follow-ups', 'followups', '#a78bfa'), ds('Leads quentes', 'leads_quentes', '#fb923c'), ds('Contratos', 'contratos', '#34d399', [6, 4]) ] },
    options:{ responsive:true, maintainAspectRatio:false, interaction:{ mode:'index', intersect:false },
      plugins:{ legend:{ position:'bottom', labels:{ color:'rgba(255,255,255,.75)', boxWidth:10, boxHeight:10, usePointStyle:true, font:{ size:11 } } },
                tooltip:{ callbacks:{ title:titulo } } },
      scales:{ x:{ grid:{ display:false }, ticks:{ color:'rgba(255,255,255,.5)', font:{ size:10 }, autoSkipPadding:6, maxRotation:0 } }, y:{ beginAtZero:true, ticks:{ color:'rgba(255,255,255,.45)', precision:0 }, grid:{ color:'rgba(255,255,255,.06)' } } } }
  });
}

// ── Histórico completo (modal), números mascarados, paginado ───────────────
const PRA = { id:0, offset:0, seq:0 };
function prAllOpen(id){
  PRA.id = id;
  const r = PR.data.ranking.find(x => x.id === id);
  gid('prAllTitle').textContent = 'Todas as ações — ' + (r ? r.nome : '');
  gid('prAllTipo').value = ''; gid('prAllFrom').value = ''; gid('prAllTo').value = '';
  gid('prAllDlg').showModal();
  prAllLoad(true);
}
async function prAllLoad(reset){
  if (reset) { PRA.offset = 0; gid('prAllList').innerHTML = '<div class="pr-empty">Carregando…</div>'; }
  const seq = ++PRA.seq;
  const qs = new URLSearchParams({ action:'get_acoes_closer', closer_id:PRA.id, offset:PRA.offset, limit:40 });
  if (gid('prAllTipo').value) qs.set('tipo', gid('prAllTipo').value);
  if (gid('prAllFrom').value) qs.set('from', gid('prAllFrom').value);
  if (gid('prAllTo').value) qs.set('to', gid('prAllTo').value);
  let d; try { d = await fetch(API_URL+'?' + qs).then(r => r.json()); } catch(e) { d = { success:false }; }
  if (seq !== PRA.seq) return;
  if (!d.success) { gid('prAllList').innerHTML = '<div class="pr-empty">Não foi possível carregar.</div>'; return; }
  const html = d.acoes.map(a => { const t = PR_TIPOS[a.tipo] || { i:'•', l:a.tipo };
    return '<div class="pr-last-r"><time>' + prFmtDT(a.registrado_em) + '</time><b>' + esc(a.mascarado) + (a.observacao ? ' <span style="font-weight:500;color:var(--muted)">· ' + esc(a.observacao) + '</span>' : '') + '</b><span class="pr-ty">' + t.i + ' ' + esc(t.l) + '</span></div>'; }).join('');
  if (reset) gid('prAllList').innerHTML = html || '<div class="pr-empty">Nenhuma ação encontrada.</div>'; else gid('prAllList').insertAdjacentHTML('beforeend', html);
  PRA.offset += d.acoes.length;
  gid('prAllCount').textContent = d.total + (d.total === 1 ? ' ação' : ' ações');
  gid('prAllMore').hidden = !d.has_more;
}

// ── Busca global (Ctrl/⌘ + K) ──────────────────────────────────────────────
const CP = { items:[], sel:0 };
function cpGoCloser(name){ showTab('equipes'); tmSelectTeam('all'); tmFilter = 'all'; const s = gid('tmSearch'); if(s) s.value = name; tmSetSearch(name); }
function cpGoTeam(id){ showTab('equipes'); tmSelectTeam(String(id)); }
function cpBuild(q){
  const n = tmNorm(q), has = n.length > 0, out = [];
  const hit = (...p) => !has || tmNorm(p.join(' ')).includes(n);
  Object.keys(TAB_INFO).forEach(k=>{
    const lbl = (document.querySelector('.tab-btn[data-tab="'+k+'"] .sb-lbl')||{}).textContent || TAB_INFO[k].title;
    if(hit(lbl, TAB_INFO[k].crumbs[0])) out.push({grp:'Telas', ico:'▦', t:lbl, sub:TAB_INFO[k].crumbs[0], run:()=>showTab(k)});
  });
  [{t:'Registrar contrato rápido', ico:''+ICO.zap+'', sub:'Ação', run:cvOpen},
   {t:'Pedidos de acesso ao portal', ico:''+ICO.clock+'', sub:'Ação', run:()=>bellGo('acessos')},
   {t:'Pódio', ico:''+ICO.tv+'', sub:'Ação', run:()=>window.open('/','_blank','noopener')},
   {t:(gid('appShell').classList.contains('collapsed')?'Expandir':'Recolher')+' menu lateral', ico:'↔', sub:'Ação', run:sbToggle}
  ].forEach(a=>{ if(hit(a.t)) out.push(Object.assign({grp:'Ações'}, a)); });
  if(has){
    allClosers.filter(c=>hit(c.name, c.equipe_nome||'')).slice(0,8).forEach(c=>out.push({grp:'Closers', ico:''+ICO.user+'', t:c.name, sub:c.equipe_nome||'Sem equipe', run:()=>cpGoCloser(c.name)}));
    allEquipes.filter(e=>hit(e.nome)).slice(0,6).forEach(e=>out.push({grp:'Equipes', ico:''+ICO.tag+'', t:e.nome, sub:(e.total_closers||0)+' closers', run:()=>cpGoTeam(e.id)}));
  }
  return out;
}
function cpRender(){
  const list = gid('cpList');
  CP.items = cpBuild(gid('cpInput').value);
  if(CP.sel >= CP.items.length) CP.sel = Math.max(0, CP.items.length - 1);
  if(!CP.items.length){ list.innerHTML = '<div class="cp-empty">Nada encontrado para “'+esc(gid('cpInput').value)+'”.</div>'; return; }
  let html = '', last = '';
  CP.items.forEach((it,i)=>{
    if(it.grp !== last){ html += '<div class="cp-grp">'+it.grp+'</div>'; last = it.grp; }
    html += '<button type="button" class="cp-it" role="option" id="cpIt'+i+'" aria-selected="'+(i===CP.sel)+'" data-i="'+i+'"><span class="cp-ic" aria-hidden="true">'+it.ico+'</span>'+esc(it.t)+'<small>'+esc(it.sub||'')+'</small></button>';
  });
  list.innerHTML = html;
  const cur = gid('cpIt'+CP.sel); if(cur) cur.scrollIntoView({block:'nearest'});
}
function cpOpen(){
  pdClose(false); bellClose(false); sbCloseDrawer(false);
  const dlg = gid('cpDlg'); if(dlg.open) return;
  gid('cpInput').value = ''; CP.sel = 0; cpRender();
  dlg.showModal(); gid('cpInput').focus();
}
function cpRun(i){
  const it = CP.items[i]; if(!it) return;
  gid('cpDlg').close();
  setTimeout(it.run, 0);
}

// ── Eventos globais do shell ───────────────────────────────────────────────
function shellInit(){
  let pref = null; try{ pref = localStorage.getItem(SB_KEY); }catch(e){}
  if(pref === '1' || pref === '0') sbSetCollapsed(pref === '1', false);
  else sbSetCollapsed(window.innerWidth >= 768 && window.innerWidth < 1200, false);   // sem preferência: recolhe em telas médias

  document.addEventListener('keydown', e=>{
    const sh = gid('appShell');
    if((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k'){ e.preventDefault(); cpOpen(); return; }
    if(e.key === 'Escape'){
      if(sh.classList.contains('drawer-open')) sbCloseDrawer(true);
      else if(PD.open) pdClose(true);
      else if(bellOpen) bellClose(true);
      return;
    }
    if(e.key === 'Tab' && sh.classList.contains('drawer-open')){          // foco preso no drawer
      const f = [...gid('sidebar').querySelectorAll('button,a[href]')].filter(x => x.offsetParent !== null);
      if(!f.length) return;
      if(e.shiftKey && document.activeElement === f[0]){ e.preventDefault(); f[f.length-1].focus(); }
      else if(!e.shiftKey && document.activeElement === f[f.length-1]){ e.preventDefault(); f[0].focus(); }
    }
  });
  document.addEventListener('pointerdown', e=>{
    if(PD.open && !gid('pdPop').contains(e.target) && !(PD.anchor && PD.anchor.contains(e.target))) pdClose(false);
    if(bellOpen && !gid('bellPop').contains(e.target) && !gid('bellBtn').contains(e.target)) bellClose(false);
  });
  window.addEventListener('scroll', e=>{
    if(e.target && e.target.closest && e.target.closest('.pop')) return;
    pdClose(false); bellClose(false);
  }, true);
  window.addEventListener('resize', ()=>{ pdPlace(); bellClose(false); });
  window.matchMedia('(min-width:768px)').addEventListener('change', e=>{ if(e.matches) sbCloseDrawer(false); });

  const inp = gid('cpInput'), dlg = gid('cpDlg');
  inp.addEventListener('input', ()=>{ CP.sel = 0; cpRender(); });
  inp.addEventListener('keydown', e=>{
    if(e.key === 'ArrowDown'){ e.preventDefault(); CP.sel = Math.min(CP.items.length-1, CP.sel+1); cpRender(); }
    else if(e.key === 'ArrowUp'){ e.preventDefault(); CP.sel = Math.max(0, CP.sel-1); cpRender(); }
    else if(e.key === 'Enter'){ e.preventDefault(); cpRun(CP.sel); }
  });
  gid('cpList').addEventListener('click', e=>{ const b = e.target.closest('.cp-it'); if(b) cpRun(Number(b.dataset.i)); });
  gid('cpList').addEventListener('mousemove', e=>{ const b = e.target.closest('.cp-it'); if(b && Number(b.dataset.i) !== CP.sel){ CP.sel = Number(b.dataset.i); document.querySelectorAll('.cp-it').forEach(x=>x.setAttribute('aria-selected', String(x === b))); } });
  [dlg, gid('cvDlg')].forEach(d=>{
    let down = null;
    d.addEventListener('mousedown', e=>{ down = e.target; });
    d.addEventListener('click', e=>{ if(e.target === d && down === d) d.close(); });     // clique no fundo fecha
  });
}

