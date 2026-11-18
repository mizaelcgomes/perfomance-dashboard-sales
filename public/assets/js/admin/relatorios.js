// ══════════════════════════════════════════════════════════════════════════
const rpEl  = id => document.getElementById(id);
const rpBRL = v => 'R$ ' + Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
const rpNum = v => Number(v || 0).toLocaleString('pt-BR');
const rpDec = v => Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
const rpDM  = s => s ? s.slice(8,10) + '/' + s.slice(5,7) : '';
const rpPct = (a, b) => b > 0 ? Math.round(a / b * 100) : 0;
const rpHex = (c, def) => /^#[0-9a-f]{6}$/i.test(c || '') ? c : (def || '#d4af37');
const RP_CHEV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';

const rp = { ano:new Date().getFullYear(), mes:new Date().getMonth() + 1, view:'perf', perfStale:true, custoStale:true };

function rpSyncMonth(){
  document.querySelectorAll('.rpMonthLbl').forEach(e => { e.textContent = ptMonths[rp.mes-1] + ' ' + rp.ano; });
}
function rpOpen(){                                  // ao entrar em Relatórios: sempre dados frescos da aba visível
  rp.perfStale = rp.custoStale = true;
  rpSyncMonth();
  if (rp.view === 'custo') ccLoad(); else pfLoad();
}
function rpSetView(view, btn){
  rp.view = view;
  document.querySelectorAll('#rpTabs .subtab').forEach(b => { const on = b === btn; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
  document.querySelectorAll('#tabRelatorios .subpanel').forEach(p => p.classList.remove('active'));
  rpEl('rp' + view.charAt(0).toUpperCase() + view.slice(1)).classList.add('active');
  rpSyncMonth();
  if (view === 'custo' && rp.custoStale) ccLoad();
  else if (view === 'perf' && rp.perfStale) pfLoad();
}
function rpChangeMonth(d){                          // o mês vale para as duas abas
  rp.mes += d;
  if (rp.mes > 12){ rp.mes = 1;  rp.ano++; }
  if (rp.mes < 1) { rp.mes = 12; rp.ano--; }
  rp.perfStale = rp.custoStale = true;
  rpSyncMonth();
  if (rp.view === 'custo') ccLoad(); else pfLoad();
}

// Semáforo único (o PDF usa a mesma regra): meta batida ou ritmo em dia → ok; ≥ 70% do ritmo → mid; abaixo → low.
const rpEst  = e => (e === 'futura' || e === 'futuro') ? 'futura' : (e === 'atual' ? 'atual' : 'fim');
const rpFrac = (est, dec, dias) => est === 'fim' ? 1 : (est === 'futura' ? 0 : dec / Math.max(1, dias));
function rpSem(vendas, meta, est, frac){
  if (est === 'futura') return 'futura';
  if (!(meta > 0)) return 'none';
  if (vendas >= meta) return 'ok';
  const esperado = meta * frac, ritmo = esperado > 0 ? vendas / esperado : 0;
  if (ritmo >= 1) return 'ok';
  return ritmo >= .7 ? 'mid' : 'low';
}
function pfStatusTxt(sem, est, bateu){
  if (sem === 'futura') return 'Ainda não começou';
  if (sem === 'none')   return 'Sem meta';
  if (est === 'fim')    return sem === 'ok' ? 'Meta batida' : (sem === 'mid' ? 'Quase lá (≥ 70% da meta)' : 'Abaixo da meta');
  if (bateu)            return 'Meta batida';
  return sem === 'ok' ? 'No ritmo' : (sem === 'mid' ? 'Perto do ritmo' : 'Abaixo do ritmo');
}
const PF_ORIGEM = { periodo:'meta do período', cadastro:'meta do cadastro', projecao:'projeção semanal' };

// ── Performance de Closers ────────────────────────────────────────────────
const pf = { data:null, per:'mes', equipe:'', benef:'', open:new Set(), restOpen:false, anim:true, seq:0, wired:false };
const PF_TOP = 15;                                  // posições visíveis; o resto fica numa lista que se abre

async function pfLoad(){
  rp.perfStale = false;
  const body = rpEl('pfBody'), seq = ++pf.seq;
  if (!pf.data) body.innerHTML = '<div class="m-loading">Carregando...</div>';
  try{
    const d = await fetch(`${API_URL}?action=get_relatorio_closers&ano=${rp.ano}&mes=${rp.mes}`).then(r => r.json());
    if (seq !== pf.seq) return;                     // chegou depois de uma resposta mais nova
    if (!d.success){ pf.data = null; body.innerHTML = `<div class="m-loading">${esc(d.message || 'Erro ao carregar')}</div>`; return; }
    pf.data = d;
    if (pf.per !== 'mes' && !d.weeks.some(w => String(w.semana_num) === pf.per)) pf.per = 'mes';
    if (pf.equipe && pf.equipe !== '__sem' && !d.data.some(v => v.equipe_nome === pf.equipe)) pf.equipe = '';
    if (pf.benef && !d.tipos.some(t => t.k === pf.benef)) pf.benef = '';
    pf.open.clear(); pf.restOpen = false; pf.anim = true;
    pfFillFilters();
    pfRender();
  }catch(e){
    if (seq !== pf.seq) return;
    console.error(e); pf.data = null;
    body.innerHTML = '<div class="m-loading">Erro de conexão</div>';
  }
}

function pfFillFilters(){
  const d = pf.data;
  const eq = [...new Set(d.data.map(v => v.equipe_nome).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'));
  const sel = rpEl('pfEquipe'), selB = rpEl('pfBenef');
  sel.innerHTML = '<option value="">Todas as equipes</option>' + eq.map(n => `<option value="${esc(n)}">${esc(n)}</option>`).join('')
                + (d.data.some(v => !v.equipe_nome) ? '<option value="__sem">Sem equipe</option>' : '');
  sel.value = pf.equipe;
  selB.innerHTML = '<option value="">Todos os benefícios</option>' + d.tipos.map(t => `<option value="${esc(t.k)}">${esc(t.label)}${t.ativo ? '' : ' (desativado)'}</option>`).join('');
  selB.value = pf.benef;
}
function pfSetEquipe(v){ pf.equipe = v; pf.open.clear(); pf.restOpen = false; pfRender(); }
function pfSetBenef(v){ pf.benef = v; pf.restOpen = false; pfRender(); }
function pfSetPer(v){ pf.per = v; pfRender(); }

function pfRenderSeg(){
  const d = pf.data, box = rpEl('pfSeg');
  const b = (val, txt, title) => `<button type="button" data-per="${val}" aria-pressed="${pf.per === val}" title="${esc(title)}">${txt}</button>`;
  box.innerHTML = b('mes', 'Mês completo', 'Mês inteiro, com o mapa de calor de todas as semanas')
    + d.weeks.map(w => b(String(w.semana_num), 'S' + w.semana_num, `Semana ${w.semana_num}: ${rpDM(w.data_inicio)} a ${rpDM(w.data_fim)}`)).join('');
}

// período selecionado: mês completo ou uma semana configurada
function pfPeriodo(){
  const d = pf.data;
  if (pf.per !== 'mes'){
    const i = d.weeks.findIndex(w => String(w.semana_num) === pf.per);
    if (i >= 0){
      const w = d.weeks[i], est = rpEst(w.estado);
      return { tipo:'semana', idx:i, rotulo:'S' + w.semana_num, ini:w.data_inicio, fim:w.data_fim, estado:est, frac:rpFrac(est, w.dias_decorridos, w.dias), dec:w.dias_decorridos, dias:w.dias };
    }
  }
  const est = rpEst(d.estado);
  return { tipo:'mes', idx:-1, rotulo:'Mês completo', ini:d.mes_inicio, fim:d.mes_fim, estado:est, frac:rpFrac(est, d.dias_decorridos, d.dias_mes), dec:d.dias_decorridos, dias:d.dias_mes };
}
const pfEquipeOk = v => !pf.equipe || (pf.equipe === '__sem' ? !v.equipe_nome : v.equipe_nome === pf.equipe);

// linhas do período selecionado, já filtradas e ordenadas (base = só filtro de equipe; rows = base + benefício)
function pfModelo(){
  const d = pf.data, P = pfPeriodo();
  const base = d.data.filter(pfEquipeOk).map(v => {
    const sw = P.idx >= 0 ? v.semanas[P.idx] : null;
    const vendas = sw ? sw.vendas : v.vendas_mes, meta = sw ? sw.meta : v.meta_mes;
    const tipos = sw ? sw.tipos : v.tipos;
    return { v, vendas, meta, tipos, pos:sw ? sw.posicao : v.posicao_mes, semTipo:sw ? sw.sem_tipo : v.sem_tipo, pct:rpPct(vendas, meta),
             bateu:meta > 0 && vendas >= meta, sem:rpSem(vendas, meta, P.estado, P.frac), nB:pf.benef ? (tipos[pf.benef] || 0) : 0 };
  });
  const rows = (pf.benef ? base.filter(r => r.nB > 0) : base.slice())
    .sort((a, b) => (pf.benef ? b.nB - a.nB : 0) || a.pos - b.pos);   // posição = total, depois os critérios de desempate do admin (vem pronta do servidor)
  rows.forEach((r, i) => { r.pos = i + 1; });
  return { P, base, rows };
}

function pfRender(){
  const d = pf.data; if (!d) return;
  pfWire();
  rpSyncMonth();
  pfRenderSeg();
  const M = pfModelo(), body = rpEl('pfBody');
  body.classList.toggle('pf-noanim', !pf.anim);
  if (!M.rows.length){
    body.innerHTML = `<div class="pf-empty">${d.data.length ? 'Nenhum closer com contratos neste filtro.' : 'Nenhum closer cadastrado.'}</div>` + pfLegend();
    pf.anim = false; return;
  }
  const cards = M.rows.map((r, i) => pfCard(r, i, M.P));
  const rest = cards.slice(PF_TOP);
  const restHtml = rest.length
    ? `<button type="button" class="pf-rest-btn" id="pfRestBtn" aria-expanded="${pf.restOpen}" aria-controls="pfRest" onclick="pfToggleRest()"><span>${pfRestTxt(M.rows.length)}</span>${RP_CHEV}</button>
       <div class="pf-rest${pf.restOpen ? ' open' : ''}" id="pfRest"><div class="pf-rest-in">${rest.join('')}</div></div>` : '';
  body.innerHTML = `<div class="pf-scroll" id="pfScroll"><div class="pf-list">${cards.slice(0, PF_TOP).join('')}${restHtml}</div></div>` + pfSummary(M) + pfLegend();
  const sc = rpEl('pfScroll'); sc.onscroll = pfScrollShadow; pfScrollShadow();
  pf.anim = false;                                  // trocas seguintes (semana, equipe, benefício) são instantâneas
}
const pfRestTxt = n => (pf.restOpen ? 'Ocultar' : 'Ver') + ` posições ${PF_TOP + 1}–${n}`;
function pfScrollShadow(){
  const s = rpEl('pfScroll'); if (!s) return;
  s.classList.toggle('sl', s.scrollLeft > 2);
  s.classList.toggle('sr', s.scrollLeft + s.clientWidth < s.scrollWidth - 2);
}

function pfAvatar(v){
  const ini = tmInitials(v.nome);
  return `<span class="pf-av" style="--ac:${rkTone(v.cor)}">${v.imageUrl ? tmImg(v.imageUrl, ini) : esc(ini)}</span>`;
}

function pfMixHtml(r){
  const d = pf.data;
  const arr = d.tipos.map(t => [t, r.tipos[t.k] || 0]).filter(x => x[1] > 0).sort((a, b) => b[1] - a[1]);
  const aria = `Ver o mix de benefícios de ${r.v.nome}`;
  if (!arr.length) return `<button type="button" class="pf-mix" onclick="pfOpenDrawer(${r.v.id})" aria-label="${esc(aria)}"><span class="pf-none">${r.semTipo > 0 ? 'sem detalhe de benefício' : '—'}</span></button>`;
  const MAX = 4;
  const chips = arr.slice(0, MAX).map(([t, q]) =>
    `<span class="pf-chip${pf.benef === t.k ? ' hl' : (pf.benef ? ' dim' : '')}" style="--c:${rpHex(t.cor)}" title="${esc(t.label)}: ${q}">${tpSvg(t.icone)}<b>${q}</b></span>`).join('');
  return `<button type="button" class="pf-mix" onclick="pfOpenDrawer(${r.v.id})" aria-label="${esc(aria)}">${chips}${arr.length > MAX ? `<span class="pf-more-n">+${arr.length - MAX}</span>` : ''}</button>`;
}

function pfProgHtml(r, P){
  const falta = r.meta - r.vendas;
  let nota;
  if (P.estado === 'futura') nota = 'Ainda não começou';
  else if (!(r.meta > 0))    nota = 'Sem meta definida';
  else if (r.bateu)          nota = r.vendas > r.meta ? `Meta batida · <b>+${r.vendas - r.meta}</b> acima` : 'Meta batida';
  else if (P.estado === 'fim') nota = `Faltaram <b>${falta}</b>`;
  else nota = `Faltam <b>${falta}</b> · ritmo esperado hoje: <b>${Math.round(r.meta * P.frac)}</b>`;
  if (P.tipo === 'mes' && r.meta > 0) nota += ` · ${esc(PF_ORIGEM[r.v.meta_mes_origem] || '')}`;
  const benef = pf.benef ? pf.data.tipos.find(t => t.k === pf.benef) : null;
  const linhaB = benef ? `<div class="pf-pn"><b>${r.nB}</b> de ${r.vendas} em ${esc(benef.label)} (${rpPct(r.nB, r.vendas)}%)</div>` : '';
  const marca = (P.estado === 'atual' && r.meta > 0) ? `<s style="left:${(P.frac * 100).toFixed(1)}%" title="Ritmo esperado hoje"></s>` : '';
  return `<div class="pf-pl"><b>${r.vendas}<span>/${r.meta}</span></b><em>${r.pct}%</em></div>
    <div class="pf-bar" role="progressbar" aria-valuemin="0" aria-valuemax="${r.meta}" aria-valuenow="${r.vendas}" aria-label="${esc(r.v.nome)}: ${r.vendas} de ${r.meta} contratos"><i style="width:${Math.min(100, r.pct)}%"></i>${marca}</div>
    <div class="pf-pn">${nota}</div>${linhaB}`;
}

function pfHeatHtml(r, i0){
  const d = pf.data;
  return `<div class="pf-heat" role="group" aria-label="Semanas de ${esc(r.v.nome)}">` + d.weeks.map((w, wi) => {
    const s = r.v.semanas[wi], est = rpEst(w.estado), sem = rpSem(s.vendas, s.meta, est, rpFrac(est, w.dias_decorridos, w.dias));
    const aria = `Semana ${w.semana_num}, ${rpDM(w.data_inicio)} a ${rpDM(w.data_fim)}: ` + (est === 'futura' ? 'ainda não começou' : `${s.vendas} de ${s.meta} contratos, ${pfStatusTxt(sem, est, s.bateu)}`);
    return `<button type="button" class="pf-hc s-${sem}${est === 'atual' ? ' cur' : ''}" data-vid="${r.v.id}" data-w="${wi}" style="--k:${wi}" aria-label="${esc(aria)}"><i>S${w.semana_num}</i><b>${est === 'futura' ? '–' : s.vendas}</b></button>`;
  }).join('') + '</div>';
}

function pfWeekInfo(r, P){
  const sem = r.sem;
  return `<div class="pf-wk s-${sem}"><span><b>${P.rotulo}</b> · ${rpDM(P.ini)} a ${rpDM(P.fim)}</span><span class="pf-st">${pfStatusTxt(sem, P.estado, r.bateu)}</span></div>`;
}

function pfDetailHtml(r){
  const d = pf.data, v = r.v;
  const linhas = d.weeks.map((w, wi) => {
    const s = v.semanas[wi], est = rpEst(w.estado), sem = rpSem(s.vendas, s.meta, est, rpFrac(est, w.dias_decorridos, w.dias)), fut = est === 'futura';
    return `<tr><td><b>S${w.semana_num}</b></td><td class="mut">${rpDM(w.data_inicio)} – ${rpDM(w.data_fim)}</td><td class="n">${fut ? '–' : s.vendas}</td><td class="n mut">${s.meta}</td><td class="n">${fut ? '–' : rpPct(s.vendas, s.meta) + '%'}</td><td><span class="pf-st s-${sem}">${pfStatusTxt(sem, est, s.bateu)}</span></td></tr>`;
  }).join('');
  return `<div class="pf-dt-wrap"><table class="pf-dt" id="pfd-${v.id}" aria-label="Detalhe semanal de ${esc(v.nome)}">
    <thead><tr><th>Semana</th><th>Período</th><th class="n">Contratos</th><th class="n">Meta</th><th class="n">%</th><th>Situação</th></tr></thead>
    <tbody>${linhas}</tbody>
    <tfoot><tr><td colspan="2">Mês</td><td class="n">${v.vendas_mes}</td><td class="n">${v.meta_mes}</td><td class="n">${v.score_mes}%</td><td>${v.metas_sem_batidas}/${v.total_semanas} semanas batidas</td></tr></tfoot>
  </table></div>`;
}

function pfCard(r, i, P){
  const v = r.v, open = pf.open.has(v.id);
  const tag = v.ex_closer ? '<span class="pf-tag">ex-closer</span>' : (v.suspenso ? '<span class="pf-tag">suspenso</span>' : '')
            + (v.e_lider ? '<span class="pf-tag" title="Líder da equipe">líder</span>' : '');
  return `<article class="pf-card${v.ex_closer || v.suspenso ? ' off' : ''}${open ? ' open' : ''} s-${r.sem}" data-vid="${v.id}" style="--i:${Math.min(i, 14)}">
    <div class="pf-row">
      <div class="pf-id">
        <span class="pf-rank${r.pos <= 3 ? ' r' + r.pos : ''}" aria-label="Posição ${r.pos}">${r.pos}</span>
        ${pfAvatar(v)}
        <div class="pf-who"><span class="pf-nm" title="${esc(v.nome)}">${esc(v.nome)}${tag}</span><span class="pf-tm" style="--tc:${rpHex(v.equipe_cor)}"><i></i>${esc(v.equipe_nome || 'Sem equipe')}</span></div>
      </div>
      <div class="pf-prog">${pfProgHtml(r, P)}</div>
      <div class="pf-heatc">${P.tipo === 'mes' ? pfHeatHtml(r, i) : pfWeekInfo(r, P)}</div>
      <div class="pf-mixc">${pfMixHtml(r)}</div>
      <div class="pf-act">
        <div class="pf-tot"><b>${r.vendas}</b><span>contratos</span></div>
        <button type="button" class="pf-tg" aria-expanded="${open}" aria-controls="pfd-${v.id}" onclick="pfToggle(${v.id})">Ver detalhe semanal ${RP_CHEV}</button>
      </div>
    </div>
    <div class="pf-more"><div class="pf-more-in">${pfDetailHtml(r)}</div></div>
  </article>`;
}

function pfToggle(vid){
  const card = document.querySelector(`.pf-card[data-vid="${vid}"]`); if (!card) return;
  const on = !card.classList.contains('open');
  card.classList.toggle('open', on);
  const b = card.querySelector('.pf-tg'); if (b) b.setAttribute('aria-expanded', String(on));
  if (on) pf.open.add(vid); else pf.open.delete(vid);
}
function pfToggleRest(){
  pf.restOpen = !pf.restOpen;
  const box = rpEl('pfRest'), btn = rpEl('pfRestBtn'); if (!box || !btn) return;
  box.classList.toggle('open', pf.restOpen);
  btn.setAttribute('aria-expanded', String(pf.restOpen));
  btn.firstElementChild.textContent = pfRestTxt(pfModelo().rows.length);
}

function pfSummary(M){
  const P = M.P, B = M.base;                        // o resumo vale para a equipe filtrada (o filtro de benefício só filtra a lista)
  const tv = B.reduce((a, r) => a + r.vendas, 0), tm = B.reduce((a, r) => a + r.meta, 0);
  const bat = B.filter(r => r.bateu).length;
  const sem = rpSem(tv, tm, P.estado, P.frac);
  let sbat = 0, stot = 0;
  if (P.tipo === 'mes') B.forEach(r => { sbat += r.v.metas_sem_batidas; stot += r.v.total_semanas; });
  let benef = '';
  if (pf.benef){
    const t = pf.data.tipos.find(x => x.k === pf.benef), tn = B.reduce((a, r) => a + r.nB, 0);
    benef = `<div><div class="k">${esc(t ? t.label : 'Benefício')}</div><div class="v" style="color:${rpHex(t && t.cor)}">${tn}</div><div class="d">${rpPct(tn, tv)}% dos contratos · ${M.rows.length} closer${M.rows.length === 1 ? '' : 's'}</div></div>`;
  }
  return `<section class="pf-sum" aria-label="Resumo">
    ${benef}
    <div><div class="k">Total de contratos</div><div class="v">${rpNum(tv)}</div><div class="d">${esc(P.rotulo)} · ${B.length} closer${B.length === 1 ? '' : 's'}</div></div>
    <div><div class="k">Meta</div><div class="v">${rpNum(tm)}</div><div class="d">${P.tipo === 'mes' ? 'soma das metas do mês' : 'soma das metas da semana'}</div></div>
    <div class="s-${sem}"><div class="k">Score</div><div class="v s-${sem}">${rpPct(tv, tm)}<small>%</small></div><div class="pf-bar" aria-hidden="true"><i style="width:${Math.min(100, rpPct(tv, tm))}%"></i></div></div>
    <div><div class="k">Batidas</div><div class="v">${bat}<small>/${B.length}</small></div><div class="d">${P.tipo === 'mes' ? `metas do mês · ${sbat}/${stot} metas semanais` : 'metas da semana'}</div></div>
  </section>`;
}
function pfLegend(){
  return `<div class="pf-legend" aria-label="Legenda das cores">
    <span class="s-ok"><i></i>Meta batida ou no ritmo</span><span class="s-mid"><i></i>≥ 70% do ritmo</span><span class="s-low"><i></i>Abaixo do ritmo</span>
    <span class="s-futura"><i></i>Ainda não começou</span><span><i class="cur"></i>Semana em curso</span>
  </div>`;
}

// ── tooltip do heatmap e eventos da lista ──
let pfTipEl = null;
function pfTipBox(){
  if (!pfTipEl){ pfTipEl = document.createElement('div'); pfTipEl.className = 'pf-tip'; pfTipEl.setAttribute('role', 'tooltip'); pfTipEl.hidden = true; document.body.appendChild(pfTipEl); }
  return pfTipEl;
}
function pfTipFor(cell){
  const d = pf.data; if (!d) return;
  const v = d.data.find(x => x.id === +cell.dataset.vid), wi = +cell.dataset.w; if (!v) return;
  const w = d.weeks[wi], s = v.semanas[wi], est = rpEst(w.estado), sem = rpSem(s.vendas, s.meta, est, rpFrac(est, w.dias_decorridos, w.dias));
  const estTxt = est === 'atual' ? `em curso · dia ${w.dias_decorridos} de ${w.dias}` : (est === 'futura' ? 'ainda não começou' : 'encerrada');
  const mix = d.tipos.map(t => [t, s.tipos[t.k] || 0]).filter(x => x[1] > 0).sort((a, b) => b[1] - a[1]);
  let h = `<div class="h"><span>${esc(v.nome)}</span></div><div class="m"><b>Semana ${w.semana_num}</b> · ${rpDM(w.data_inicio)} → ${rpDM(w.data_fim)} · ${estTxt}</div>`;
  if (est !== 'futura'){
    h += `<div style="margin-top:4px"><b>${s.vendas}</b> de <b>${s.meta}</b> (${rpPct(s.vendas, s.meta)}%) · <span class="st">${pfStatusTxt(sem, est, s.bateu)}</span></div>`;
    if (!s.bateu && s.meta > s.vendas) h += `<div class="m">${est === 'fim' ? 'Faltaram' : 'Faltam'} ${s.meta - s.vendas} para a meta</div>`;
    if (mix.length) h += '<div class="mx">' + mix.slice(0, 5).map(([t, q]) => `<span style="--c:${rpHex(t.cor)}"><i></i>${esc(t.label)}: <b>${q}</b></span>`).join('') + (mix.length > 5 ? `<span class="m">+${mix.length - 5} benefício(s)</span>` : '') + '</div>';
    else if (s.vendas > 0) h += '<div class="m">sem detalhe de benefício</div>';
  }
  const tip = pfTipBox();
  tip.style.setProperty('--sc', `var(--sem-${sem === 'futura' || sem === 'none' ? 'off' : sem})`);
  tip.innerHTML = h; tip.hidden = false;
  const r = cell.getBoundingClientRect(), tw = tip.offsetWidth, th = tip.offsetHeight;
  tip.style.left = Math.max(8, Math.min(innerWidth - tw - 8, r.left + r.width / 2 - tw / 2)) + 'px';
  tip.style.top  = (r.top - th - 10 < 8 ? r.bottom + 10 : r.top - th - 10) + 'px';
  document.querySelectorAll('.pf-hc.on').forEach(c => c.classList.remove('on')); cell.classList.add('on');
}
function pfHideTip(){ if (pfTipEl) pfTipEl.hidden = true; document.querySelectorAll('.pf-hc.on').forEach(c => c.classList.remove('on')); }

function pfWire(){
  if (pf.wired) return; pf.wired = true;
  const body = rpEl('pfBody'), seg = rpEl('pfSeg');
  body.addEventListener('mouseover', e => { const c = e.target.closest('.pf-hc'); if (c) pfTipFor(c); });
  body.addEventListener('mouseout',  e => { if (e.target.closest('.pf-hc')) pfHideTip(); });
  body.addEventListener('focusin',   e => { const c = e.target.closest('.pf-hc'); if (c) pfTipFor(c); });
  body.addEventListener('focusout',  e => { if (e.target.closest('.pf-hc')) pfHideTip(); });
  body.addEventListener('click',     e => { const c = e.target.closest('.pf-hc'); if (c) pfTipFor(c); });     // toque (celular): abre/mantém a dica
  document.addEventListener('click', e => { if (!e.target.closest('.pf-hc')) pfHideTip(); });
  window.addEventListener('scroll', pfHideTip, true);
  window.addEventListener('resize', () => { pfHideTip(); pfScrollShadow(); });
  seg.addEventListener('click', e => { const b = e.target.closest('button[data-per]'); if (b) pfSetPer(b.dataset.per); });
  seg.addEventListener('keydown', e => {            // ← → trocam o período; o foco acompanha
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    const bs = [...seg.querySelectorAll('button')], i = bs.findIndex(b => b.getAttribute('aria-pressed') === 'true');
    const n = bs[Math.max(0, Math.min(bs.length - 1, i + (e.key === 'ArrowRight' ? 1 : -1)))];
    if (n){ e.preventDefault(); pfSetPer(n.dataset.per); const nb = seg.querySelector(`button[data-per="${n.dataset.per}"]`); if (nb) nb.focus(); }
  });
  const dlg = rpEl('pfDrawer');
  dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });                                  // clique no fundo fecha
}

// ── Drawer: mix de benefícios completo de um closer (mês inteiro) ──
function pfOpenDrawer(vid){
  const d = pf.data, v = d && d.data.find(x => x.id === vid); if (!v) return;
  const mesTxt = ptMonths[d.mes - 1] + ' ' + d.ano;
  const est = rpEst(d.estado), frac = rpFrac(est, d.dias_decorridos, d.dias_mes);
  const sem = rpSem(v.vendas_mes, v.meta_mes, est, frac);
  const arr = d.tipos.map(t => [t, v.tipos[t.k] || 0]).filter(x => x[1] > 0).sort((a, b) => b[1] - a[1]);
  const soma = arr.reduce((a, x) => a + x[1], 0) + v.sem_tipo;
  rpEl('pfDwHead').innerHTML = `<div class="pf-dw-head">${pfAvatar(v)}<div style="min-width:0"><div class="tm-eyebrow">Mix de benefícios · ${esc(mesTxt)}</div><h3>${esc(v.nome)}</h3><div class="tm-head-sub">${esc(v.equipe_nome || 'Sem equipe')}${v.ex_closer ? ' · ex-closer' : (v.suspenso ? ' · suspenso' : '')}</div></div></div>`;
  const stack = arr.map(([t, q], i) => `<i style="--c:${rpHex(t.cor)};flex:${q};animation-delay:${i * 50}ms" title="${esc(t.label)}: ${q}"></i>`).join('')
              + (v.sem_tipo > 0 ? `<i style="--c:#8a8a8a;flex:${v.sem_tipo}" title="Sem detalhe: ${v.sem_tipo}"></i>` : '');
  const lista = arr.map(([t, q]) => `<li style="--c:${rpHex(t.cor)}">${tpSvg(t.icone)}<span>${esc(t.label)}${t.ativo ? '' : ' <span class="pf-tag">desativado</span>'}</span><span class="q">${q}</span><span class="p">${rpPct(q, soma)}%</span></li>`).join('')
              + (v.sem_tipo > 0 ? `<li style="--c:#8a8a8a">${tpSvg('file')}<span>Sem detalhe de benefício</span><span class="q">${v.sem_tipo}</span><span class="p">${rpPct(v.sem_tipo, soma)}%</span></li>` : '');
  const cab = d.weeks.map(w => `<th title="${rpDM(w.data_inicio)} a ${rpDM(w.data_fim)}">S${w.semana_num}</th>`).join('');
  const mx = arr.map(([t, q]) => `<tr style="--c:${rpHex(t.cor)}"><td><i></i>${esc(t.label)}</td>${v.semanas.map(s => { const n = s.tipos[t.k] || 0; return `<td class="${n ? '' : 'z'}">${n || '·'}</td>`; }).join('')}<td class="t">${q}</td></tr>`).join('')
           + (v.sem_tipo > 0 ? `<tr style="--c:#8a8a8a"><td><i></i>Sem detalhe</td>${v.semanas.map(s => `<td class="${s.sem_tipo ? '' : 'z'}">${s.sem_tipo || '·'}</td>`).join('')}<td class="t">${v.sem_tipo}</td></tr>` : '');
  rpEl('pfDwBody').innerHTML = `
    <div class="pf-dw-stats">
      <div class="pf-dw-stat"><div class="k">Contratos no mês</div><div class="v">${v.vendas_mes}</div></div>
      <div class="pf-dw-stat"><div class="k">Meta do mês</div><div class="v">${v.meta_mes}</div></div>
      <div class="pf-dw-stat s-${sem}"><div class="k">Score</div><div class="v s-${sem}">${v.score_mes}%</div></div>
      <div class="pf-dw-stat"><div class="k">Semanas batidas</div><div class="v">${v.metas_sem_batidas}/${v.total_semanas}</div></div>
    </div>
    ${arr.length || v.sem_tipo ? `<div class="pf-dw-h">Mix do mês</div><div class="pf-stack" aria-hidden="true">${stack}</div><ul class="pf-bl">${lista}</ul>
    <div class="pf-dw-h">Benefício × semana</div><div style="overflow-x:auto"><table class="pf-mx"><thead><tr><th>Benefício</th>${cab}<th>Mês</th></tr></thead><tbody>${mx}</tbody></table></div>
    ${arr.reduce((a, x) => a + x[1], 0) !== v.vendas_mes ? `<p class="tm-hint warn">O detalhe por benefício soma <b>${arr.reduce((a, x) => a + x[1], 0)}</b>, mas o total lançado nos dias do mês é <b>${v.vendas_mes}</b>. A diferença vem de lançamentos em que o total do dia e o benefício não ficaram em sintonia (dados antigos).</p>` : ''}
    <p class="cc-note">As semanas seguem o Calendário e podem incluir dias do mês vizinho; por isso a soma das semanas pode diferir do total do mês.</p>`
    : '<div class="pf-empty" style="margin-top:8px">Nenhum contrato neste mês.</div>'}`;
  rpEl('pfDrawer').showModal();
}

// ── Exportar ──
function rpDownload(blob, nome){
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = nome;
  document.body.appendChild(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
}
// CSV = tabela COMPLETA do mês (todas as semanas e todos os benefícios), qualquer que seja a semana/benefício na tela.
// Só o filtro de equipe vale. BOM + ";" para o Excel pt-BR abrir acentos e colunas certos.
function pfExportCsv(){
  const d = pf.data;
  if (!d || !d.data.length){ toast('Nada para exportar', true); return; }
  const q = x => { const t = String(x ?? ''); return /[";\r\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t; };
  const estM = rpEst(d.estado), fracM = rpFrac(estM, d.dias_decorridos, d.dias_mes);
  const rows = d.data.filter(pfEquipeOk).sort((a, b) => b.vendas_mes - a.vendas_mes || b.score_mes - a.score_mes || a.nome.localeCompare(b.nome, 'pt-BR'));
  const head = ['Posição', 'Closer', 'Equipe', 'Líder da equipe', 'É o líder', 'Situação'];
  d.weeks.forEach(w => head.push(`S${w.semana_num} (${rpDM(w.data_inicio)}-${rpDM(w.data_fim)}) contratos`, `S${w.semana_num} meta`, `S${w.semana_num} situação`));
  head.push('Contratos no mês', 'Meta do mês', 'Origem da meta do mês', '% da meta do mês', 'Situação do mês', 'Semanas batidas', 'Total de semanas');
  d.tipos.forEach(t => head.push(t.label));
  head.push('Sem detalhe de benefício');
  const lines = [head.map(q).join(';')];
  const tot = { sem:d.weeks.map(() => 0), mes:0, meta:0, sb:0, st:0, tipos:{}, semTipo:0 };
  rows.forEach((v, i) => {
    const r = [i + 1, v.nome, v.equipe_nome || '', v.equipe_lider || '', v.e_lider ? 'Sim' : 'Não', v.ex_closer ? 'Ex-closer' : (v.suspenso ? 'Suspenso' : 'Ativo')];
    v.semanas.forEach((s, wi) => {
      const w = d.weeks[wi], est = rpEst(w.estado), sem = rpSem(s.vendas, s.meta, est, rpFrac(est, w.dias_decorridos, w.dias));
      r.push(est === 'futura' ? '' : s.vendas, s.meta, pfStatusTxt(sem, est, s.bateu)); tot.sem[wi] += s.vendas;
    });
    r.push(v.vendas_mes, v.meta_mes, PF_ORIGEM[v.meta_mes_origem] || '', v.score_mes + '%', pfStatusTxt(rpSem(v.vendas_mes, v.meta_mes, estM, fracM), estM, v.bateu_mes), v.metas_sem_batidas, v.total_semanas);
    d.tipos.forEach(t => { const n = v.tipos[t.k] || 0; r.push(n); tot.tipos[t.k] = (tot.tipos[t.k] || 0) + n; });
    r.push(v.sem_tipo);
    tot.mes += v.vendas_mes; tot.meta += v.meta_mes; tot.sb += v.metas_sem_batidas; tot.st += v.total_semanas; tot.semTipo += v.sem_tipo;
    lines.push(r.map(q).join(';'));
  });
  const t = ['', 'TOTAL (' + rows.length + ' closers)', '', '', '', ''];
  tot.sem.forEach(n => t.push(n, '', ''));
  t.push(tot.mes, tot.meta, '', rpPct(tot.mes, tot.meta) + '%', '', tot.sb, tot.st);
  d.tipos.forEach(x => t.push(tot.tipos[x.k] || 0));
  t.push(tot.semTipo);
  lines.push(t.map(q).join(';'));
  const nome = `performance-closers-${d.ano}-${String(d.mes).padStart(2, '0')}.csv`;
  rpDownload(new Blob(['﻿' + lines.join('\r\n')], {type:'text/csv;charset=utf-8;'}), nome);
  toast('CSV exportado: ' + nome);
}

// PDF gerado no servidor (pdf-simples.php): quebra de página, cabeçalho repetido e "Página x de y". Só o filtro de equipe vale.
async function pfExportPdf(){
  const btn = rpEl('pfBtnPdf'), html = btn.innerHTML;
  if (btn.disabled) return;
  btn.disabled = true; btn.textContent = 'Gerando...';
  try{
    const r = await fetch(`${API_URL}?action=relatorio_closers_pdf&ano=${rp.ano}&mes=${rp.mes}` + (pf.equipe ? `&equipe=${encodeURIComponent(pf.equipe)}` : ''), {credentials:'same-origin'});
    if (!r.ok || !/application\/pdf/i.test(r.headers.get('content-type') || '')){
      let msg = 'Não foi possível gerar o PDF.';
      try{ const j = await r.json(); if (j && j.message) msg = j.message; }catch(e){}
      toast(msg, true); return;
    }
    const nome = `relatorio-closers-${rp.ano}-${String(rp.mes).padStart(2, '0')}.pdf`;
    rpDownload(await r.blob(), nome);
    toast('PDF gerado: ' + nome);
  }catch(e){ console.error(e); toast('Erro de conexão ao gerar o PDF', true); }
  finally{ btn.disabled = false; btn.innerHTML = html; }
}

// ── Custo por Contrato ────────────────────────────────────────────────────
const cc = { d:null, hist:[], metaCac:85, chart:null, seq:0 };

async function ccLoad(){
  rp.custoStale = false;
  const seq = ++cc.seq, box = rpEl('ccKpis');
  if (!cc.d) box.innerHTML = '<div class="m-loading" style="grid-column:1/-1">Carregando...</div>';
  try{
    const [a, h] = await Promise.all([
      fetch(`${API_URL}?action=get_custo_contrato&ano=${rp.ano}&mes=${rp.mes}`).then(r => r.json()),
      fetch(API_URL+'?action=get_investimento_historico').then(r => r.json())
    ]);
    if (seq !== cc.seq) return;
    if (!a.success){ box.innerHTML = `<div class="m-loading" style="grid-column:1/-1">${esc(a.message || 'Erro ao carregar')}</div>`; return; }
    cc.d = a; cc.hist = h.success ? (h.data || []) : [];
    cc.metaCac = parseFloat(a.meta_cac) || 0;
    rpEl('miMetaCac').value = cc.metaCac || '';
    rpSyncMonth();
    ccRender();
  }catch(e){
    if (seq !== cc.seq) return;
    console.error(e); box.innerHTML = '<div class="m-loading" style="grid-column:1/-1">Erro de conexão</div>';
  }
}
function ccRender(){ ccRenderKpis(); ccRenderChart(); ccRenderHist(); }

function ccSpark(vals){
  const v = vals.filter(x => x != null);
  if (v.length < 2) return '<div class="cc-spark-w"></div>';
  const mn = Math.min(...v), mx = Math.max(...v), rg = (mx - mn) || 1;
  const pts = v.map((y, i) => [i / (v.length - 1) * 100, 4 + (1 - (y - mn) / rg) * 28]);
  const ln = pts.map(p => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' '), ult = pts[pts.length - 1];
  return `<div class="cc-spark-w"><svg class="cc-spark" viewBox="0 0 100 36" preserveAspectRatio="none" aria-hidden="true"><polygon class="a" points="0,36 ${ln} 100,36"/><polyline class="l" points="${ln}"/></svg><i class="cc-dot" style="left:100%;top:${(ult[1] / 36 * 100).toFixed(1)}%"></i></div>`;
}

function ccKpiList(d){
  const est = rpEst(d.estado), frac = rpFrac(est, d.dias_decorridos, d.dias_mes);
  const met = d.meta_contratos, mCac = d.meta_cac, inv = d.investimento, orc = d.orcamento, mdia = d.meta_contratos_dia;
  const sv = d.serie.filter(s => s.ctr_acum !== null), out = [];

  { // 1 · contratos do mês
    const sem = rpSem(d.contratos, met, est, frac);
    let nota = met > 0 ? (d.contratos >= met ? 'Meta batida' : `Faltam ${rpNum(met - d.contratos)}` + (est === 'atual' ? ` · ritmo esperado hoje: ${rpNum(Math.round(met * frac))}` : '')) : 'Cadastre a meta em Metas & Regras';
    out.push({ k:'Contratos do mês', v:rpNum(d.contratos), sem, fill:rpPct(d.contratos, met), marker:(est === 'atual' && met > 0) ? frac * 100 : null,
               meta:met > 0 ? `Meta ${rpNum(met)}` : 'Sem meta', mp:met > 0 ? rpPct(d.contratos, met) + '%' : '', nota, spark:sv.map(s => s.ctr_acum) });
  }
  { // 2 · CAC (quanto menor, melhor): a barra vai até 150% da meta e a marca fica na própria meta
    const tem = d.cac != null && mCac > 0, ratio = tem ? d.cac / mCac : 0;
    const sem = !tem ? 'none' : (ratio <= 1 ? 'ok' : (ratio <= 1.15 ? 'mid' : 'low'));
    out.push({ k:'CAC do mês', v:d.cac != null ? rpBRL(d.cac) : '—', sem, fill:tem ? ratio / 1.5 * 100 : 0, marker:tem ? 100 / 1.5 : null,
               meta:mCac > 0 ? `Meta ${rpBRL(mCac)}` : 'Sem meta de CAC', mp:tem ? (ratio > 1 ? '+' : '') + Math.round((ratio - 1) * 100) + '% vs meta' : '',
               nota:d.cac == null ? 'Informe o investimento do mês para calcular' : (ratio <= 1 ? 'Dentro da meta' : 'Acima da meta'), spark:sv.map(s => s.cac_acum) });
  }
  { // 3 · investimento × orçamento implícito (meta de CAC × meta de contratos)
    const tem = inv > 0 && orc > 0, esp = orc * (est === 'fim' ? 1 : frac), ratio = (tem && esp > 0) ? inv / esp : 0;
    const sem = !tem ? 'none' : (ratio <= 1 ? 'ok' : (ratio <= 1.15 ? 'mid' : 'low'));
    out.push({ k:'Investimento', v:inv > 0 ? rpBRL(inv) : '—', sem, fill:orc > 0 ? rpPct(inv, orc) : 0, marker:(est === 'atual' && orc > 0) ? frac * 100 : null,
               meta:orc > 0 ? `Orçamento ${rpBRL(orc)}` : 'Sem orçamento', mp:(inv > 0 && orc > 0) ? rpPct(inv, orc) + '%' : '',
               nota:inv > 0 ? `${rpBRL(inv / Math.max(1, d.dias_decorridos))} por dia · orçamento = meta de CAC × meta de contratos` : 'Investimento do mês não informado', spark:sv.map(s => s.inv_acum) });
  }
  { // 4 · contratos por dia × ritmo necessário
    const val = d.contratos_dia, sem = (mdia > 0 && est !== 'futura') ? (val >= mdia ? 'ok' : (val >= mdia * .7 ? 'mid' : 'low')) : 'none';
    const rest = d.dias_mes - d.dias_decorridos;
    const precisa = (est === 'atual' && met > d.contratos && rest > 0) ? ` · precisa de ${rpDec((met - d.contratos) / rest)}/dia ${rest === 1 ? 'no dia que falta' : 'nos ' + rest + ' dias que faltam'}` : '';
    out.push({ k:'Contratos por dia', v:rpDec(val), sem, fill:mdia > 0 ? rpPct(val, mdia) : 0, marker:null,
               meta:mdia > 0 ? `Meta ${rpDec(mdia)}/dia` : 'Sem meta', mp:mdia > 0 ? rpPct(val, mdia) + '%' : '',
               nota:`${d.dias_decorridos} de ${d.dias_mes} dias${precisa}`, spark:sv.map(s => s.contratos) });
  }
  return out;
}
function ccRenderKpis(){
  const d = cc.d;
  rpEl('ccKpis').innerHTML = ccKpiList(d).map((k, i) => `<article class="cc-kpi s-${k.sem}" style="--i:${i}">
    <div class="k">${k.k}</div><div class="v">${k.v}</div>${ccSpark(k.spark)}
    <div class="cc-meta-l"><span>${k.meta}</span><b>${k.mp}</b></div>
    <div class="pf-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(Math.min(100, k.fill))}" aria-label="${esc(k.k)}"><i style="width:${Math.min(100, Math.max(0, k.fill)).toFixed(1)}%"></i>${k.marker != null ? `<s style="left:${k.marker.toFixed(1)}%"></s>` : ''}</div>
    <div class="pf-pn" title="${esc(k.nota)}">${esc(k.nota)}</div>
  </article>`).join('');
}

function ccRenderChart(){
  const d = cc.d, cv = rpEl('ccChart');
  if (!cv || typeof Chart === 'undefined') return;
  if (cc.chart){ cc.chart.destroy(); cc.chart = null; }
  const est = rpEst(d.estado);
  rpEl('ccChartNote').textContent = est === 'futura' ? 'Este mês ainda não começou.'
    : 'O sistema guarda o investimento por mês, então a curva dourada distribui o total de forma uniforme pelos dias decorridos (' + d.dias_decorridos + '). Os contratos são os reais de cada dia.';
  const gold = '#d4af37', green = '#2ecc71', mut = '#8d8777';
  const g = cv.getContext('2d').createLinearGradient(0, 0, 0, 320);
  g.addColorStop(0, 'rgba(212,175,55,.26)'); g.addColorStop(1, 'rgba(212,175,55,0)');
  const compact = v => v >= 1000 ? (v / 1000).toFixed(v % 1000 ? 1 : 0).replace('.', ',') + 'k' : v;
  cc.chart = new Chart(cv, {
    type:'line',
    data:{ labels:d.serie.map(s => s.dia), datasets:[
      { label:'Investimento acumulado', data:d.serie.map(s => s.inv_acum), yAxisID:'y', borderColor:gold, backgroundColor:g, fill:true, tension:.3, borderWidth:2.5, pointRadius:0, pointHoverRadius:5, pointBackgroundColor:gold },
      { label:'Contratos acumulados', data:d.serie.map(s => s.ctr_acum), yAxisID:'y1', borderColor:green, fill:false, tension:.3, borderWidth:2.5, pointRadius:0, pointHoverRadius:5, pointBackgroundColor:green }
    ]},
    options:{
      responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false},
      plugins:{
        legend:{position:'top', align:'end', labels:{color:'#c9c2ad', usePointStyle:true, boxWidth:8, font:{size:11}}},
        tooltip:{ backgroundColor:'#0d0d0d', borderColor:gold, borderWidth:1, titleColor:'#e8c860', bodyColor:'#f4f0e6', padding:12, cornerRadius:6,
          callbacks:{
            title:items => 'Dia ' + rpDM(d.serie[items[0].dataIndex].data),
            label:c => c.raw == null ? ' sem dados' : (c.datasetIndex === 0 ? ' Investimento acumulado: ' + rpBRL(c.raw) : ' Contratos acumulados: ' + rpNum(c.raw)),
            afterBody:items => { const s = d.serie[items[0].dataIndex]; return s.cac_acum != null ? ['', 'CAC acumulado: ' + rpBRL(s.cac_acum)] : []; }
          } }
      },
      scales:{
        x:{ grid:{display:false}, ticks:{color:mut, font:{size:10}, maxTicksLimit:16}, title:{display:true, text:'Dia do mês', color:mut, font:{size:10}} },
        y:{ position:'left', beginAtZero:true, grid:{color:'rgba(212,175,55,.08)'}, ticks:{color:gold, font:{size:10}, callback:v => 'R$ ' + compact(v)} },
        y1:{ position:'right', beginAtZero:true, grid:{drawOnChartArea:false}, ticks:{color:green, font:{size:10}, precision:0} }
      }
    }
  });
}

function ccRenderHist(){
  const box = rpEl('ccHist'), serie = cc.hist, atual = rp.ano + '-' + String(rp.mes).padStart(2, '0');
  if (!serie.length){ box.innerHTML = '<div class="m-loading">Nenhum mês com contrato ou investimento ainda. Use “+ Mês” para começar.</div>'; return; }
  const semCac = c => (c == null || !(cc.metaCac > 0)) ? 'none' : (c <= cc.metaCac ? 'ok' : (c <= cc.metaCac * 1.15 ? 'mid' : 'low'));
  const linhas = serie.map((r, i) => {
    const ant = i > 0 ? serie[i - 1] : null;
    let vr = '<span class="cc-var s-none">—</span>';
    if (r.cac != null && ant && ant.cac != null){
      const p = (r.cac / ant.cac - 1) * 100;
      vr = Math.abs(p) < .05 ? '<span class="cc-var s-none">= 0%</span>'
         : `<span class="cc-var s-${p > 0 ? 'low' : 'ok'}" title="CAC de ${ptMonths[ant.mes-1]}: ${rpBRL(ant.cac)}">${p > 0 ? '▲ +' : '▼ −'}${Math.abs(p).toLocaleString('pt-BR', {maximumFractionDigits:1})}%</span>`;
    }
    const inp = `<input class="mi-inp" type="number" min="0" step="0.01" value="${r.investimento || ''}" placeholder="0,00" aria-label="Investimento de ${ptMonths[r.mes-1]} ${r.ano}" data-ano="${r.ano}" data-mes="${r.mes}" data-campo="investimento" onclick="event.stopPropagation()" onkeydown="event.stopPropagation()" onchange="miSalvarLinha(this)">`;
    return `<tr class="cc-row${r.ym === atual ? ' sel' : ''}" data-ym="${r.ym}" data-ano="${r.ano}" data-mes="${r.mes}" tabindex="0" role="button" aria-label="Ver ${ptMonths[r.mes-1]} ${r.ano}">
      <td class="cc-mes"><b>${ptMonths[r.mes-1]} ${r.ano}</b>${r.parcial ? ' <span class="pf-tag">em curso</span>' : ''}<small>${r.dias} dias</small></td>
      <td><b style="color:#f0d878">${rpNum(r.contratos)}</b></td>
      <td style="min-width:130px">${inp}</td>
      <td><span class="cc-cac s-${semCac(r.cac)}">${r.cac == null ? '—' : rpBRL(r.cac)}</span></td>
      <td>${vr}</td>
      <td><button type="button" class="btn btn-s btn-sm" onclick="event.stopPropagation();miExcluir(${r.ano},${r.mes})" title="Limpar o investimento deste mês" aria-label="Limpar o investimento de ${ptMonths[r.mes-1]} ${r.ano}">${ICO.x}</button></td>
    </tr>`;
  }).reverse().join('');
  box.innerHTML = `<div class="tt-wrap"><table class="cc-tbl"><thead><tr><th>Mês</th><th>Contratos</th><th>Investimento</th><th>CAC</th><th>Variação CAC</th><th></th></tr></thead><tbody>${linhas}</tbody></table></div>`;
  if (!box.dataset.wired){
    box.dataset.wired = '1';
    const ir = tr => { rp.ano = +tr.dataset.ano; rp.mes = +tr.dataset.mes; rp.perfStale = true; rpSyncMonth(); ccLoad(); };
    box.addEventListener('click', e => { const tr = e.target.closest('tr.cc-row'); if (tr && !e.target.closest('input,button')) ir(tr); });
    box.addEventListener('keydown', e => { const tr = e.target.closest('tr.cc-row'); if (tr && e.target === tr && (e.key === 'Enter' || e.key === ' ')){ e.preventDefault(); ir(tr); } });
  }
}

async function miSalvarLinha(el){
  const ano = el.dataset.ano, mes = el.dataset.mes;
  const linha = cc.hist.find(r => String(r.ano) === String(ano) && String(r.mes) === String(mes));
  if (!linha) return;
  linha.investimento = parseFloat(el.value) || 0;
  const fd = new FormData();
  fd.append('action', 'save_investimento_mensal');
  ['ano', 'mes'].forEach(k => fd.append(k, linha[k]));
  ['investimento', 'alcance', 'impressoes', 'cpm', 'frequencia'].forEach(k => fd.append(k, linha[k] || 0));   // o que não aparece aqui segue intacto
  try{
    const d = await fetch(API_URL, {method:'POST', body:fd}).then(r => r.json());
    if (!d.success){ toast(d.message || 'Erro ao salvar', true); return; }
    toast('Salvo');
    ccLoad();                                       // recalcula CAC, variação, KPIs e gráfico
  }catch{ toast('Erro de conexão', true); }
}
async function miExcluir(ano, mes){
  if (!confirm(`Limpar os dados de mídia de ${ptMonths[mes-1]} ${ano}? Os contratos do mês não são afetados.`)) return;
  const bd = new URLSearchParams();
  bd.append('action', 'delete_investimento_mensal'); bd.append('ano', ano); bd.append('mes', mes);
  const d = await fetch(API_URL, {method:'POST', body:bd}).then(r => r.json());
  if (d.success){ toast(d.message); ccLoad(); } else toast(d.message, true);
}
async function miSaveMetaCac(){
  const fd = new FormData();
  fd.append('action', 'save_meta_cac');
  fd.append('meta_cac', rpEl('miMetaCac').value || 0);
  const d = await fetch(API_URL, {method:'POST', body:fd}).then(r => r.json());
  if (d.success){ toast(d.message); ccLoad(); } else toast(d.message, true);
}
function miAddMes(){
  const hoje = new Date();
  const txt = prompt('Qual mês adicionar? Formato AAAA-MM (ex: 2026-08)', hoje.getFullYear() + '-' + String(hoje.getMonth() + 1).padStart(2, '0'));
  if (!txt) return;
  const m = /^(\d{4})-(\d{1,2})$/.exec(txt.trim());
  if (!m || +m[2] < 1 || +m[2] > 12){ toast('Formato inválido. Use AAAA-MM.', true); return; }
  const fd = new FormData();
  fd.append('action', 'save_investimento_mensal');
  fd.append('ano', m[1]); fd.append('mes', String(+m[2])); fd.append('investimento', '0');
  fetch(API_URL, {method:'POST', body:fd}).then(r => r.json()).then(d => {
    if (d.success){ toast('Mês adicionado — preencha o investimento'); ccLoad(); } else toast(d.message, true);
  }).catch(() => toast('Erro de conexão', true));
}

