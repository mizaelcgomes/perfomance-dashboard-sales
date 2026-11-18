/*
 * Portal do Closer, página Ranking: a minha posição (Comercial e equipe, com a variação contra a semana ou o mês anterior), o ranking da minha equipe
 * (a minha linha destacada com "VOCÊ") e o do Comercial (os 10 primeiros e, se eu estiver fora deles, a minha posição fixa no fim). Em empate de pontos
 * mostra o critério de desempate do admin ("Desempatou por: 3× BPC/LOAS"). Os dados vêm de portal_ranking (o mesmo ranking da TV e do admin); o servidor
 * só devolve nomes e números, nunca ids de outros closers.
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP || !LP.closer) return;
  const E = LD.esc, $ = id => document.getElementById(id);
  const R = { per: LD.ler('rkPer') === 'mes' ? 'mes' : 'semana', seq: 0, carregou: false };

  function variacao(v, ant) {
    if (v === null || v === undefined) return '';
    if (v === 0) return '<span class="mp-trend flat">= mesma posição</span> <small>vs ' + ant + '</small>';
    return '<span class="mp-trend ' + (v > 0 ? 'up' : 'down') + '">' + (v > 0 ? '▲ ' : '▼ ') + Math.abs(v) + (Math.abs(v) === 1 ? ' posição' : ' posições') + '</span> <small>vs ' + ant + '</small>';
  }
  const faixa = pct => (pct >= 80 ? 'ok' : pct >= 40 ? 'mid' : 'bad');

  function linha(l) {
    const desempate = l.desempate
      ? '<small class="cp-desemp">' + (l.desempate_perdeu ? 'Empate em pontos · desempate: ' : 'Desempatou por: ') + E(l.desempate) + '</small>' : '';
    return '<li class="cp-rk-row' + (l.voce ? ' voce' : '') + '"' + (l.voce ? ' aria-current="true"' : '') + '><span class="cp-rk-n">' + LD.pos(l.pos) + '</span>'
      + '<span class="cp-rk-nm"><span class="cp-rk-n2"><b>' + E(l.nome) + '</b>' + (l.voce ? '<em class="cp-voce">VOCÊ</em>' : '') + '</span>' + desempate + '</span>'
      + '<span class="cp-rk-v">' + l.vendas + ' / ' + l.meta + '</span><span class="cp-pct ' + faixa(l.pct) + '">' + l.pct + '%</span></li>';
  }
  function desenhar(r) {
    const ant = R.per === 'mes' ? 'mês anterior' : 'semana anterior', m = r.minha;
    $('rkRotulo').textContent = r.rotulo;
    if (m.pos_comercial === null) {
      $('rkMinha').innerHTML = '<p class="cp-vazio">Você não aparece no ranking agora (em férias ou sem equipe).</p>';
    } else {
      $('rkMinha').innerHTML = '<div class="cp-rk"><div class="cp-rk-pos">#' + m.pos_comercial + '</div><div class="cp-rk-t"><b>de ' + m.total_comercial + ' no Comercial</b>'
        + (m.pos_equipe ? '<span>#' + m.pos_equipe + ' de ' + m.total_equipe + ' na equipe</span>' : '') + '</div>'
        + '<div class="cp-rk-m"><b>' + m.vendas + ' / ' + m.meta + '</b><span class="cp-pct ' + faixa(m.pct) + '">' + m.pct + '%</span></div></div>'
        + '<p class="cp-rk-var">' + variacao(m.variacao_comercial, ant) + (m.variacao_equipe !== null && m.variacao_equipe !== undefined && m.pos_equipe ? ' &nbsp; ' + variacao(m.variacao_equipe, ant).replace('vs ', 'na equipe vs ') : '') + '</p>';
    }
    $('rkEquipe').innerHTML = r.equipe.length ? '<ol class="cp-rk-lista">' + r.equipe.map(linha).join('') + '</ol>' : '<p class="cp-vazio">Sua equipe ainda não tem ranking neste período.</p>';
    const fora = r.comercial.length > 10 && r.comercial[r.comercial.length - 1].voce;
    $('rkCom').innerHTML = r.comercial.length ? '<ol class="cp-rk-lista">' + r.comercial.map((l, i) => (fora && i === r.comercial.length - 1 ? '<li class="cp-rk-gap" aria-hidden="true">⋯</li>' : '') + linha(l)).join('') + '</ol>' : '<p class="cp-vazio">Sem ranking neste período.</p>';
    $('rkComN').textContent = r.total_comercial + (r.total_comercial === 1 ? ' closer' : ' closers');
  }
  async function carregar() {
    const meu = ++R.seq;
    if (!R.carregou) { $('rkMinha').innerHTML = LD.skeleton(); }
    const r = await LP.api('portal_ranking', { periodo: R.per });
    if (meu !== R.seq) return;
    if (!r || !r.success) { $('rkMinha').innerHTML = LD.erroTela((r && r.message) || '', 'rkRetry'); const b = $('rkRetry'); if (b) b.onclick = carregar; return; }
    R.carregou = true; desenhar(r);
  }
  function sincSeg() { document.querySelectorAll('#rkSeg button').forEach(b => { const on = b.dataset.per === R.per; b.classList.toggle('active', on); b.setAttribute('aria-selected', String(on)); }); }
  $('rkSeg').addEventListener('click', e => { const b = e.target.closest('[data-per]'); if (!b || b.dataset.per === R.per) return; R.per = b.dataset.per; LD.salvar('rkPer', R.per); sincSeg(); carregar(); });
  $('rkComToggle').addEventListener('click', () => {
    const aberto = $('rkCom').hidden; $('rkCom').hidden = !aberto;
    $('rkComToggle').setAttribute('aria-expanded', String(aberto)); $('rkComToggle').textContent = aberto ? 'Recolher' : 'Abrir';
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && LP.aba === 'ranking') carregar(); });
  sincSeg();
  LP.mod.ranking = {
    mostrar(op) { if (op && (op.per === 'semana' || op.per === 'mes')) { R.per = op.per; LD.salvar('rkPer', R.per); sincSeg(); } carregar(); },
    recarregar: carregar,
    atual: () => R.per,
  };
})();
