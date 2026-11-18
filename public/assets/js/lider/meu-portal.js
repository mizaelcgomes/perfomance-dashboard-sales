/*
 * Portal do Closer (closer.php) usa este mesmo arquivo: lá o Meu dia ganha a meta da semana/mês, a posição no ranking, as últimas interações do líder e a
 * evolução com a média da equipe (cartões #mdMeta, #mdInter; só existem na página do closer), e os Meus contratos ganham o "Hoje" e a barra da meta.
 * Portal do Líder, grupo "MEU PORTAL (CLOSER)": o líder também é closer, então o portal do closer vive aqui dentro, com o mesmo visual do resto.
 *   · Meu dia: resumo do dia (ligações, follow-ups, leads quentes), últimas ações (clique abre a edição), minha evolução de 7 dias e
 *     "Meus feedbacks" (o que o líder dele registrou; só aparece se houver algo).
 *   · Meus contratos: semana ou mês, com escolha do mês e da semana, indicadores, gráfico por dia e benefícios fechados.
 * Os dados vêm das mesmas ações do closer-api.php do portal do closer (resumo, acoes, evolucao, contratos, meus_feedbacks), sempre da conta
 * logada. Registrar, editar e excluir ações: quick.js e acoes.js (LP.acoesMudaram avisa esta tela).
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP) return;
  const E = LD.esc, $ = id => document.getElementById(id);
  const DOW = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
  const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
  const fmt1 = n => Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 1 });
  const dm = s => s.slice(8, 10) + '/' + s.slice(5, 7);
  const cap = s => s.charAt(0).toUpperCase() + s.slice(1);
  const dia = { carregou: false };
  const ic = k => (LP.boot.ic && LP.boot.ic[k]) || '';

  function rotuloDia(s) {
    if (s === LP.hoje) return 'Hoje';
    if (s === LP.addDias(LP.hoje, -1)) return 'Ontem';
    return cap(DOW[LP.asDate(s).getDay()]) + ', ' + dm(s);
  }
  function tendencia(atual, anterior) {
    if (anterior > 0) { const p = Math.round((atual - anterior) / anterior * 100); return '<span class="mp-trend ' + (p > 0 ? 'up' : p < 0 ? 'down' : 'flat') + '">' + (p > 0 ? '▲ ' : p < 0 ? '▼ ' : '= ') + Math.abs(p) + '%</span>'; }
    return atual > 0 ? '<span class="mp-trend up">▲ novo</span>' : '';
  }

  // ═══ Meu dia ══════════════════════════════════════════════════════════════════════════════════
  function setResumo(r) {
    [['nLig', 'ligacao'], ['nFu', 'followup'], ['nLq', 'lead_quente']].forEach(([id, k]) => {
      const el = $(id), v = String(r[k] | 0);
      if (el.textContent === v) return;
      el.textContent = v;
      const c = el.closest('.mp-sum-c'); c.classList.remove('bump'); void c.offsetWidth; c.classList.add('bump');
    });
  }
  async function carregarResumo() {
    const r = await LP.api('resumo', {});
    if (r && r.success) { if (r.hoje) LP.hoje = r.hoje; setResumo(r.resumo); }
  }
  async function carregarRecentes() {
    const r = await LP.api('acoes', { periodo: 'todos', limit: 5 });
    const box = $('mdRecentes');
    if (!r || !r.success) { box.innerHTML = LD.erroTela((r && r.message) || '', 'mdRecRetry'); const b = $('mdRecRetry'); if (b) b.onclick = carregarRecentes; return; }
    if (!r.acoes.length) { box.innerHTML = '<div class="ld-empty"><strong>Nenhuma ação ainda</strong>Use o Registro de Ação Rápida acima para registrar a primeira.</div>'; return; }
    let ult = null, html = '';
    r.acoes.forEach(a => {
      if (a.data !== ult) { html += '<h3 class="lp-dia-h">' + E(rotuloDia(a.data)) + '</h3>'; ult = a.data; }
      html += LP.linhaAcao(a);
    });
    box.innerHTML = '<div class="mp-rec">' + html + '</div>';
  }
  /** r = {dias: [{data, total, media_equipe?}], total, anterior, media_equipe_total?}: a linha dourada é do closer; a tracejada, a média da equipe. */
  function desenharEvolucao(r) {
    const d = r.dias, comMedia = d.length && d[0].media_equipe !== undefined, max = Math.max(4, ...d.map(x => Math.max(x.total, comMedia ? x.media_equipe : 0))), W = 300, H = 150, L = 14, R = 14, T = 22, B = 26;
    const x = i => L + i * (W - L - R) / (d.length - 1), y = v => T + (H - T - B) * (1 - v / max);
    const pts = d.map((p, i) => [x(i), y(p.total)]);
    const linha = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
    const area = linha + ' L' + pts[pts.length - 1][0].toFixed(1) + ' ' + (H - B) + ' L' + pts[0][0].toFixed(1) + ' ' + (H - B) + ' Z';
    const media = comMedia ? d.map((p, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p.media_equipe).toFixed(1)).join(' ') : '';
    const aria = 'Ações por dia nos últimos 7 dias: ' + d.map(p => dm(p.data) + ' ' + p.total).join(', ');
    $('evChart').innerHTML = '<svg class="mp-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + E(aria) + '">'
      + '<defs><linearGradient id="mpEvG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#d4af37" stop-opacity=".38"/><stop offset="1" stop-color="#d4af37" stop-opacity="0"/></linearGradient></defs>'
      + [0, .5, 1].map(f => '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + (T + (H - T - B) * f) + '" y2="' + (T + (H - T - B) * f) + '" stroke="rgba(255,255,255,.07)"/>').join('')
      + '<path d="' + area + '" fill="url(#mpEvG)"/>'
      + (media ? '<path d="' + media + '" fill="none" stroke="#60a5fa" stroke-width="1.8" stroke-dasharray="5 4" stroke-linecap="round"><title>Média da equipe</title></path>' : '')
      + '<path d="' + linha + '" fill="none" stroke="#fbbf24" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"/>'
      + pts.map((p, i) => '<circle cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="' + (i === pts.length - 1 ? 4.5 : 3.2) + '" fill="#0a0a0a" stroke="#fbbf24" stroke-width="2"><title>' + E(rotuloDia(d[i].data)) + ': ' + d[i].total + (d[i].total === 1 ? ' ação' : ' ações') + (comMedia ? ' · média da equipe ' + fmt1(d[i].media_equipe) : '') + '</title></circle>'
        + (d[i].total ? '<text x="' + p[0].toFixed(1) + '" y="' + (p[1] - 9).toFixed(1) + '" text-anchor="middle" font-size="9.5" font-weight="800" fill="#f3f3f3">' + d[i].total + '</text>' : '')
        + '<text x="' + p[0].toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="9.5" font-weight="' + (d[i].data === LP.hoje ? 800 : 500) + '" fill="' + (d[i].data === LP.hoje ? '#fbbf24' : 'rgba(255,255,255,.5)') + '">' + DOW[LP.asDate(d[i].data).getDay()] + '</text>').join('')
      + '</svg>';
    $('evTxt').innerHTML = comMedia && r.equipe_n > 1
      ? 'Você: <b>' + r.total + '</b> ' + (r.total === 1 ? 'ação' : 'ações') + ' · Média da equipe: <b>' + r.media_equipe_total + '</b>' + (r.anterior ? ' ' + tendencia(r.total, r.anterior) : '') + '<span class="cp-ev-leg"><i class="eu"></i>Você<i class="eq"></i>Média da equipe</span>'
      : 'Você fez <b>' + r.total + '</b> ' + (r.total === 1 ? 'ação' : 'ações') + ' nos últimos 7 dias, <b>' + r.anterior + '</b> nos 7 anteriores.' + (r.anterior ? tendencia(r.total, r.anterior) : '');
  }
  async function carregarEvolucao() {
    if (LP.closer) return carregarMeuDia();                          // no portal do closer a evolução vem junto do resto do Meu dia
    const r = await LP.api('evolucao', {});
    if (!r || !r.success) { $('evTxt').textContent = 'Não foi possível carregar a evolução.'; return; }
    desenharEvolucao(r);
  }

  // ═══ Portal do closer: meta, ranking, interações recentes (cartões do Meu dia que só existem em closer.php) ═════════════════════════
  const MD = { per: LD.ler('mdMetaPer') === 'mes' ? 'mes' : 'semana', dados: null, seq: 0 };
  const faixa = pct => (pct >= 80 ? 'ok' : pct >= 40 ? 'mid' : 'bad');            // verde ≥ 80%, amarelo 40 a 79%, vermelho < 40%
  function renderMeta() {
    const d = MD.dados; if (!d || !$('mdMeta')) return;
    const mes = MD.per === 'mes', m = d.meta[MD.per], pct = Math.max(0, Math.min(100, m.pct)), f = faixa(m.pct);
    $('mdMetaTit').textContent = mes ? 'Meta do mês' : 'Meta da semana';
    $('mdMetaPer').textContent = !mes && d.semana ? 'Semana ' + d.semana.num + ' · ' + dm(d.semana.start) + ' a ' + dm(d.semana.end) : MESES[+d.hoje.slice(5, 7) - 1] + ' de ' + d.hoje.slice(0, 4);
    $('mdMetaBar').innerHTML = '<div class="cp-bar ' + f + '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '" aria-label="Progresso da meta"><i style="width:' + pct + '%"></i></div>';
    $('mdMetaNum').innerHTML = '<b>' + m.vendas + '</b> / ' + m.meta + ' <span class="cp-pct ' + f + '">' + m.pct + '%</span>';
    $('mdMetaTxt').textContent = m.meta <= 0 ? 'Sem meta definida para este período.'
      : m.vendas >= m.meta ? '🎉 Você bateu a meta' + (m.bateu_em_dias ? ' em ' + m.bateu_em_dias + (m.bateu_em_dias === 1 ? ' dia' : ' dias') : '') + '!'
      : 'Faltam ' + m.faltam + (m.faltam === 1 ? ' contrato' : ' contratos') + ' para bater a meta.';
    document.querySelectorAll('#mdMetaSeg button').forEach(b => { const on = b.dataset.per === MD.per; b.classList.toggle('active', on); b.setAttribute('aria-selected', String(on)); });
  }
  async function carregarMeuDia() {
    if (!$('mdMeta')) return;
    const seq = ++MD.seq, r = await LP.api('portal_meu_dia', {});
    if (seq !== MD.seq) return;
    if (!r || !r.success) { $('evTxt').textContent = 'Não foi possível carregar o resumo.'; $('mdMetaTxt').textContent = (r && r.message) || 'Não foi possível carregar a meta.'; return; }
    MD.dados = r; renderMeta(); desenharEvolucao(r.evolucao);
  }
  if ($('mdMetaSeg')) $('mdMetaSeg').addEventListener('click', e => { const b = e.target.closest('[data-per]'); if (!b || b.dataset.per === MD.per) return; MD.per = b.dataset.per; LD.salvar('mdMetaPer', MD.per); renderMeta(); });
  const NOME_TIPO = { feedback: 'Feedback', cobranca: 'Cobrança', revisao: 'Revisão' };
  async function carregarInter() {
    const box = $('mdInter'); if (!box) return;
    const r = await LP.api('portal_interacoes', { tipo: 'recentes' });
    if (!r || !r.success) { box.innerHTML = '<p class="cp-vazio">Não foi possível carregar as interações.</p>'; return; }
    if (!r.itens.length) { box.innerHTML = '<p class="cp-vazio">Nenhuma interação ainda. Seu líder registra aqui as conversas com você.</p>'; return; }
    box.innerHTML = '<ul class="cp-inter">' + r.itens.map(i => '<li><button type="button" data-tipo="' + E(i.tipo) + '"><time>' + dm(i.data) + '</time><span><b>' + E(i.lider_nome || 'Seu líder') + '</b>: ' + E(i.texto || i.titulo) + '</span>' + (i.novo ? '<em class="cp-novo">NOVO</em>' : '') + '</button></li>').join('') + '</ul>';
    box.querySelectorAll('button[data-tipo]').forEach(b => { b.onclick = () => LP.ir('interacoes', { tipo: b.dataset.tipo }); });
  }
  if ($('mdInterVer')) $('mdInterVer').addEventListener('click', () => LP.ir('interacoes'));

  // "Meus feedbacks": o que o líder dele registrou para ele (só leitura). Escondido enquanto não houver nada; com novidade abre sozinho e, ao abrir,
  // marca tudo como visto (o "novo" some na próxima visita, mas continua marcado nesta tela).
  const fb = { dados: null, aberto: false, marcou: false };
  function cartaoFb(i) {
    const tipo = i.tipo === 'cobranca' ? 'Cobrança de cadastramento' : 'Feedback';
    return '<article class="ld-reg' + (i.novo ? ' novo' : '') + '"><div class="ld-reg-h"><h4>' + E(i.titulo) + '</h4>'
      + '<span>' + (i.novo ? '<span class="ld-st novo">' + (i.atualizado ? 'Atualizado' : 'Novo') + '</span>' : (i.atualizado ? '<span class="ld-chip mute">Atualizado</span>' : '')) + '</span></div>'
      + '<div class="ld-reg-m">' + (i.tipo === 'cobranca' ? '' : '<span>' + tipo + '</span>') + '<span>de <b>' + E(i.lider_nome || 'seu líder') + '</b></span><span>' + LD.dataBR(i.data) + '</span></div>'
      + (i.texto ? '<p class="ld-reg-t">' + E(i.texto) + '</p>' : '') + '</article>';
  }
  function renderFb() {
    $('mdFbLista').innerHTML = fb.dados.itens.map(cartaoFb).join('');
    const n = fb.dados.novos, bd = $('mdFbBd');
    bd.hidden = !(n > 0 && !fb.marcou); bd.textContent = n + (n === 1 ? ' novo' : ' novos');
  }
  async function abrirFb(abrir) {
    fb.aberto = abrir;
    $('mdFbLista').hidden = !abrir;
    const t = $('mdFbToggle'); t.textContent = abrir ? 'Recolher' : 'Abrir'; t.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    if (abrir && fb.dados && fb.dados.novos > 0 && !fb.marcou) {
      const r = await LP.api('meus_feedbacks_vistos', {});
      if (r && r.success) { fb.marcou = true; renderFb(); }
    }
  }
  async function carregarFeedbacks() {
    const r = await LP.api('meus_feedbacks', {});
    if (!r || !r.success || !r.itens || !r.itens.length) return;       // nada recebido: o cartão continua escondido
    fb.dados = r; fb.marcou = false; $('mdFeedbacks').hidden = false; renderFb(); abrirFb(r.novos > 0);
  }
  $('mdFbToggle').addEventListener('click', () => abrirFb(!fb.aberto));

  function carregarDia() {
    dia.carregou = true;
    $('mdRecentes').innerHTML = LD.skeleton();
    carregarResumo(); carregarRecentes(); carregarEvolucao(); carregarInter();
    if (!LP.closer) carregarFeedbacks();      // no portal do closer os feedbacks ficam em Minhas interações
  }
  // histórico completo das ações: um cartão no próprio Meu dia (com filtros), montado na primeira vez que abre
  function historico(abrir) {
    const card = $('mdHistCard'), b = $('mdHist');
    if (abrir) { if (!LP.acoesMontada) { LP.acoesMontada = true; LP.mod.acoes.montar($('mdHistBody')); } else LP.mod.acoes.mostrar(); }
    card.hidden = !abrir; b.setAttribute('aria-expanded', String(abrir)); b.textContent = abrir ? 'Ocultar histórico' : 'Ver histórico completo';
    if (abrir) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  $('mdHist').addEventListener('click', () => historico($('mdHistCard').hidden));
  $('mdHistX').addEventListener('click', () => historico(false));
  $('mdRecentes').addEventListener('click', e => { const l = e.target.closest('.lp-ac[data-id]'); if (l) LP.editarAcao(+l.dataset.id); });
  $('mdRecentes').addEventListener('keydown', e => { if (e.key !== 'Enter' && e.key !== ' ') return; const l = e.target.closest('.lp-ac[data-id]'); if (l) { e.preventDefault(); LP.editarAcao(+l.dataset.id); } });

  // ═══ Meus contratos ═══════════════════════════════════════════════════════════════════════════
  const CT = { per: 'semana', ano: null, mes: null, sem: null, seq: 0, data: null, carregou: false };
  { const s = LD.ler('ctPer'); if (s && [...document.querySelectorAll('#ctSeg button')].some(b => b.dataset.per === s)) CT.per = s; }
  const CORES = ['#d4af37', '#34d399', '#60a5fa', '#a78bfa', '#fb7185', '#fbbf24', '#2dd4bf', '#f472b6'];
  function sincSeg() { document.querySelectorAll('#ctSeg button').forEach(b => { const on = b.dataset.per === CT.per; b.classList.toggle('active', on); b.setAttribute('aria-selected', String(on)); }); }
  $('ctSeg').addEventListener('click', e => {
    const b = e.target.closest('[data-per]'); if (!b || b.dataset.per === CT.per) return;
    CT.per = b.dataset.per; CT.sem = null;                         // ao trocar de aba, a semana volta ao padrão do mês escolhido
    LD.salvar('ctPer', CT.per); sincSeg(); carregarContratos();
  });
  function mudarMes(dir) {                                          // mês anterior / próximo, dentro do que tem dados até o mês atual
    const d = CT.data; if (!d || CT.ano === null) return;
    let y = CT.ano, m = CT.mes + dir;
    if (m < 1) { m = 12; y--; } else if (m > 12) { m = 1; y++; }
    const ym = y + '-' + String(m).padStart(2, '0');
    if (ym < d.mes_min || ym > d.mes_max) return;
    CT.ano = y; CT.mes = m; CT.sem = null; carregarContratos();
  }
  $('ctPrev').addEventListener('click', () => mudarMes(-1));
  $('ctNext').addEventListener('click', () => mudarMes(1));
  $('ctSems').addEventListener('click', e => { const b = e.target.closest('[data-sem]'); if (!b || b.disabled) return; CT.sem = +b.dataset.sem; carregarContratos(); });
  async function carregarContratos() {
    const seq = ++CT.seq;
    const r = await LP.api('contratos', { periodo: CT.per, ano: CT.ano, mes: CT.mes, semana: CT.sem });
    if (seq !== CT.seq) return;
    if (!r || !r.success) { $('ctChart').innerHTML = '<div class="mp-empty">' + E((r && r.message) || 'Não foi possível carregar os contratos.') + '</div>'; return; }
    CT.carregou = true; CT.ano = r.ano; CT.mes = r.mes; CT.sem = r.semana;      // o servidor resolve o mês e a semana padrão
    CT.data = r; renderContratos();
  }
  function delta(atual, anterior, rotulo) { return '<div class="mp-k-d">' + tendencia(atual, anterior) + '<span class="mp-k-p">' + rotulo + ': ' + anterior + '</span></div>'; }
  function renderContratos() {
    const d = CT.data; if (!d) return;
    const sem = d.periodo === 'semana', w = sem ? d.semanas.find(x => x.num === d.semana) : null;
    $('ctSems').hidden = !sem; $('ctNav').hidden = d.periodo === 'hoje';
    const ym = d.ano + '-' + String(d.mes).padStart(2, '0');
    $('ctMesLbl').textContent = MESES[d.mes - 1] + ' de ' + d.ano;
    $('ctPrev').disabled = ym <= d.mes_min; $('ctNext').disabled = ym >= d.mes_max;
    if (sem) $('ctSems').innerHTML = d.semanas.map(x => '<button type="button" data-sem="' + x.num + '" aria-pressed="' + (x.num === d.semana) + '"' + (x.num === d.semana ? ' class="active"' : '') + (x.futura ? ' disabled' : '') + '>S' + x.num + (x.atual ? ' · atual' : '') + '<small>' + dm(x.start) + ' a ' + dm(x.end) + '</small></button>').join('');
    $('ctCap').innerHTML = d.periodo === 'hoje' ? '<b>Hoje</b> · ' + dm(d.range.start) : sem ? (w ? '<b>Semana ' + w.num + '</b> · ' + dm(w.start) + ' a ' + dm(w.end) : '')
      : d.range.end >= d.range.hoje ? '<b>Mês em andamento</b> · ' + dm(d.range.start) + ' a ' + dm(d.range.hoje) : '<b>Mês fechado</b> · ' + dm(d.range.start) + ' a ' + dm(d.range.end);
    const k = (rot, val, extra) => '<div class="mp-k"><small>' + rot + '</small><b>' + val + '</b>' + (extra || '') + '</div>';
    const melhor = d.melhor ? d.melhor.qtd + '<span>' + dm(d.melhor.data) + '</span>' : '—';
    $('ctKpis').innerHTML = k('Contratos', d.total, delta(d.total, d.anterior, 'antes')) + k('Média/dia', fmt1(d.media) + '<span>/dia</span>') + k('Melhor dia', melhor);
    graficoContratos(d, w && w.futura ? 'Esta semana ainda não começou.' : 'Nenhum contrato registrado neste período.'); beneficios(d); metaContratos(d);
  }
  /** Portal do closer: a barra da meta do período (verde ≥ 80%, amarelo 40 a 79%, vermelho < 40%). Hoje usa a meta diária, se o admin definiu. */
  function metaContratos(d) {
    const box = $('ctMeta'); if (!box) return;
    if (d.meta === null || d.meta === undefined || d.meta <= 0) { box.innerHTML = d.periodo === 'hoje' ? '' : '<p class="cp-vazio">Sem meta definida para este período.</p>'; return; }
    const pct = Math.round(d.total / d.meta * 100), f = faixa(pct), w = Math.max(0, Math.min(100, pct));
    box.innerHTML = '<div class="cp-meta-l"><span>Meta ' + (d.periodo === 'hoje' ? 'do dia' : d.periodo === 'mes' ? 'do mês' : 'da semana') + '</span><b>' + d.total + ' / ' + d.meta + ' <span class="cp-pct ' + f + '">' + pct + '%</span></b></div>'
      + '<div class="cp-bar ' + f + '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + w + '" aria-label="Progresso da meta"><i style="width:' + w + '%"></i></div>';
  }
  function graficoContratos(d, vazio) {
    const box = $('ctChart');
    if (!d.serie.some(p => p.qtd)) { box.innerHTML = '<div class="mp-empty"><b aria-hidden="true">' + ic('inbox') + '</b>' + E(vazio) + '</div>'; return; }
    const S = d.serie, n = S.length, W = Math.max(280, Math.round(box.clientWidth || 320)), H = 200, L = 28, R = 6, T = 20, B = 36;
    const max = Math.max(3, ...S.map(p => p.qtd || 0));
    const passo = max <= 5 ? 1 : max <= 10 ? 2 : max <= 20 ? 5 : Math.ceil(max / 4 / 5) * 5, yMax = Math.ceil(max / passo) * passo;
    const faixa = (W - L - R) / n, bw = Math.min(30, Math.max(3, faixa * .66));
    const x = i => L + faixa * i + faixa / 2, y = v => T + (H - T - B) * (1 - v / yMax), base = y(0);
    let s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Contratos por dia: ' + S.reduce((a, p) => a + (p.qtd || 0), 0) + ' no gráfico"><defs><linearGradient id="mpCtG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fbbf24"/><stop offset="1" stop-color="#d4af37" stop-opacity=".35"/></linearGradient></defs>';
    for (let v = 0; v <= yMax; v += passo) s += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y(v).toFixed(1) + '" y2="' + y(v).toFixed(1) + '" stroke="rgba(255,255,255,.07)"/><text x="' + (L - 6) + '" y="' + (y(v) + 3.5).toFixed(1) + '" text-anchor="end" font-size="9.5" fill="rgba(255,255,255,.4)">' + v + '</text>';
    const cada = Math.max(1, Math.ceil(26 / faixa)), valores = faixa >= 20, duasLinhas = n <= 14;
    S.forEach((p, i) => {
      const cx = x(i), hj = p.data === d.range.hoje, fds = [0, 6].includes(LP.asDate(p.data).getDay());
      const nome = cap(DOW[LP.asDate(p.data).getDay()]) + ' ' + dm(p.data);
      if (p.qtd === null) s += '<rect x="' + (cx - bw / 2).toFixed(1) + '" y="' + (base - 2).toFixed(1) + '" width="' + bw.toFixed(1) + '" height="2" rx="1" fill="rgba(255,255,255,.1)"/>';
      else {
        const h = Math.max(2, base - y(p.qtd));
        s += '<rect x="' + (cx - bw / 2).toFixed(1) + '" y="' + (base - h).toFixed(1) + '" width="' + bw.toFixed(1) + '" height="' + h.toFixed(1) + '" rx="' + Math.min(5, bw / 3).toFixed(1) + '" fill="' + (p.qtd ? 'url(#mpCtG)' : 'rgba(255,255,255,.12)') + '"' + (fds && p.qtd ? ' opacity=".55"' : '') + (hj ? ' stroke="#fff" stroke-width="1.5"' : '') + '><title>' + E(nome + ': ' + p.qtd + (p.qtd === 1 ? ' contrato' : ' contratos')) + '</title></rect>';
        if (valores && p.qtd) s += '<text x="' + cx.toFixed(1) + '" y="' + (base - h - 5).toFixed(1) + '" text-anchor="middle" font-size="10" font-weight="800" fill="#f3f3f3">' + p.qtd + '</text>';
      }
      if (i % cada === 0) {
        s += '<text x="' + cx.toFixed(1) + '" y="' + (H - 18) + '" text-anchor="middle" font-size="10" font-weight="' + (hj ? 800 : 500) + '" fill="' + (hj ? '#fbbf24' : fds ? 'rgba(212,175,55,.65)' : 'rgba(255,255,255,.55)') + '">' + p.data.slice(8, 10) + '</text>';
        if (duasLinhas) s += '<text x="' + cx.toFixed(1) + '" y="' + (H - 5) + '" text-anchor="middle" font-size="9" fill="rgba(255,255,255,.35)">' + DOW[LP.asDate(p.data).getDay()] + '</text>';
      }
    });
    box.innerHTML = s + '</svg>';
  }
  const icBen = k => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ((LP.boot.tipoIc || {})[k] || (LP.boot.tipoIc || {}).file || '') + '</svg>';
  function beneficios(d) {
    const linhas = d.beneficios.map(r => ({ label: r.label, qtd: r.qtd, pct: r.pct, cor: r.cor, icone: r.icone })), den = Math.max(d.total, d.com_tipo);
    if (d.sem_tipo > 0) linhas.push({ label: 'Sem tipo informado', qtd: d.sem_tipo, pct: den ? Math.round(d.sem_tipo / den * 1000) / 10 : 0, none: true });
    $('ctBenN').textContent = d.com_tipo ? d.com_tipo + ' com tipo registrado' : '';
    const box = $('ctBen');
    if (!linhas.length) { box.innerHTML = '<div class="mp-empty" style="padding:22px">Nenhum benefício registrado neste período.</div>'; return; }
    const cor = (r, i) => r.none ? '#6b6b6b' : (r.cor || CORES[i % CORES.length]);          // a cor do benefício é a mesma do painel do admin
    box.innerHTML = '<div class="mp-stack" role="img" aria-label="Proporção de contratos por benefício">' + linhas.map((r, i) => '<i style="width:' + r.pct + '%;background:' + cor(r, i) + '" title="' + E(r.label + ': ' + r.qtd) + '"></i>').join('') + '</div>'
      + linhas.map((r, i) => '<div class="mp-row' + (r.none ? ' none' : '') + '" style="--bc:' + cor(r, i) + '">' + (r.none ? '<i></i>' : '<i class="mp-ic">' + icBen(r.icone) + '</i>') + '<span title="' + E(r.label) + '">' + E(r.label) + '</span><b>' + r.qtd + '</b><em>' + fmt1(r.pct) + '%</em></div>').join('');
  }
  // os contratos são lançados pelo admin: ao voltar para a aba do navegador, atualiza; o gráfico se redesenha quando a largura muda
  document.addEventListener('visibilitychange', () => { if (!document.hidden && LP.aba === 'contratos') carregarContratos(); });
  let rz; window.addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => { if (LP.aba === 'contratos') renderContratos(); }, 160); });
  sincSeg();

  // ═══ registro nas abas ════════════════════════════════════════════════════════════════════════
  LP.mod['meu-dia'] = { mostrar() { if (!dia.carregou) carregarDia(); else carregarResumo(); }, recarregar: carregarDia };
  LP.mod.contratos = {
    mostrar(op) {
      if (op && op.per && [...document.querySelectorAll('#ctSeg button')].some(b => b.dataset.per === op.per) && op.per !== CT.per) { CT.per = op.per; CT.sem = null; LD.salvar('ctPer', CT.per); sincSeg(); carregarContratos(); return; }
      if (!CT.carregou) carregarContratos(); else renderContratos();
    },
    recarregar: carregarContratos,
    atual: () => CT.per,      // o submenu da barra lateral marca esta opção
  };
  // chamado por LP.acoesMudaram: uma ação foi criada, editada ou excluída. Se o Meu dia já foi aberto, atualiza os contadores e as listas
  LP.mod.meuportal = {
    aposAcao(resumo) {
      if (!dia.carregou) return;
      if (resumo) setResumo(resumo); else carregarResumo();
      carregarRecentes(); carregarEvolucao();
      if (LP.closer) carregarInter();
    },
  };
})();
