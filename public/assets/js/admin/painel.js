// ══════════════════════════════════════════════════════════════════════════
// PAINEL GERAL — KPIs, gráficos e Visão do Time (dados: api.php?action=get_painel)
// ══════════════════════════════════════════════════════════════════════════
const PN_VT = { dia:'Dia', semana:'Semana', mes:'Mês' };
let PN_VT_INIT = 'semana'; try{ const s = localStorage.getItem('pnVt'); if(PN_VT[s]) PN_VT_INIT = s; }catch(e){}
const PN = { sel:{mode:'current'}, data:null, team:'all', seq:0, vt:PN_VT_INIT, status:'all', day:null, scrollTo:false };
const PN_MIX_COLORS = ['#d4af37','#34d399','#60a5fa','#a78bfa','#fb7185','#fbbf24','#2dd4bf','#f472b6'];
const pnCls = p => p < 40 ? 'lo' : (p < 80 ? 'mid' : 'hi');     // vermelho <40 · amarelo 40–79 · verde ≥80
const pnIc = p => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+p+'</svg>';
const PN_ICONS = {
  users:  pnIc('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
  file:   pnIc('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="9 15 11 17 15 13"/>'),
  cal:    pnIc('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/>'),
  target: pnIc('<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>')
};
function pnHex(hex, a){
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || ''), n = m ? parseInt(m[1],16) : 0xd4af37;
  return 'rgba('+(n>>16)+','+((n>>8)&255)+','+(n&255)+','+a+')';
}

function pnInit(){
  pnRenderSkeleton();
  gid('pnChips').addEventListener('click', e=>{ const b = e.target.closest('[data-team]'); if(b) pnSetTeam(b.dataset.team); });
  gid('pnSeg').addEventListener('click', e=>{ const b = e.target.closest('[data-vt]'); if(b) pnSetVt(b.dataset.vt); });
  gid('pnStatus').addEventListener('click', e=>{ const b = e.target.closest('[data-status]'); if(b) pnSetStatus(b.dataset.status); });
  gid('pnDayStrip').addEventListener('click', e=>{ const b = e.target.closest('[data-day]'); if(b){ PN.day = b.dataset.day; pnRerenderVt(); } });
  pnReload();
}
function pnRenderSkeleton(){
  gid('pnKpis').innerHTML = [1,2,3,4].map(()=>'<div class="pn-kpi"><div class="pn-kpi-top"><div class="pn-ico pn-skel"></div><div class="pn-kpi-lbl pn-skel" style="width:130px">carregando</div></div><div class="pn-kpi-val pn-skel" style="width:96px">00</div><div class="pn-prog"></div><div class="pn-kpi-cap pn-skel" style="width:70%">carregando</div></div>').join('');
}
function pnRenderError(msg){
  gid('pnKpis').innerHTML = '<div class="pn-empty pn-err" style="grid-column:1/-1"><div>Não foi possível carregar o painel.<br><small>'+esc(msg||'')+'</small></div><button type="button" class="btn btn-p btn-sm" onclick="pnReload()">Tentar de novo</button></div>';
}
async function pnReload(){
  const seq = ++PN.seq, pane = gid('tabPainel');
  let url = API_URL+'?action=get_painel';
  if(PN.sel.mode === 'custom') url += '&ano='+PN.sel.ano+'&mes='+PN.sel.mes+'&semana_num='+PN.sel.sem;
  pane.setAttribute('aria-busy','true');
  try{
    const d = await fetch(url).then(r=>r.json());
    if(seq !== PN.seq) return;
    if(!d.success){
      if(/autenticad/i.test(d.message||'')){ location.href = '/login'; return; }
      throw new Error(d.message || 'Erro desconhecido');
    }
    PN.data = d;
    if(PN.team !== 'all' && !d.teams.some(t => String(t.id) === String(PN.team))) PN.team = 'all';
    pnRender();
  }catch(e){ if(seq === PN.seq){ console.error(e); pnRenderError(e.message); } }
  finally{ if(seq === PN.seq) pane.removeAttribute('aria-busy'); }
}
function pnSetTeam(id){ PN.team = String(id); if(PN.data) pnRender(); }

// recorte dos dados conforme o filtro de equipe
function pnView(){
  const d = PN.data, tid = PN.team === 'all' ? null : Number(PN.team);
  const cl = tid ? d.closers.filter(c => c.equipe_id === tid) : d.closers;
  const team = tid ? d.teams.find(t => t.id === tid) : null;
  return { cl, team,
    sem: team ? {v:team.vendas, m:team.meta}         : {v:d.totals.semana, m:d.totals.meta_semana},
    mes: team ? {v:team.vendas_mes, m:team.meta_mes} : {v:d.totals.mes,    m:d.totals.meta_mes} };
}
function pnRender(){
  const d = PN.data, w = d.week, v = pnView();
  shellSetPeriod(d); shellSetCac(d);
  gid('pnSub').innerHTML = (w.semana_num ? 'Semana '+w.semana_num : 'Semana')+': '+pnDM(w.start)+' a '+pnDM(w.end)
    + (w.is_current ? ' · <b>semana atual</b>' : '') + (v.team ? ' · <b>'+esc(v.team.nome)+'</b>' : '');
  pnRenderChips(); pnRenderKpis(v); pnRenderCharts(); pnRenderTeamView(v);
  pnAnimateBars();
  if(PN.scrollTo){ PN.scrollTo = false; gid('pnVt').scrollIntoView({behavior:'smooth', block:'start'}); }   // vindo do sino: leva até a Visão do Time
}

function pnRenderChips(){
  const d = PN.data;
  gid('pnChips').innerHTML = '<button type="button" class="pn-chip'+(PN.team==='all'?' active':'')+'" data-team="all" aria-pressed="'+(PN.team==='all')+'">Todas</button>'
    + d.teams.map(t=>'<button type="button" class="pn-chip'+(String(t.id)===PN.team?' active':'')+'" data-team="'+t.id+'" aria-pressed="'+(String(t.id)===PN.team)+'" style="--cc:'+rkTone(t.cor)+'"><i></i>'+esc(t.nome)+'</button>').join('');
}

// ── KPIs ───────────────────────────────────────────────────────────────────
function pnKpi(ico, label, val, w, cls, capL, capR){
  return '<div class="pn-kpi"><div class="pn-kpi-top"><div class="pn-ico">'+PN_ICONS[ico]+'</div><div class="pn-kpi-lbl">'+label+'</div></div>'
    + '<div class="pn-kpi-val">'+val+'</div>'
    + '<div class="pn-prog" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'+Math.round(w)+'" aria-label="'+esc(label)+'"><i class="'+cls+'" style="width:0" data-w="'+Math.min(100,w)+'"></i></div>'
    + '<div class="pn-kpi-cap"><span>'+capL+'</span><span>'+capR+'</span></div></div>';
}
function pnRenderKpis(v){
  const d = PN.data, act = v.cl.filter(c => !c.ex_closer), n = act.length;      // KPIs só com closers ativos
  const withSales = act.filter(c => c.vendas > 0).length;
  const pct = o => o.m > 0 ? Math.round(o.v / o.m * 100) : 0;
  const ps = pct(v.sem), pm = pct(v.mes);
  const avg = n ? Math.round(act.reduce((s,c)=>s+c.score,0) / n) : 0;
  const hit = act.filter(c => c.score >= 100).length, hitW = n ? hit / n * 100 : 0;
  const susp = v.team ? 0 : d.totals.suspensos;
  gid('pnKpis').innerHTML =
      pnKpi('users',  'Closers ativos',    n, n ? withSales/n*100 : 0, 'gold', '<b>'+withSales+'</b> de '+n+' com contratos', susp ? susp+' em férias' : '')
    + pnKpi('file',   'Contratos semana',  v.sem.v, ps, pnCls(ps), '<b>'+ps+'%</b> da meta', v.sem.m ? 'meta '+v.sem.m : 'sem meta')
    + pnKpi('cal',    'Contratos mês',     v.mes.v, pm, pnCls(pm), '<b>'+pm+'%</b> da meta', v.mes.m ? 'meta '+v.mes.m : 'sem meta')
    + pnKpi('target', 'Score médio',       avg+'<small>%</small>', hitW, pnCls(hitW), '<b>'+hit+'/'+n+'</b> batendo meta', '');
}

// ── Gráficos (Chart.js) ────────────────────────────────────────────────────
function pnResizeCharts(){ ['pnTeams','pnMix','pnDaily'].forEach(k=>{ if(typeof charts !== 'undefined' && charts[k]) charts[k].resize(); }); }
const pnValueLabels = {
  id:'pnValueLabels',
  afterDatasetsDraw(chart){
    const ctx = chart.ctx; ctx.save();
    ctx.fillStyle = '#f3f3f3'; ctx.font = '800 12px Inter, sans-serif'; ctx.textBaseline = 'middle';
    chart.getDatasetMeta(0).data.forEach((bar,i)=>{ ctx.fillText(String(chart.data.datasets[0].data[i]), bar.x + 9, bar.y); });
    ctx.restore();
  }
};
const pnCenterText = {
  id:'pnCenterText',
  afterDraw(chart){
    const a = chart.chartArea; if(!a) return;
    const cx = (a.left + a.right) / 2, cy = (a.top + a.bottom) / 2, ctx = chart.ctx;
    ctx.save(); ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillStyle = '#ffffff'; ctx.font = '900 30px Inter, sans-serif'; ctx.fillText(String(chart.$total || 0), cx, cy - 8);
    ctx.fillStyle = 'rgba(255,255,255,.5)'; ctx.font = '600 11px Inter, sans-serif'; ctx.fillText('contratos', cx, cy + 16);
    ctx.restore();
  }
};
function pnRenderCharts(){
  if(typeof Chart === 'undefined'){
    const m = '<div class="pn-empty">Não foi possível carregar a biblioteca de gráficos (sem internet?).</div>';
    gid('pnTeamsWrap').innerHTML = m; gid('pnMix').innerHTML = m; gid('pnDailyStats').innerHTML = ''; gid('pnDailyWrap').innerHTML = m; return;
  }
  pnChartTeams(); pnChartMix(); pnChartDaily();
}
function pnChartTeams(){
  destroyChart('pnTeams');
  const d = PN.data, wrap = gid('pnTeamsWrap'), tid = PN.team === 'all' ? null : Number(PN.team);
  const arr = [...d.teams].sort((a,b)=> b.vendas - a.vendas || a.nome.localeCompare(b.nome,'pt-BR'));
  gid('pnTeamsSub').textContent = 'contratos na semana';
  if(!arr.length){ wrap.style.height = 'auto'; wrap.innerHTML = '<div class="pn-empty">Nenhuma equipe cadastrada.</div>'; return; }
  wrap.style.height = Math.max(200, arr.length * 46 + 34)+'px';
  wrap.innerHTML = '<canvas id="pnChartTeams" role="img" aria-label="Contratos por equipe na semana"></canvas>';
  const tone = arr.map(t=>rkTone(t.cor)), dim = i => tid !== null && arr[i].id !== tid;
  charts.pnTeams = new Chart(gid('pnChartTeams').getContext('2d'), {
    type:'bar',
    data:{ labels:arr.map(t=>t.nome), datasets:[{ data:arr.map(t=>t.vendas), borderRadius:8, barThickness:24, borderSkipped:false,
      backgroundColor:c=>{ const a = c.chart.chartArea; if(!a) return tone[c.dataIndex];
        const al = dim(c.dataIndex) ? .3 : 1, g = c.chart.ctx.createLinearGradient(a.left,0,a.right,0);
        g.addColorStop(0, pnHex(tone[c.dataIndex], .28*al)); g.addColorStop(1, pnHex(tone[c.dataIndex], al)); return g; } }] },
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, layout:{padding:{right:40}},
      plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:c=>{ const t = arr[c.dataIndex]; return ' '+t.vendas+' contrato'+(t.vendas===1?'':'s')+' · '+t.score+'% da meta ('+t.meta+')'; } } } },
      scales:{ x:{ beginAtZero:true, ticks:{color:'rgba(255,255,255,.45)', precision:0}, grid:{color:'rgba(255,255,255,.06)'} },
               y:{ ticks:{color:'rgba(255,255,255,.85)', font:{size:12, weight:'600'}}, grid:{display:false} } } },
    plugins:[pnValueLabels]
  });
}
function pnTipoLabel(k){ return k === '__sem' ? 'Sem benefício informado' : k.startsWith('outros:') ? 'Outros: '+k.slice(7) : (TIPOS_MAP[k] || k); }
function pnChartMix(){
  destroyChart('pnMix');
  const d = PN.data, box = gid('pnMix'), te = d.tipos_equipe || {}, tid = PN.team === 'all' ? null : Number(PN.team), agg = {};
  const add = m => Object.entries(m || {}).forEach(([k,v])=>{ agg[k] = (agg[k] || 0) + Number(v); });
  if(tid !== null) add(te[tid]); else Object.values(te).forEach(add);
  const tipado = Object.values(agg).reduce((s,v)=>s+v,0);
  const escopo = tid !== null ? ((d.teams.find(t => t.id === tid) || {}).vendas || 0) : (d.totals.semana || 0);
  const semTipo = Math.max(0, escopo - tipado);       // contratos da semana sem benefício: a fatia cinza fecha o total do KPI
  if (semTipo > 0) agg['__sem'] = semTipo;
  const rows = Object.entries(agg).filter(([,v])=>v>0).sort((a,b)=>(a[0]==='__sem')-(b[0]==='__sem') || b[1]-a[1]);
  const total = rows.reduce((s,[,v])=>s+v,0);
  gid('pnMixSub').textContent = tipado ? tipado+' com tipo registrado' : '';
  if(!total){ box.style.display = 'block'; box.innerHTML = '<div class="pn-empty">Nenhum contrato com tipo de benefício registrado nesta semana'+(tid!==null?' para esta equipe':'')+'.</div>'; return; }
  box.style.display = '';
  box.innerHTML = '<div class="pn-donut"><canvas id="pnChartMix" role="img" aria-label="Contratos por tipo de benefício"></canvas></div><ul class="pn-legend" id="pnMixLegend"></ul>';
  let nOutros = 0;
  const colors = rows.map(([k]) => k === '__sem' ? '#4b4b4b' : (k.indexOf('outros:') === 0 ? tpShade(tpCor('outros'), ++nOutros) : tpCor(k)));   // a cor de cada benefício é a mesma de toda a interface
  gid('pnMixLegend').innerHTML = rows.map(([k,v],i)=>'<li>'+(k === '__sem' ? '<i style="background:'+colors[i]+'"></i>' : '<i class="tpi" style="color:'+colors[i]+'">'+tpIcon(k)+'</i>')+'<span title="'+esc(pnTipoLabel(k))+'">'+esc(pnTipoLabel(k))+'</span><b>'+v+'</b><em>'+Math.round(v/total*100)+'%</em></li>').join('');
  charts.pnMix = new Chart(gid('pnChartMix').getContext('2d'), {
    type:'doughnut',
    data:{ labels:rows.map(([k])=>pnTipoLabel(k)), datasets:[{ data:rows.map(([,v])=>v), backgroundColor:colors, borderColor:'#101010', borderWidth:3, hoverOffset:6 }] },
    options:{ responsive:true, maintainAspectRatio:false, cutout:'68%',
      plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:c=>' '+c.label+': '+c.raw+' ('+Math.round(c.raw/total*100)+'%)' } } } },
    plugins:[pnCenterText]
  });
  charts.pnMix.$total = total;
}

// ── Contratos por dia: o mês inteiro (respeita o mês escolhido no período e o filtro de equipe) ──
const pnNum = n => Number(n).toLocaleString('pt-BR', {maximumFractionDigits:1});
const pnDailyLabels = {
  id:'pnDailyLabels',
  afterDatasetsDraw(chart){
    const meta = chart.getDatasetMeta(0), vals = chart.data.datasets[0].data, a = chart.chartArea;
    if(!a || !meta.data.length || a.width / meta.data.length < 17) return;   // estreito demais (mobile): só no toque
    const ctx = chart.ctx; ctx.save();
    ctx.fillStyle = '#f3f3f3'; ctx.font = '800 11px Inter, sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
    meta.data.forEach((bar,i)=>{ if(vals[i]) ctx.fillText(String(vals[i]), bar.x, bar.y - 4); });
    ctx.restore();
  }
};
const pnAvgLine = {
  id:'pnAvgLine',
  afterDatasetsDraw(chart){
    const avg = chart.$avg, a = chart.chartArea; if(!avg || !a) return;
    const y = chart.scales.y.getPixelForValue(avg), ctx = chart.ctx, txt = 'média '+pnNum(avg)+'/dia';
    ctx.save();
    ctx.strokeStyle = 'rgba(255,255,255,.6)'; ctx.lineWidth = 1.5; ctx.setLineDash([6,5]);
    ctx.beginPath(); ctx.moveTo(a.left, y); ctx.lineTo(a.right, y); ctx.stroke(); ctx.setLineDash([]);
    ctx.font = '700 11px Inter, sans-serif'; ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
    const w = ctx.measureText(txt).width + 14, x = a.right - 4, ty = Math.max(a.top + 10, y - 12);
    ctx.fillStyle = 'rgba(16,16,16,.9)'; ctx.beginPath();
    if(ctx.roundRect) ctx.roundRect(x - w, ty - 9, w, 18, 9); else ctx.rect(x - w, ty - 9, w, 18);
    ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,.9)'; ctx.fillText(txt, x - 7, ty);
    ctx.restore();
  }
};
function pnChartDaily(){
  destroyChart('pnDaily');
  const d = PN.data, m = d.month, wrap = gid('pnDailyWrap'), st = gid('pnDailyStats'), today = d.week.today;
  const team = PN.team === 'all' ? null : d.teams.find(t => String(t.id) === String(PN.team));
  const serie = team ? team.daily_mes : m.daily_total, ref = ptMonths[m.mes - 1]+' de '+m.ano;
  gid('pnDailySub').textContent = ref+' · '+(team ? team.nome : 'todas as equipes');
  const elapsed = m.dates.filter(dt => dt <= today).length, total = serie.reduce((s,q)=>s+q, 0);
  if(!total){
    st.innerHTML = ''; wrap.style.height = 'auto';
    wrap.innerHTML = '<div class="pn-empty">'+(elapsed ? 'Nenhum contrato registrado em '+ref+(team ? ' para esta equipe' : '')+'.' : 'Este mês ainda não começou.')+'</div>';
    return;
  }
  wrap.style.height = ''; wrap.innerHTML = '<canvas id="pnChartDaily" role="img"></canvas>';
  const avg = elapsed ? total / elapsed : 0, max = Math.max(...serie), bi = serie.indexOf(max);
  const semVenda = serie.filter((q,i)=> m.dates[i] <= today && q === 0).length;
  const tone = team ? rkTone(team.cor) : '#d4af37';
  const we = m.dates.map(dt => { const w = new Date(dt+'T12:00:00').getDay(); return w === 0 || w === 6; });
  const isT = i => m.dates[i] === today;
  let acc = 0; const cum = serie.map(q => acc += q);
  // dias que ainda não chegaram ficam sem barra (a menos que já tenham contrato lançado)
  const data = serie.map((q,i)=> m.dates[i] <= today || q > 0 ? q : null);
  // previsão: cada dia que ainda não chegou (e sem contrato lançado) recebe a média por dia. Só no mês em andamento e a partir do 2º dia
  const fcDias = m.dates.map((dt,i)=> dt > today && !serie[i] ? i : -1).filter(i => i >= 0);
  const prev = elapsed >= 2 && fcDias.length ? total + avg * fcDias.length : null;
  const isF = i => prev !== null && fcDias.includes(i);
  const fcData = prev === null ? null : serie.map((q,i)=> isF(i) ? avg : null);
  let accF = 0; const cumF = serie.map((q,i)=> accF += isF(i) ? avg : q);
  const meta = team ? team.meta_mes : d.totals.meta_mes, pctMeta = prev !== null && meta > 0 ? Math.round(prev / meta * 100) : null;
  const faltam = fcDias.length+' dia'+(fcDias.length === 1 ? '' : 's')+' que faltam';
  st.innerHTML =
      '<div class="pn-dstat"><small>Total no mês</small><b>'+total+'</b></div>'
    + '<div class="pn-dstat"><small>Média por dia</small><b>'+pnNum(avg)+'<em>/dia</em></b></div>'
    + '<div class="pn-dstat"><small>Melhor dia</small><b>'+max+'<em>'+m.labels[bi]+' '+pnDM(m.dates[bi])+'</em></b></div>'
    + '<div class="pn-dstat"><small>Dias sem contrato</small><b>'+semVenda+'<em>de '+elapsed+'</em></b></div>'
    + (prev === null ? '' : '<div class="pn-dstat prev" title="Total até hoje + média por dia × '+faltam+'"><small>Previsão do mês</small><b>'+Math.round(prev)+'<em>contratos</em></b>'+(pctMeta === null ? '' : '<span>'+pctMeta+'% da meta ('+meta+')</span>')+'</div>'
      + '<div class="pn-daily-leg"><span><i style="background:'+pnHex(tone,.85)+'"></i>Realizado</span><span><i style="background:'+pnHex(tone,.22)+';border-color:'+pnHex(tone,.9)+'"></i>Previsão: média de '+pnNum(avg)+'/dia nos '+faltam+'</span></div>');
  const cv = gid('pnChartDaily');
  cv.setAttribute('aria-label', 'Contratos por dia em '+ref+': total '+total+', média '+pnNum(avg)+' por dia, melhor dia '+pnDM(m.dates[bi])+' com '+max+(prev === null ? '' : ', previsão do mês '+Math.round(prev)));
  charts.pnDaily = new Chart(cv.getContext('2d'), {
    type:'bar',
    data:{ labels:m.nums, datasets:[{ data, borderRadius:5, borderSkipped:false, minBarLength:3, maxBarThickness:28,
      backgroundColor:c=>{
        const i = c.dataIndex, a = c.chart.chartArea, al = isT(i) ? 1 : (we[i] ? .5 : .85);
        if(!a) return pnHex(tone, al);
        const g = c.chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
        g.addColorStop(0, pnHex(tone, al)); g.addColorStop(1, pnHex(tone, al * .35)); return g;
      },
      borderColor:c=> isT(c.dataIndex) ? '#ffffff' : 'transparent', borderWidth:c=> isT(c.dataIndex) ? 1.5 : 0 }]
      .concat(fcData ? [{ label:'Previsão', data:fcData, borderRadius:5, borderSkipped:false, minBarLength:3, maxBarThickness:28,
        backgroundColor:pnHex(tone, .22), borderColor:pnHex(tone, .9), borderWidth:1.5 }] : []) },
    options:{ responsive:true, maintainAspectRatio:false, layout:{padding:{top:18}},
      plugins:{ legend:{display:false}, tooltip:{ callbacks:{
        title:it=> m.labels[it[0].dataIndex]+' '+pnDM(m.dates[it[0].dataIndex])+(isT(it[0].dataIndex) ? ' · hoje' : (isF(it[0].dataIndex) ? ' · previsão' : '')),
        label:c=> c.datasetIndex === 1 ? ' Previsão: ~'+pnNum(c.raw)+' contratos (média por dia)' : ' '+c.raw+' contrato'+(c.raw === 1 ? '' : 's'),
        afterLabel:c=> c.datasetIndex === 1 ? ' Acumulado previsto: ~'+Math.round(cumF[c.dataIndex]) : ' Acumulado no mês: '+cum[c.dataIndex] } } },
      scales:{ x:{ stacked:true, grid:{display:false}, ticks:{ autoSkipPadding:6, maxRotation:0,
                    color:c=> isT(c.index) ? '#ffffff' : (we[c.index] ? 'rgba(212,175,55,.6)' : 'rgba(255,255,255,.5)'),
                    font:c=>({ size:11, weight: isT(c.index) ? '800' : '500' }) } },
               y:{ stacked:true, beginAtZero:true, grace:'8%', ticks:{ color:'rgba(255,255,255,.45)', precision:0 }, grid:{color:'rgba(255,255,255,.06)'} } } },
    plugins:[pnDailyLabels, pnAvgLine]
  });
  charts.pnDaily.$avg = elapsed >= 2 ? avg : 0;
}

// ── Visão do Time: tabela com heatmap (≥1024px) e cards (mobile) ──────────
function pnBadge(c, teamMap){
  const t = c.equipe_id ? teamMap.get(c.equipe_id) : null;
  return t ? '<span class="pn-badge" style="--bc:'+rkTone(t.cor)+'"><i></i>'+esc(t.nome)+'</span>' : '<span class="pn-badge none"><i></i>Sem equipe</span>';
}
function pnAvatar(c){
  const ini = tmInitials(c.name);
  return '<div class="pn-av" style="--ac:'+rkTone(c.color)+'">'+(c.imageUrl ? tmImg(c.imageUrl, ini) : esc(ini))+'</div>';
}
// ── Visão do Time: Dia / Semana / Mês — mesma linguagem visual (heatmap, barra de total, badge de score) ──
function pnAnimateBars(){
  requestAnimationFrame(()=>requestAnimationFrame(()=>document.querySelectorAll('#tabPainel .pn-prog i[data-w]').forEach(i=>{ i.style.width = i.dataset.w+'%'; })));
}
// índice (dentro da semana exibida) do dia mostrado na visão Dia: o escolhido; senão hoje; senão o 1º/último dia da semana
function pnDayIdx(){
  const w = PN.data.week, i = PN.day ? w.dates.indexOf(PN.day) : -1;
  if(i >= 0) return i;
  if(w.today < w.dates[0]) return 0;
  if(w.today > w.dates[w.dates.length-1]) return w.dates.length - 1;
  return Math.max(0, w.dates.indexOf(w.today));
}
// valor do closer no período exibido → {qty, meta, score|null}; null = sem meta definida (só acontece na visão Dia)
function pnMetric(c, mode, k){
  if(mode === 'mes') return { qty:c.vendas_mes, meta:c.meta_mes, score:c.score_mes };
  if(mode === 'dia'){
    const q = c.daily[k] || 0, m = c.daily_metas ? c.daily_metas[k] : null;
    return { qty:q, meta:m, score: m > 0 ? Math.round(q / m * 100) : null };
  }
  return { qty:c.vendas, meta:c.meta, score:c.score };
}
// mesma regra do sino: TM_RISK_DAYS+ dias sem fechar contrato (até hoje), exceto quem já bateu a meta da semana
const pnRisk = c => c.dias_sem_venda != null && c.dias_sem_venda >= TM_RISK_DAYS && !(c.score >= 100);
const pnTot = m => '<div class="pn-tot"><div class="pn-tot-n">'+m.qty+'<span> / '+(m.meta > 0 ? m.meta : '—')+'</span></div><div class="pn-prog"><i class="'+(m.score === null ? 'none' : pnCls(m.score))+'" style="width:0" data-w="'+(m.score === null ? 0 : Math.min(100, m.score))+'"></i></div></div>';
const pnScoreBadge = m => m.score === null ? '<span class="pn-score none" title="Sem meta definida para este dia">—</span>' : '<span class="pn-score '+pnCls(m.score)+'">'+m.score+'%</span>';
function pnDayCell(q, date, today, name, big){
  const fut = date > today, kind = fut ? 'fut' : (q >= 3 ? 'd3' : (q >= 1 ? 'd1' : 'd0'));
  return '<div class="pn-d '+kind+(big ? ' big' : '')+(date === today ? ' today' : '')+'" title="'+esc(name)+' · '+pnDM(date)+': '+q+'">'+(fut ? '·' : q)+'</div>';
}

function pnExTag(c){ return c.ex_closer ? '<span class="pn-ex">ex-closer</span>' : ''; }
function pnRenderTeamView(v){
  const d = PN.data, w = d.week, mo = d.month, today = w.today, mode = PN.vt, k = pnDayIdx();
  const teamMap = new Map(d.teams.map(t=>[t.id,t]));
  const tbl = gid('pnTblWrap'), cards = gid('pnCards');

  // controles: período (Dia/Semana/Mês) e filtros de situação, com contagem
  document.querySelectorAll('#pnSeg [data-vt]').forEach(b=>{ const on = b.dataset.vt === mode; b.classList.toggle('active', on); b.setAttribute('aria-selected', String(on)); });
  const all = v.cl.map(c => ({ c, m:pnMetric(c, mode, k) }));
  const hit = r => !r.c.ex_closer && r.m.score !== null && r.m.score >= 100, risk = r => !r.c.ex_closer && pnRisk(r.c);   // ex-closers não entram nos filtros
  const cnt = { all:all.length, meta:all.filter(hit).length, risco:all.filter(risk).length };
  gid('pnStatus').innerHTML = [['all','Todos'], ['meta',''+ICO.target+' Bateram a meta'], ['risco',''+ICO.alert+' Sem fechamentos']].map(([key, label]) =>
    '<button type="button" class="pn-chip sm'+(PN.status === key ? ' active' : '')+'" data-status="'+key+'" aria-pressed="'+(PN.status === key)+'"'
    + (key === 'risco' ? ' title="'+TM_RISK_DAYS+'+ dias sem fechar contrato (até hoje), exceto quem já bateu a meta da semana"' : '')
    + (key === 'meta' ? ' title="'+(mode === 'dia' ? 'Bateu a meta do dia' : mode === 'mes' ? 'Bateu a meta do mês' : 'Bateu a meta da semana')+'"' : '')
    + '>'+label+' <b>'+cnt[key]+'</b></button>').join('');

  // dia escolhido (só na visão Dia)
  const strip = gid('pnDayStrip');
  strip.hidden = mode !== 'dia';
  if(mode === 'dia') strip.innerHTML = w.dates.map((dt,i)=>'<button type="button" class="pn-dbtn'+(i === k ? ' active' : '')+(dt > today ? ' fut' : '')+(dt === today ? ' today' : '')+'" data-day="'+dt+'" aria-pressed="'+(i === k)+'" aria-label="'+esc(w.labels[i])+' '+pnDM(dt)+'"><span>'+esc(w.labels[i].toUpperCase())+'</span><b>'+w.nums[i]+'</b></button>').join('');

  // filtro de situação + ordem de cada visão
  let rows = PN.status === 'meta' ? all.filter(hit) : PN.status === 'risco' ? all.filter(risk) : all;
  const byName = (a,b) => a.c.name.localeCompare(b.c.name, 'pt-BR');
  const exLast = (a,b) => (a.c.ex_closer ? 1 : 0) - (b.c.ex_closer ? 1 : 0);      // ex-closers sempre no fim
  if(PN.status === 'risco') rows = [...rows].sort((a,b) => b.c.dias_sem_venda - a.c.dias_sem_venda || byName(a,b));         // quem está parado há mais tempo primeiro
  else if(mode === 'mes')   rows = [...rows].sort((a,b) => exLast(a,b) || b.m.score - a.m.score || b.m.qty - a.m.qty || byName(a,b));
  else if(mode === 'dia')   rows = [...rows].sort((a,b) => exLast(a,b) || b.m.qty - a.m.qty || (b.m.score ?? -1) - (a.m.score ?? -1) || byName(a,b));
  // (semana: mantém a ordem do servidor = ranking da TV, com desempate)

  const per = mode === 'mes' ? ptMonths[mo.mes-1]+' '+mo.ano+' · '+mo.dates.length+' dias' : mode === 'dia' ? w.labels[k]+' '+pnDM(w.dates[k]) : w.labels.length+' dias';
  gid('pnTeamSub').textContent = rows.length+' closer'+(rows.length === 1 ? '' : 's')+' · '+per;

  if(!rows.length){
    const m = '<div class="pn-empty">'+(!v.cl.length ? 'Nenhum closer ativo'+(v.team ? ' nesta equipe' : '')+'.'
      : PN.status === 'meta' ? 'Ninguém bateu a meta neste período ainda.'
      : 'Ninguém está há '+TM_RISK_DAYS+'+ dias sem fechar. '+ICO.award+'')+'</div>';
    tbl.innerHTML = m; cards.innerHTML = m; return;
  }

  const diasChip = c => PN.status === 'risco' ? '<span class="pn-dias">'+ICO.alert+' há '+c.dias_sem_venda+' dia'+(c.dias_sem_venda === 1 ? '' : 's')+'</span>' : '';
  const isFirst = (r,i) => i === 0 && !r.c.ex_closer && r.m.qty > 0 && PN.status !== 'risco';            // coroa só no líder real (e nunca na lista de "parados")

  // ── tabela (≥1024px) ──
  let head, body;
  if(mode === 'dia'){
    head = '<th class="l">Closer</th><th>Contratos</th><th>Meta do dia</th><th>Score</th>';
    body = rows.map((r,i)=>{ const c = r.c;
      return '<tr><td class="who"><div class="pn-who"><span class="pn-rk'+(isFirst(r,i) ? ' first' : '')+'">'+(r.c.ex_closer ? '—' : (i+1))+'</span>'+pnAvatar(c)+'<div><span class="pn-nm" title="'+esc(c.name)+'">'+esc(c.name)+'</span>'+pnBadge(c, teamMap)+pnExTag(c)+diasChip(c)+'</div></div></td>'
        + '<td>'+pnDayCell(r.m.qty, w.dates[k], today, c.name, true)+'</td><td>'+pnTot(r.m)+'</td><td>'+pnScoreBadge(r.m)+'</td></tr>';
    }).join('');
  } else {
    const dates = mode === 'mes' ? mo.dates : w.dates, labels = mode === 'mes' ? mo.labels : w.labels, nums = mode === 'mes' ? mo.nums : w.nums;
    head = '<th class="l">Closer</th>'+dates.map((dt,i)=>'<th class="'+(dt === today ? 'today ' : '')+(mode === 'mes' && (labels[i] === 'Dom' || labels[i] === 'Sáb') ? 'we' : '')+'">'+esc(mode === 'mes' ? labels[i].charAt(0) : labels[i].toUpperCase())+'<small>'+nums[i]+'</small></th>').join('')+'<th>Total</th><th>Score</th>';
    body = rows.map((r,i)=>{ const c = r.c, series = mode === 'mes' ? c.daily_mes : c.daily;
      return '<tr><td class="who"><div class="pn-who"><span class="pn-rk'+(isFirst(r,i) ? ' first' : '')+'">'+(r.c.ex_closer ? '—' : (i+1))+'</span>'+pnAvatar(c)+'<div><span class="pn-nm" title="'+esc(c.name)+'">'+esc(c.name)+'</span>'+pnBadge(c, teamMap)+pnExTag(c)+diasChip(c)+'</div></div></td>'
        + series.map((q,j)=>'<td>'+pnDayCell(q, dates[j], today, c.name, false)+'</td>').join('')
        + '<td>'+pnTot(r.m)+'</td><td>'+pnScoreBadge(r.m)+'</td></tr>';
    }).join('');
  }
  tbl.innerHTML = '<table class="pn-tbl '+mode+'"><thead><tr>'+head+'</tr></thead><tbody>'+body+'</tbody></table>';

  // ── cards (<1024px) ──
  cards.innerHTML = rows.map((r,i)=>{
    const c = r.c, m = r.m, cls = m.score === null ? 'none' : pnCls(m.score), wd = m.score === null ? 0 : Math.min(100, m.score), first = isFirst(r,i);
    return '<article class="pn-cl'+(first ? ' first' : '')+'"><div class="pn-cl-h"><span class="pn-rk'+(first ? ' first' : '')+'">'+(r.c.ex_closer ? '—' : (i+1))+'º</span>'+pnAvatar(c)+'<div><span class="pn-nm">'+esc(c.name)+'</span>'+pnBadge(c, teamMap)+pnExTag(c)+diasChip(c)+'</div></div>'
      + '<div class="pn-prog" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'+wd+'" aria-label="Progresso de '+esc(c.name)+'"><i class="'+cls+'" style="width:0" data-w="'+wd+'"></i></div>'
      + '<div class="pn-cl-f"><span><b>'+m.qty+'</b> contrato'+(m.qty === 1 ? '' : 's')+' <span style="opacity:.6">/ '+(m.meta > 0 ? m.meta : 'sem meta')+'</span></span><span class="pn-pct '+cls+'">'+(m.score === null ? '—' : m.score+'%')+'</span></div></article>';
  }).join('');
}
function pnRerenderVt(){ if(!PN.data) return; pnRenderTeamView(pnView()); pnAnimateBars(); }
function pnSetVt(v){ if(!PN_VT[v]) return; PN.vt = v; try{ localStorage.setItem('pnVt', v); }catch(e){} pnRerenderVt(); }
function pnSetStatus(s){ PN.status = (PN.status === s && s !== 'all') ? 'all' : s; pnRerenderVt(); }   // clicar no filtro ativo volta para "Todos"

// ── Init ───────────────────────────────────────────────────────────────────
document.getElementById('calMonthLabel').textContent=calLabel(calMonth,calYear);
