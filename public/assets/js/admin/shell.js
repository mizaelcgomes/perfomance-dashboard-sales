// ══════════════════════════════════════════════════════════════════════════
// APP SHELL — sidebar (256↔64px / drawer no mobile), topbar, período global,
// notificações, busca (Ctrl+K) e venda rápida (FAB)
// ══════════════════════════════════════════════════════════════════════════
const SB_KEY = 'sbCollapsed';
const gid = id => document.getElementById(id);
const isMobileVp = () => window.matchMedia('(max-width:767px)').matches;
const pnDM  = s => s ? s.slice(8,10)+'/'+s.slice(5,7) : '';
const pnBrl = v => 'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
let sbLastFocus = null;

// ── Sidebar: recolher (desktop) e drawer (mobile) ──────────────────────────
function sbSetCollapsed(on, persist){
  const sh = gid('appShell'); if(!sh) return;
  sh.classList.toggle('collapsed', !!on);
  const b = gid('sbCollapseBtn');
  if(b){
    b.setAttribute('aria-expanded', String(!on));
    b.querySelector('.sb-lbl').textContent = on ? 'Expandir menu' : 'Recolher menu';
    if(on) b.setAttribute('title','Expandir menu'); else b.removeAttribute('title');
  }
  document.querySelectorAll('.sidebar .tab-btn').forEach(el=>{
    if(on) el.setAttribute('title', el.querySelector('.sb-lbl').textContent.trim()); else el.removeAttribute('title');
  });
  if(persist){ try{ localStorage.setItem(SB_KEY, on?'1':'0'); }catch(e){} }
  setTimeout(pnResizeCharts, 320);   // gráficos acompanham a largura nova depois da transição
}
function sbToggle(){ sbSetCollapsed(!gid('appShell').classList.contains('collapsed'), true); }
function sbOpenDrawer(){
  if(!isMobileVp()) return;
  const sh = gid('appShell'); if(sh.classList.contains('drawer-open')) return;
  pdClose(false); bellClose(false);
  sbLastFocus = document.activeElement;
  sh.classList.add('drawer-open'); document.body.classList.add('drawer-lock');
  gid('sbBurger').setAttribute('aria-expanded','true');
  gid('mainArea').inert = true;                       // foco/leitor de tela ficam só no menu
  const t = document.querySelector('.sidebar .tab-btn.active') || document.querySelector('.sidebar .tab-btn');
  setTimeout(()=>{ if(t) t.focus(); }, 60);
}
function sbCloseDrawer(restoreFocus){
  const sh = gid('appShell'); if(!sh || !sh.classList.contains('drawer-open')) return;
  sh.classList.remove('drawer-open'); document.body.classList.remove('drawer-lock');
  gid('sbBurger').setAttribute('aria-expanded','false');
  gid('mainArea').inert = false;
  if(restoreFocus !== false){ try{ (sbLastFocus || gid('sbBurger')).focus(); }catch(e){} }
  sbLastFocus = null;
}

// ── Seletor de período global ──────────────────────────────────────────────
const PD = { open:false, anchor:null, y:null, m:null, cache:{} };
function pdToggle(anchor){ (PD.open && PD.anchor === anchor) ? pdClose(true) : pdOpen(anchor); }
async function pdOpen(anchor){
  bellClose(false);
  PD.anchor = anchor; PD.open = true;
  const mo = PN.data ? PN.data.month : {ano:RK_Y, mes:RK_M};
  PD.y = mo.ano; PD.m = mo.mes;
  anchor.setAttribute('aria-expanded','true');
  gid('pdPop').hidden = false;
  pdPlace();
  await pdRenderWeeks();
  pdPlace();
}
function pdPlace(){
  if(!PD.anchor) return;
  const pop = gid('pdPop'), r = PD.anchor.getBoundingClientRect(), w = pop.offsetWidth;
  const left = Math.max(12, Math.min(window.innerWidth - w - 12, r.left + r.width/2 - w/2));
  pop.style.left = left+'px'; pop.style.top = (r.bottom + 8)+'px';
}
async function pdRenderWeeks(){
  gid('pdPopMonth').textContent = ptMonths[PD.m-1]+' '+PD.y;
  const box = gid('pdWeeks'), key = PD.y+'-'+PD.m;
  let weeks = PD.cache[key];
  if(!weeks){
    box.innerHTML = '<div class="pd-empty">Carregando…</div>';
    try{
      const j = await fetch(API_URL+'?action=get_month_weeks&ano='+PD.y+'&mes='+PD.m).then(r=>r.json());
      weeks = j.success ? j.data : [];
      PD.cache[key] = weeks;
    }catch(e){ weeks = []; }
  }
  const sel = PN.data ? PN.data.week : null;
  const today = PN.data ? PN.data.week.today : tmTodayISO();
  box.innerHTML = weeks.length ? weeks.map(w=>{
    const isSel = sel && sel.start === w.data_inicio && sel.end === w.data_fim;
    const isCur = w.data_inicio <= today && today <= w.data_fim;
    return '<button type="button" class="pd-wk'+(isSel?' sel':'')+'" onclick="pdPick('+w.semana_num+')"><span>S'+w.semana_num+': '+pnDM(w.data_inicio)+' a '+pnDM(w.data_fim)+'</span>'+(isCur?'<em>Atual</em>':'')+'</button>';
  }).join('') : '<div class="pd-empty">Nenhuma semana neste mês.<br>Configure em Calendário.</div>';
}
function pdMonth(d){ PD.m += d; if(PD.m>12){PD.m=1;PD.y++;} if(PD.m<1){PD.m=12;PD.y--;} pdRenderWeeks(); }
function pdPick(num){ PN.sel = {mode:'custom', ano:PD.y, mes:PD.m, sem:num}; pdClose(true); pnReload(); }
function pdReset(){ PN.sel = {mode:'current'}; pdClose(true); pnReload(); }
function pdClose(refocus){
  if(!PD.open) return;
  gid('pdPop').hidden = true;
  const a = PD.anchor; PD.open = false; PD.anchor = null;
  if(a){ a.setAttribute('aria-expanded','false'); if(refocus){ try{ a.focus(); }catch(e){} } }
}
function shellSetPeriod(d){
  const w = d.week, m3 = ptMonths[d.month.mes-1].slice(0,3);
  const wk = (w.semana_num ? 'S'+w.semana_num+': ' : 'Semana: ')+pnDM(w.start)+' a '+pnDM(w.end);
  const short = m3+' · '+(w.semana_num ? 'S'+w.semana_num+': ' : '')+pnDM(w.start)+' a '+pnDM(w.end);
  document.querySelectorAll('[data-pd="month"]').forEach(el=>el.textContent = m3+' '+d.month.ano);
  document.querySelectorAll('[data-pd="week"]').forEach(el=>el.textContent = wk);
  document.querySelectorAll('[data-pd="short"]').forEach(el=>el.textContent = short);
}

// ── Widget de CAC (verde = dentro da meta · vermelho = acima · cinza = sem dado) ──
function shellSetCac(d){
  const c = d.cac, has = c && c.valor != null;
  const cls = has && c.meta > 0 ? (c.valor <= c.meta ? 'ok' : 'bad') : '';
  const mes = ptMonths[d.month.mes-1]+' '+d.month.ano;
  const tip = has
    ? 'CAC de '+mes+': '+pnBrl(c.valor)+(c.meta>0 ? ' · meta '+pnBrl(c.meta)+(c.valor<=c.meta?' (dentro da meta)':' (acima da meta)') : '')+' · investimento '+pnBrl(c.investimento)
    : 'CAC de '+mes+': sem investimento ou sem contratos registrados';
  document.querySelectorAll('[data-cac]').forEach(el=>el.setAttribute('title', tip));
  document.querySelectorAll('[data-cac-val]').forEach(el=>el.textContent = has ? pnBrl(c.valor) : '—');
  document.querySelectorAll('[data-cac-dot]').forEach(el=>{ el.className = 'cac-dot'+(cls?' '+cls:''); });
}

// ── Notificações (sino): alertas calculados dos dados já carregados ────────
let bellOpen = false;
function shellAlerts(){
  const today = tmTodayISO(), out = [];
  const meta = v => v.meta_s_eff || v.metas || 0;
  const hit  = allClosers.filter(v => meta(v) > 0 && v.vendas >= meta(v));
  const risk = allClosers.filter(v => { const l = tmLastSale[v.id]; return l && !(meta(v) > 0 && v.vendas >= meta(v)) && tmDiffDays(l, today) >= TM_RISK_DAYS; });
  const names = a => a.slice(0,3).map(v=>v.name).join(', ')+(a.length>3 ? ' e +'+(a.length-3) : '');
  if(risk.length) out.push({cls:'risk', ico:''+ICO.alert+'', t:risk.length+' closer'+(risk.length>1?'s':'')+' sem fechamentos há '+TM_RISK_DAYS+'+ dias', d:names(risk), go:'risco'});
  if(hit.length)  out.push({cls:'gold', ico:''+ICO.flame+'', t:hit.length+' closer'+(hit.length>1?'s':'')+' bateram a meta da semana', d:names(hit), go:'meta'});
  if(tmSuspended.length) out.push({cls:'vac', ico:''+ICO.sun+'', t:tmSuspended.length+' em férias', d:tmSuspended.slice(0,3).map(v=>v.nome).join(', ')+(tmSuspended.length>3?' e +'+(tmSuspended.length-3):''), go:'ferias'});
  const pend = tmAcc.filter(a => a.status === 'pendente');
  if(pend.length) out.unshift({cls:'gold', ico:''+ICO.clock+'', t:pend.length+' pedido'+(pend.length>1?'s':'')+' de acesso ao portal', d:pend.slice(0,3).map(a=>a.nome).join(', ')+(pend.length>3 ? ' e +'+(pend.length-3) : ''), go:'acessos'});
  return {items:out, attention:risk.length+pend.length};
}
function bellRender(){
  const n = gid('bellN'), list = gid('bellList'); if(!n || !list) return;
  const a = shellAlerts();
  n.hidden = a.attention === 0; n.textContent = a.attention > 9 ? '9+' : a.attention;
  gid('bellBtn').setAttribute('aria-label', 'Notificações'+(a.attention ? ' ('+a.attention+' que pedem atenção)' : ''));
  list.innerHTML = a.items.length
    ? a.items.map(i=>'<button type="button" class="bell-it '+i.cls+'" onclick="bellGo(\''+i.go+'\')"><span class="bell-ic" aria-hidden="true">'+i.ico+'</span><span class="bell-tx"><b>'+esc(i.t)+'</b><small>'+esc(i.d)+'</small></span></button>').join('')
    : '<div class="bell-empty">Tudo em dia.<br>Nenhum alerta no momento.</div>';
}
function shellRefresh(){ bellRender(); }
function bellToggle(btn){ bellOpen ? bellClose(true) : bellShow(btn); }

// ── perfil (topo): Meu perfil, Configurações e Sair ──────────────────────────────
function profClose(foco){ const m=document.getElementById('profMenu'), b=document.getElementById('profBtn'); if(!m||m.hidden) return; m.hidden=true; b.setAttribute('aria-expanded','false'); if(foco) b.focus(); }
function profToggle(btn){ const m=document.getElementById('profMenu'); if(!m.hidden){ profClose(true); return; } m.hidden=false; btn.setAttribute('aria-expanded','true'); const p=m.querySelector('[role="menuitem"]'); if(p) p.focus(); }
function profPerfil(){
  profClose();
  let d=document.getElementById('profDlg');
  if(!d){
    d=document.createElement('dialog'); d.id='profDlg'; d.setAttribute('aria-label','Meu perfil');
    d.style.cssText='border:1px solid var(--stroke-hi);border-radius:18px;background:var(--panel2);color:var(--text);padding:0;width:min(92vw,380px);';
    d.innerHTML='<div style="padding:22px 24px"><h2 style="font-size:16px;font-weight:800;margin:0 0 16px">Meu perfil</h2>'
      +'<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px"><span class="tb-av" style="width:52px;height:52px;flex-basis:52px;font-size:20px">'+ADMIN_BOOT.inicialHtml+'</span><div><div style="font-weight:800">'+ADMIN_BOOT.usuarioHtml+'</div><div style="font-size:12px;color:var(--muted)">Administrador</div></div></div>'
      +'<p style="font-size:12px;color:var(--muted);line-height:1.6;margin:0 0 18px">Acesso completo ao painel: operação, gestão e a TV. Para trocar a senha, peça ao responsável pelo servidor (ela fica no arquivo de configuração).</p>'
      +'<button type="button" class="btn" style="width:100%" onclick="this.closest(\'dialog\').close()">Fechar</button></div>';
    d.addEventListener('click',e=>{ if(e.target===d) d.close(); });
    document.body.appendChild(d);
  }
  d.showModal();
}
document.addEventListener('click',e=>{ if(!e.target.closest('.tb-prof-w')) profClose(); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape') profClose(true); });
// no modo recolhido o nome de cada item aparece ao lado quando o mouse passa (CSS usa data-label)
document.querySelectorAll('.sidebar .tab-btn, .sidebar .sb-foot .sb-btn').forEach(el=>{ const l=el.querySelector('.sb-lbl'); el.dataset.label=(l?l.textContent:el.getAttribute('title')||'').trim(); });
function bellShow(btn){
  pdClose(false);
  bellRender();
  const pop = gid('bellPop'); pop.hidden = false; bellOpen = true; btn.setAttribute('aria-expanded','true');
  const r = btn.getBoundingClientRect(), w = pop.offsetWidth;
  pop.style.left = Math.max(12, Math.min(window.innerWidth - w - 12, r.right - w))+'px';
  pop.style.top  = (r.bottom + 8)+'px';
}
function bellClose(refocus){
  if(!bellOpen) return;
  gid('bellPop').hidden = true; bellOpen = false;
  const b = gid('bellBtn'); b.setAttribute('aria-expanded','false'); if(refocus) b.focus();
}
function bellGo(filter){
  bellClose(false);
  if(filter === 'acessos'){ showTab('equipes'); tmSelectTeam('all'); tmSetFilter('pendente'); }
  else if(filter === 'ferias'){ showTab('equipes'); tmSelectTeam('all'); tmSetFilter('ferias'); }
  else {                              // risco / meta batida: leva à Visão do Time (semana atual, todas as equipes) já filtrada
    PN.sel = {mode:'current'}; PN.vt = 'semana'; PN.team = 'all'; PN.status = filter === 'risco' ? 'risco' : 'meta'; PN.scrollTo = true;
    showTab('painel');
  }
}

