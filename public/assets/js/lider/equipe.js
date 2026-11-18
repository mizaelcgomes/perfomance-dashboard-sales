/*
 * Portal do Líder, aba Equipe (gestão individual): um cartão por closer da equipe, com a performance da semana, as métricas dos últimos 30 dias
 * (reuniões, cobranças, revisões, feedbacks recebidos e treinamentos concluídos), os alertas do closer e os botões Novo Feedback e Ver Histórico.
 * "Ver Histórico" abre um painel lateral com a visão geral do closer (status, meta da semana e do mês, métricas dos 30 dias), a produtividade e
 * os contratos (com os benefícios fechados) da SEMANA ou do MÊS à escolha, o gráfico de evolução dos últimos 30 dias (contratos em barras, ações
 * em linha) e a linha do tempo das interações (feedbacks, cobranças, revisões, treinamentos e reuniões). Dados: lider_equipe e lider_closer
 * (só closers da equipe do líder).
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP) return;
  const E = LD.esc, $ = id => document.getElementById(id);
  const estado = { carregou: false, seq: 0, closers: [] };
  const s = n => (n === 1 ? '' : 's');

  function cartao(c) {
    const pct = c.meta > 0 ? Math.min(100, Math.round(c.vendas / c.meta * 100)) : 0, m = c.metricas;
    const met = (rot, titulo, v) => '<div title="' + E(titulo) + '"><small>' + rot + '</small><b>' + v + '</b></div>';
    const al = c.alertas.slice(0, 3).map(a => '<div class="lp-al ' + (a.severidade === 'positivo' ? 'positivo' : '') + '">' + E(a.titulo) + '</div>').join('');
    return '<article class="lp-cl" data-id="' + c.id + '"><div class="lp-cl-h">' + LD.avatar(c.nome, c.cor) + '<h3 title="' + E(c.nome) + '">' + E(c.nome) + '</h3><span class="lp-st ' + c.status + '">' + E(c.status_rotulo) + '</span></div>'
      + '<div class="lp-cl-p"><div><span>Semana</span><b>' + c.vendas + '/' + c.meta + ' contratos</b></div>'
      + '<div class="lp-bar ' + c.status + '" role="progressbar" aria-valuemin="0" aria-valuemax="' + c.meta + '" aria-valuenow="' + c.vendas + '" aria-label="' + E(c.nome) + ': ' + c.vendas + ' de ' + c.meta + ' contratos na semana"><i style="--p:' + pct + '%"></i></div>'
      + '<div><span>Mês</span><b>' + c.vendas_mes + '/' + c.meta_mes + ' contratos</b></div></div>'
      + '<div><p class="lp-met-l">Últimos 30 dias</p><div class="lp-met">'
      + met('Reuniões', 'Reuniões em que participou', m.reunioes) + met('Cobranças', 'Cobranças recebidas', m.cobrancas) + met('Revisões', 'Revisões de atendimento', m.revisoes)
      + met('Feedbacks', 'Feedbacks recebidos', m.feedbacks) + met('Treinos', 'Treinamentos concluídos (presença)', m.treinamentos) + '</div></div>'
      + (al ? '<div class="lp-cl-al">' + al + '</div>' : '')
      + '<div class="lp-cl-a"><button type="button" class="ld-btn sm pri" data-op="feedback">Novo feedback</button><button type="button" class="ld-btn sm" data-op="historico">Ver histórico</button></div></article>';
  }

  async function carregar() {
    const meu = ++estado.seq, lista = $('eqLista');
    if (!estado.carregou) lista.innerHTML = LD.skeleton();
    const r = await LP.api('lider_equipe', {});
    if (meu !== estado.seq) return;
    if (!r || !r.success) { lista.innerHTML = '<div style="grid-column:1/-1">' + LD.erroTela((r && r.message) || '', 'eqRetry') + '</div>'; const b = $('eqRetry'); if (b) b.onclick = carregar; return; }
    estado.carregou = true; estado.closers = r.closers;
    $('eqSub').innerHTML = r.closers.length + ' closer' + s(r.closers.length) + ' · métricas dos <b>últimos ' + r.dias + ' dias</b>';
    lista.innerHTML = r.closers.length ? r.closers.map(cartao).join('') : '<div class="ld-empty" style="grid-column:1/-1"><strong>Nenhum closer na equipe</strong>Os closers da sua equipe aparecem aqui.</div>';
  }

  // ── gráfico de evolução (SVG, sem biblioteca) ─────────────────────────────────────────────────
  function grafico(ev) {
    const W = 560, H = 190, pl = 8, pr = 8, pt = 12, pb = 26, n = ev.datas.length, area = H - pt - pb, bw = (W - pl - pr) / n;
    const maxC = Math.max(1, ...ev.contratos), maxA = Math.max(1, ...ev.acoes);
    let barras = '', pontos = [], rotulos = '';
    ev.datas.forEach((d, i) => {
      const h = ev.contratos[i] / maxC * area;
      if (ev.contratos[i] > 0) barras += '<rect x="' + (pl + i * bw + bw * 0.15).toFixed(1) + '" y="' + (H - pb - h).toFixed(1) + '" width="' + (bw * 0.7).toFixed(1) + '" height="' + h.toFixed(1) + '" rx="2" fill="#34d399"><title>' + LD.dm(d) + ': ' + ev.contratos[i] + ' contrato' + s(ev.contratos[i]) + '</title></rect>';
      pontos.push((pl + i * bw + bw / 2).toFixed(1) + ',' + (H - pb - ev.acoes[i] / maxA * area).toFixed(1));
      if (i % 5 === 0 || i === n - 1) rotulos += '<text x="' + (pl + i * bw + bw / 2).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="10" fill="rgba(255,255,255,.6)">' + LD.dm(d) + '</text>';
    });
    const linha = ev.acoes.some(v => v > 0) ? '<polyline points="' + pontos.join(' ') + '" fill="none" stroke="#60a5fa" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>' : '';
    return '<div class="lp-evo"><svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Evolução dos últimos 30 dias: ' + ev.total_contratos + ' contratos e ' + ev.total_acoes + ' ações">'
      + '<line x1="' + pl + '" y1="' + (H - pb) + '" x2="' + (W - pr) + '" y2="' + (H - pb) + '" stroke="rgba(255,255,255,.18)"/>' + barras + linha + rotulos + '</svg>'
      + '<div class="lp-evo-l"><span><i style="background:#34d399"></i>Contratos por dia (<b>' + ev.total_contratos + '</b> no total)</span><span><i style="background:#60a5fa"></i>Ações por dia (<b>' + ev.total_acoes + '</b> no total)</span></div></div>';
  }

  // ── detalhe do closer (painel lateral) ────────────────────────────────────────────────────────
  const TIPOS = { feedback: 'Feedback', cobranca: 'Cobrança', revisao: 'Revisão', treinamento: 'Treinamento', reuniao: 'Reunião' };
  const fmt1 = n => Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 1 });
  const icBen = k => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ((LP.boot.tipoIc || {})[k] || (LP.boot.tipoIc || {}).file || '') + '</svg>';
  const kcard = (rot, val, extra) => '<div class="mp-k"><small>' + rot + '</small><b>' + val + '</b>' + (extra ? '<div class="mp-k-d">' + extra + '</div>' : '') + '</div>';

  /** Os benefícios fechados no período (a mesma barra e as mesmas linhas do Meus contratos). */
  function beneficios(ct) {
    const comTipo = ct.beneficios.reduce((a, b) => a + b.qtd, 0), den = Math.max(ct.total, comTipo), pct = q => (den ? Math.round(q / den * 1000) / 10 : 0);
    const linhas = ct.beneficios.map(b => ({ label: b.label, qtd: b.qtd, pct: pct(b.qtd), cor: b.cor, icone: b.icone }));
    if (ct.sem_tipo > 0) linhas.push({ label: 'Sem tipo informado', qtd: ct.sem_tipo, pct: pct(ct.sem_tipo), none: true });
    if (!linhas.length) return '<div class="mp-empty" style="padding:22px">Nenhum benefício registrado neste período.</div>';
    const cor = r => (r.none ? '#6b6b6b' : r.cor);
    return '<div class="mp-stack" role="img" aria-label="Proporção de contratos por benefício">' + linhas.map(r => '<i style="width:' + r.pct + '%;background:' + cor(r) + '" title="' + E(r.label + ': ' + r.qtd) + '"></i>').join('') + '</div>'
      + linhas.map(r => '<div class="mp-row' + (r.none ? ' none' : '') + '" style="--bc:' + cor(r) + '">' + (r.none ? '<i></i>' : '<i class="mp-ic">' + icBen(r.icone) + '</i>') + '<span title="' + E(r.label) + '">' + E(r.label) + '</span><b>' + r.qtd + '</b><em>' + fmt1(r.pct) + '%</em></div>').join('');
  }

  /** Produtividade e contratos da semana ou do mês (o que muda quando o líder troca o período). */
  function blocoPeriodo(pd) {
    const pr = pd.produtividade, ct = pd.contratos;
    const cap = '<p class="cd-cap"><b>' + (pd.periodo === 'mes' ? 'Mês' : 'Semana') + '</b> · ' + LD.dm(pd.inicio) + ' a ' + LD.dm(pd.fim) + '</p>';
    const produtividade = !pr ? '<div class="ld-empty">Sem dados de produtividade neste período.</div>'
      : '<div class="mp-ct-kpis">' + kcard('Ligações', pr.ligacoes) + kcard('Follow-ups', pr.followups) + kcard('Leads quentes', pr.leads_quentes)
        + kcard('Ações', pr.total, LD.delta(pr.total, pr.anterior) + '<span class="mp-k-p">antes: ' + pr.anterior + '</span>')
        + kcard('Conversão', pr.conversao === null ? '—' : LD.num(pr.conversao) + '%', '<span class="mp-k-p">' + ct.total + ' ÷ ' + pr.leads + ' lead' + s(pr.leads) + '</span>')
        + kcard('Leads únicos', pr.leads) + '</div>'
        + (pr.tem_conta ? '' : '<div class="ld-note">Este closer ainda não tem acesso ativo ao portal: ligações, follow-ups e leads só contam quando ele registra pelo portal.</div>');
    const melhor = ct.melhor_dia ? ct.melhor_dia.qtd + '<span>' + LD.dm(ct.melhor_dia.data) + '</span>' : '—';
    const contratos = '<div class="mp-ct-kpis">' + kcard('Contratos', ct.total, LD.delta(ct.total, ct.anterior) + '<span class="mp-k-p">antes: ' + ct.anterior + '</span>')
      + kcard('Média/dia', fmt1(ct.media_dia) + '<span>/dia</span>') + kcard('Melhor dia', melhor) + '</div>' + beneficios(ct);
    return cap + '<section class="cd-bloco" aria-label="Produtividade"><h4>Produtividade</h4>' + produtividade + '</section>'
      + '<section class="cd-bloco" aria-label="Contratos"><h4>Contratos</h4>' + contratos + '</section>';
  }

  async function buscarCloser(id, periodo) {
    const r = await LP.api('lider_closer', { closer_id: id, periodo });
    if (!r || !r.success) { LD.toast((r && r.message) || 'Não foi possível carregar o histórico.', 'err'); return null; }
    return r;
  }

  async function abrirCloser(id) {
    const r = await buscarCloser(id, 'semana'); if (!r) return;
    const c = r.closer, m = c.metricas;
    const tl = r.linha_do_tempo.map(i => '<li class="t-' + i.tipo + '"><time datetime="' + E(i.quando) + '">' + LD.dm(i.quando) + '<br>' + String(i.quando).slice(11, 16) + '</time>'
      + '<b><span class="ld-det-t">' + (TIPOS[i.tipo] || E(i.tipo)) + '</span>' + E(i.titulo) + (i.tipo === 'revisao' && i.extra ? ' · ' + E(LD.fmtFone(i.extra)) : '') + '</b>' + (i.texto ? '<p>' + E(i.texto) + '</p>' : '') + '</li>').join('');
    const d = LD.drawer({
      titulo: c.nome,
      corpo: '<h3 class="lp-dia-h" style="margin-top:0">Visão geral</h3>'
        + '<div class="lp-resumo"><span class="lp-st ' + c.status + '">' + E(c.status_rotulo) + '</span><span class="lp-chip">Semana <b>' + c.vendas + '/' + c.meta + '</b></span><span class="lp-chip">Mês <b>' + c.vendas_mes + '/' + c.meta_mes + '</b></span></div>'
        + '<div class="lp-resumo"><span class="lp-chip">Feedbacks <b>' + m.feedbacks + '</b></span><span class="lp-chip">Cobranças <b>' + m.cobrancas + '</b></span><span class="lp-chip">Revisões <b>' + m.revisoes + '</b></span><span class="lp-chip">Reuniões <b>' + m.reunioes + '</b></span><span class="lp-chip">Treinamentos <b>' + m.treinamentos + '</b></span></div>'
        + '<p class="cd-cap">Feedbacks, cobranças, revisões, reuniões e treinamentos: últimos 30 dias.</p>'
        + '<div class="cd-per"><h3>Período</h3><div class="ld-seg" id="cdSeg" role="tablist" aria-label="Período da produtividade e dos contratos">'
        + '<button type="button" role="tab" data-per="semana" aria-selected="true" class="active">Semana</button><button type="button" role="tab" data-per="mes" aria-selected="false">Mês</button></div></div>'
        + '<div id="cdPeriodo">' + blocoPeriodo(r.periodo) + '</div>'
        + '<h3 class="lp-dia-h">Evolução (30 dias)</h3>' + grafico(r.evolucao)
        + '<h3 class="lp-dia-h">Linha do tempo</h3>' + (tl ? '<ul class="lp-tl">' + tl + '</ul>' : '<div class="ld-empty"><strong>Nenhuma interação ainda</strong>Feedbacks, cobranças, revisões, treinamentos e reuniões deste closer aparecem aqui.</div>'),
    });
    let seq = 0;
    LD.abas(d.dlg.querySelector('#cdSeg'), 'per', async per => {
      const meu = ++seq, box = d.dlg.querySelector('#cdPeriodo');
      box.setAttribute('aria-busy', 'true'); box.style.opacity = '.5';
      const x = await buscarCloser(id, per);
      if (meu !== seq) return;
      box.removeAttribute('aria-busy'); box.style.opacity = '';
      if (x) box.innerHTML = blocoPeriodo(x.periodo);
    });
  }

  $('eqLista').addEventListener('click', e => {
    const b = e.target.closest('[data-op]'); if (!b) return;
    const id = Number(b.closest('[data-id]').dataset.id);
    if (b.dataset.op === 'feedback') LP.reg('feedbacks').novo({ closer_id: id });
    else if (b.dataset.op === 'historico') abrirCloser(id);
  });

  LP.mod.equipe = {
    mostrar() { if (!estado.carregou || LP.sujo.delete('equipe')) carregar(); },
    recarregar() { carregar(); },
    abrirCloser,
  };
})();
