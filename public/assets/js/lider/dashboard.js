/*
 * Portal do Líder, aba Dashboard: Performance da equipe (cada closer com a barra de contratos da semana e o status), Alertas proativos, Ações
 * pendentes, Comparação com o Comercial (só agregados: a média e a posição, nunca outra equipe pelo nome) e a Produtividade da equipe: só o
 * ranking da equipe por ações (ligações + follow-ups + leads quentes), com o filtro de período. Os dados vêm de LP.carregarDash()
 * (lider_dashboard) e lider_visao.
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP) return;
  const E = LD.esc, $ = id => document.getElementById(id);
  const s = n => (n === 1 ? '' : 's');
  const prod = { per: 'hoje', seq: 0, carregou: false, dados: null };
  let todosAlertas = false, todasPend = false;

  // ── Performance da equipe ─────────────────────────────────────────────────────────────────────
  function dica(c) {
    if (c.status === 'bateu') return c.bateu_em_dias ? 'Bateu a meta em ' + c.bateu_em_dias + ' dia' + s(c.bateu_em_dias) + '!' : 'Bateu a meta da semana!';
    if (c.status === 'sem_meta') return 'Sem meta cadastrada nesta semana.';
    const d = c.dias_sem_venda;
    if (d === null) return c.vendas === 0 ? 'Nenhuma venda registrada ainda.' : '';
    if (c.vendas === 0) return '0 contratos há ' + d + ' dia' + s(d);
    return d >= 1 ? 'Última venda há ' + d + ' dia' + s(d) : 'Vendeu hoje.';
  }
  function linha(c) {
    const pct = c.meta > 0 ? Math.min(100, Math.round(c.vendas / c.meta * 100)) : 0;
    return '<li class="lp-perf">' + LD.avatar(c.nome, c.cor)
      + '<div class="lp-perf-n"><b>' + E(c.nome) + '</b>' + (c.lider ? '<span class="lp-voce">você</span>' : '') + '<span class="lp-st ' + c.status + '">' + E(c.status_rotulo) + '</span></div>'
      + '<div class="lp-perf-x">' + c.vendas + '/' + c.meta + '<small>contratos</small></div>'
      + '<div class="lp-perf-b"><div class="lp-bar ' + c.status + '" role="progressbar" aria-valuemin="0" aria-valuemax="' + c.meta + '" aria-valuenow="' + c.vendas + '" aria-label="' + E(c.nome) + ': ' + c.vendas + ' de ' + c.meta + ' contratos"><i style="--p:' + pct + '%"></i></div></div>'
      + (dica(c) ? '<div class="lp-perf-dica">' + E(dica(c)) + '</div>' : '') + '</li>';
  }
  function perf(d) {
    const e = d.equipe, w = d.semana;
    $('dPerfSub').innerHTML = (w.numero ? 'Semana ' + w.numero + ' · ' : '') + (w.inicio ? '<b>' + LD.dm(w.inicio) + ' a ' + LD.dm(w.fim) + '</b>' : '') + ' · dia ' + w.dia_atual + ' de ' + w.dias;
    const pos = (n, tot) => (n ? n + 'º de ' + tot : '—');
    $('dPerf').innerHTML = '<div class="lp-resumo">'
      + '<span class="lp-chip">Pontuação da semana <b>' + e.score + '%</b></span><span class="lp-chip">Pontuação do mês <b>' + e.score_mes + '%</b></span>'
      + '<span class="lp-chip">Ranking na empresa <b>' + pos(e.posicao_semana, e.total_equipes) + '</b> na semana</span><span class="lp-chip"><b>' + pos(e.posicao_mes, e.total_equipes) + '</b> no mês</span></div>'
      + (d.closers.length ? '<ul class="lp-lista">' + d.closers.map(linha).join('') + '</ul>' : '<div class="ld-empty"><strong>Nenhum closer na equipe</strong>Cadastre closers em Time › Equipes, no admin.</div>');
  }

  // ── Alertas proativos e ações pendentes ───────────────────────────────────────────────────────
  function lista(el, itens, tipo, mostrarTodos, alternar, vazioT, vazioD) {
    if (!itens.length) { el.innerHTML = '<div class="ld-empty"><strong>' + vazioT + '</strong>' + vazioD + '</div>'; return; }
    const vis = mostrarTodos ? itens : itens.slice(0, 5);
    el.innerHTML = '<ul class="lp-lista">' + vis.map(i => LP.itemHtml(i, tipo)).join('') + '</ul>'
      + (itens.length > 5 ? '<button type="button" class="ld-btn sm lp-mais" data-alternar="' + alternar + '">' + (mostrarTodos ? 'Mostrar menos' : 'Ver todos (' + itens.length + ')') + '</button>' : '');
  }
  function alertas(d) {
    const at = d.alertas.filter(a => a.severidade === 'atencao').length, po = d.alertas.length - at;
    $('dAlSub').innerHTML = at || po ? '<b>' + at + '</b> atenção · <b>' + po + '</b> positivo' + s(po) : '';
    lista($('dAlertas'), d.alertas, 'alerta', todosAlertas, 'al', 'Nenhum alerta agora', 'Quando um closer ficar parado ou bater a meta, aparece aqui.');
  }
  function pendencias(d) {
    $('dPenSub').innerHTML = d.pendencias.length ? '<b>' + d.pendencias.length + '</b> pendente' + s(d.pendencias.length) : '';
    lista($('dPend'), d.pendencias, 'pend', todasPend, 'pen', 'Tudo em dia', 'Feedbacks, reuniões e treinamentos sem pendência.');
  }

  // ── Comparação com o Comercial ────────────────────────────────────────────────────────────────
  function comparacao(d) {
    const c = d.comparacao, e = d.equipe;
    $('dCmpSub').textContent = c.equipes + ' equipe' + s(c.equipes) + ' no Comercial';
    if (!c.equipes) { $('dComp').innerHTML = '<div class="ld-empty">Ainda não há equipes com closers para comparar.</div>'; return; }
    const fmt = v => LD.num(Math.round(v * 10) / 10);
    $('dComp').innerHTML = '<div class="lp-cmp">' + c.metricas.map(m => {
      const max = Math.max(m.k === 'contratos_closer' ? 1 : 100, m.equipe, m.media), p = v => Math.min(100, Math.round(v / max * 100));
      return '<div class="lp-cmp-r"><span>' + E(m.rotulo) + '</span>'
        + '<div class="lp-cmp-b eq"><span>Sua equipe</span><div class="lp-bar" role="img" aria-label="Sua equipe: ' + fmt(m.equipe) + '"><i style="--p:' + p(m.equipe) + '%"></i></div><b>' + fmt(m.equipe) + '</b></div>'
        + '<div class="lp-cmp-b md"><span>Média</span><div class="lp-bar" role="img" aria-label="Média do Comercial: ' + fmt(m.media) + '"><i style="--p:' + p(m.media) + '%"></i></div><b>' + fmt(m.media) + '</b></div></div>';
    }).join('') + '<div class="lp-pos"><span class="lp-chip">Semana <b>' + (e.posicao_semana ? e.posicao_semana + 'º de ' + e.total_equipes : '—') + '</b></span><span class="lp-chip">Mês <b>' + (e.posicao_mes ? e.posicao_mes + 'º de ' + e.total_equipes : '—') + '</b></span></div></div>';
  }

  // ── Produtividade da equipe: só o ranking por ações, com o filtro de período ─────────────────────
  function ranking(p) {
    const voce = new Set(((LP.dash && LP.dash.closers) || []).filter(c => c.lider).map(c => c.id));
    const linhas = p.ranking.slice().sort((a, b) => (b.total - a.total) || String(a.nome).localeCompare(String(b.nome), 'pt-BR'));
    if (!linhas.length) return '<div class="ld-empty"><strong>Nenhum closer na equipe</strong>Cadastre closers em Time › Equipes, no admin.</div>';
    const max = Math.max(1, ...linhas.map(r => r.total));
    return '<ol class="lp-rk">' + linhas.map((r, i) => '<li>'
      + '<span class="lp-rk-p" aria-label="Posição ' + (i + 1) + '">' + (i + 1) + 'º</span>' + LD.avatar(r.nome, r.cor)
      + '<div class="lp-rk-n"><b>' + E(r.nome) + '</b>' + (voce.has(r.id) ? '<span class="lp-voce">você</span>' : '') + (r.ferias ? '<span class="lp-st sem_meta">férias</span>' : '')
      + '<small>Ligações <b>' + r.ligacoes + '</b> · Follow-ups <b>' + r.followups + '</b> · Leads quentes <b>' + r.leads_quentes + '</b></small></div>'
      + '<div class="lp-rk-t">' + r.total + '<small>' + (r.total === 1 ? 'ação' : 'ações') + '</small></div>'
      + '<div class="lp-rk-b"><div class="lp-bar" role="img" aria-label="' + E(r.nome) + ': ' + r.total + ' ações"><i style="--p:' + Math.round(r.total / max * 100) + '%"></i></div></div></li>').join('') + '</ol>';
  }
  async function carregarProd() {
    const meu = ++prod.seq, box = $('dProd');
    if (!prod.carregou) box.innerHTML = LD.skeleton();
    const r = await LP.api('lider_visao', { periodo: prod.per });
    if (meu !== prod.seq) return;
    if (!r || !r.success) { box.innerHTML = LD.erroTela((r && r.message) || '', 'dProdRetry'); const b = $('dProdRetry'); if (b) b.onclick = carregarProd; return; }
    prod.carregou = true; prod.dados = r.produtividade;
    pintarProd();
  }
  /** Desenha o ranking. Roda de novo quando o Dashboard chega (é ele que diz qual closer é o líder, para o "você"). */
  function pintarProd() {
    const p = prod.dados; if (!p) return;
    const nota = p.range.vazio ? 'Este período ainda não começou — não há ações para mostrar.'
      : !p.com_conta ? '<b>Nenhum closer do time com acesso ativo ao portal ainda.</b> As ações só contam quando o closer usa o portal.' : '';
    $('dProd').innerHTML = (nota ? '<div class="ld-note">' + nota + '</div>' : '') + ranking(p);
  }

  // ── montagem ──────────────────────────────────────────────────────────────────────────────────
  function erro(r) {
    const msg = (r && r.message) || 'Tente de novo em instantes.';
    ['dPerf', 'dAlertas', 'dPend', 'dComp'].forEach((id, i) => { $(id).innerHTML = i === 0 ? LD.erroTela(msg, 'dRetry') : '<div class="ld-empty">—</div>'; });
    const b = $('dRetry'); if (b) b.onclick = () => LP.carregarDash(true);
  }
  function render(d, r) {
    if (!d) return erro(r);
    perf(d); alertas(d); pendencias(d); comparacao(d); pintarProd();
  }
  LP.aoDash(render);
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-alternar]'); if (!b || !LP.dash) return;
    if (b.dataset.alternar === 'al') { todosAlertas = !todosAlertas; alertas(LP.dash); } else { todasPend = !todasPend; pendencias(LP.dash); }
  });
  LD.abas($('dProdSeg'), 'per', per => { prod.per = per; carregarProd(); });

  LP.mod.dashboard = {
    mostrar() { if (!prod.carregou) carregarProd(); if (LP.sujo.delete('dashboard') || !LP.dash) LP.carregarDash(true); },
    recarregarProd() { if (prod.carregou) carregarProd(); },
  };
  ['dPerf', 'dAlertas', 'dPend', 'dComp'].forEach(id => { $(id).innerHTML = LD.skeleton(); });
})();
