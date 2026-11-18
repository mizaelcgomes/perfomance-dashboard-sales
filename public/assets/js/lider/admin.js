/*
 * Admin, aba Produtividade: as sub-abas Closers | Líderes | Atividades dos líderes.
 *  - Closers é a tela de sempre (o admin.php a controla; aqui só se mostra e se esconde);
 *  - Líderes: um cartão por equipe, com o líder, o funil do período e as pontuações (fase L2);
 *  - Atividades dos líderes: o que cada líder registrou no período e quando foi a última vez, com o detalhe num painel lateral (fase L3).
 * O admin.php chama prLidCarregar() sempre que a aba Produtividade recarrega: o período (Hoje / Semana / Mês / Personalizado) e a equipe
 * são os do filtro da própria aba (objeto PR). Só a sub-aba aberta carrega dados. Tudo é só leitura.
 */
(function () {
  'use strict';
  const LD = window.LD;
  const barra = document.getElementById('prSubTabs');
  if (!LD || !barra || typeof PR === 'undefined') return;

  const E = LD.esc;
  const filtros = document.querySelector('#tabProdutividade .pr-filters');
  const painel = { closers: document.getElementById('prPanelClosers'), lideres: document.getElementById('prPanelLideres'), ativ: document.getElementById('prPanelAtiv') };
  let sub = 'closers', seqL = 0, seqA = 0;
  const ler = (acao, extra) => {
    const qs = new URLSearchParams(Object.assign({ action: acao }, extra || {}));
    return fetch(API_URL+'?' + qs).then(r => r.json());
  };
  const periodo = () => {
    const q = { periodo: PR.per };
    if (PR.per === 'custom') { q.from = PR.from; q.to = PR.to; }
    if (PR.eq) q.equipe_id = PR.eq;
    return q;
  };

  // ── Líderes (cartões) ─────────────────────────────────────────────────────────────────────────
  function cartao(t) {
    const k = t.kpis, semLider = t.lider_nome === null;
    const lider = semLider
      ? '<b>Sem líder definido</b> · defina em Time › Equipes'
      : 'Líder: <b>' + E(t.lider_nome) + '</b>' + (t.lider_acesso === false ? ' · <span class="ld-chip" title="O líder só vê a área do time no portal se tiver conta de closer ativa">sem acesso ao portal</span>' : '');
    const mini = (rot, v) => '<div><small>' + rot + '</small><b>' + v + '</b></div>';
    const melhores = t.melhores.filter(m => m.contratos > 0);
    const p = t.painel;
    return '<article class="ld-team' + (semLider ? ' sem-lider' : '') + '" style="--tc:' + LD.cor(t.equipe_cor) + '" data-equipe="' + t.equipe_id + '">'
      + '<div class="ld-team-h">' + LD.avatar(t.lider_nome || '', t.equipe_cor) + '<div style="min-width:0"><h3 title="' + E(t.equipe_nome) + '">' + E(t.equipe_nome) + '</h3>'
      + '<p>' + lider + ' · ' + t.closers + ' closer' + (t.closers === 1 ? '' : 's') + '</p></div></div>'
      + '<div class="ld-mini">' + mini('Ligações', k.ligacoes) + mini('Follow-ups', k.followups) + mini('Leads q.', k.leads_quentes) + mini('Contratos', k.contratos) + '</div>'
      + '<div class="ld-best">Contratos: ' + LD.delta(k.contratos, t.prev.contratos) + ' <span>antes: ' + t.prev.contratos + '</span> · Conversão: <b>' + (k.conversao === null ? '—' : LD.num(k.conversao) + '%') + '</b>'
      + (t.participantes < t.closers ? '<br><span>' + (t.participantes === 0 ? 'Nenhum closer com acesso ao portal: ligações e follow-ups não contam.' : t.participantes + ' de ' + t.closers + ' closers usam o portal.') + '</span>' : '') + '</div>'
      + (p ? '<div class="ld-meta">' + LD.meter('Semana', p.vendas, p.meta, p.score) + LD.meter('Mês', p.vendas_mes, p.meta_mes, p.score_mes) + '</div>' : '')
      + '<div class="ld-best">' + (melhores.length ? 'Mais contratos: ' + melhores.map((m, i) => (i + 1) + 'º <b>' + E(m.nome) + '</b> (' + m.contratos + ')').join(' · ') : 'Nenhum contrato no período.') + '</div>'
      + '</article>';
  }
  async function carregarLideres() {
    const meu = ++seqL, lista = document.getElementById('prLidList'), subt = document.getElementById('prLidSub');
    if (!lista) return;
    lista.setAttribute('aria-busy', 'true');
    if (!lista.querySelector('.ld-team')) lista.innerHTML = '<div style="grid-column:1/-1">' + LD.skeleton() + '</div>';
    try {
      const d = await ler('get_produtividade_lideres', { periodo: PR.per, from: PR.per === 'custom' ? PR.from : '', to: PR.per === 'custom' ? PR.to : '' });
      if (meu !== seqL) return;
      if (!d.success) throw new Error(d.message || 'Erro');
      let eq = d.equipes || [];
      const todas = eq.length, comLider = eq.filter(t => t.lider_nome !== null).length;
      if (PR.eq) eq = eq.filter(t => String(t.equipe_id) === String(PR.eq));
      if (subt) subt.textContent = PR.eq ? 'equipe selecionada no filtro' : todas + ' equipe' + (todas === 1 ? '' : 's') + ' · ' + comLider + ' com líder';
      lista.innerHTML = eq.length ? eq.map(cartao).join('') : '<div class="ld-empty" style="grid-column:1/-1">Nenhuma equipe cadastrada.</div>';
    } catch (e) {
      if (meu !== seqL) return;
      lista.innerHTML = '<div style="grid-column:1/-1">' + LD.erroTela('', 'prLidRetry') + '</div>';
      const b = document.getElementById('prLidRetry'); if (b) b.onclick = carregarLideres;
    } finally {
      if (meu === seqL) lista.removeAttribute('aria-busy');
    }
  }

  // ── Atividades dos líderes (tabela + painel de detalhe) ────────────────────────────────────────
  const TIPOS = { reuniao: 'Reunião', feedback: 'Feedback', cobranca: 'Cobrança', revisao: 'Revisão', treinamento: 'Treinamento' };
  const STATUS = { agendada: 'agendada', realizada: 'realizada', cancelada: 'cancelada' };
  function linhaAtiv(l, i) {
    const num = (v, extra) => '<b>' + v + '</b>' + (extra ? '<span class="multi">' + extra + '</span>' : '');
    const quem = LD.quem(l.lider_nome || 'Sem líder', l.equipe_cor, '<span class="ld-sub">' + E(l.equipe_nome) + (l.lider_nome !== null && l.lider_acesso === false ? ' · <span class="ld-chip" title="Sem conta ativa no portal">sem acesso ao portal</span>' : '') + '</span>');
    const p = l.proprios;
    const ult = l.ultima_atividade ? LD.dhBR(l.ultima_atividade) + '<span class="multi">' + (l.dias_sem_atividade === 0 ? 'hoje' : 'há ' + l.dias_sem_atividade + ' dia' + (l.dias_sem_atividade === 1 ? '' : 's')) + '</span>' : '—';
    const alerta = l.alerta ? '<span class="ld-chip' + (l.alerta.k === 'nunca' ? '' : ' warn') + '">' + E(l.alerta.t) + '</span>' : '—';
    const rotulo = l.lider_nome ? 'Ver os últimos registros de ' + l.lider_nome : 'Equipe ' + l.equipe_nome + ' sem líder';
    return '<tr data-abrir data-equipe="' + l.equipe_id + '" tabindex="0" role="button" aria-label="' + E(rotulo) + '"><td class="pos">' + (i + 1) + '</td>'
      + '<td class="l" data-label="">' + quem + '</td>'
      + '<td class="num" data-label="Total"><b>' + l.total + '</b></td>'
      + '<td class="num" data-label="Reuniões">' + num(l.reunioes.marcadas, l.reunioes.realizadas + ' realizada' + (l.reunioes.realizadas === 1 ? '' : 's') + ' · ' + l.reunioes.canceladas + ' cancelada' + (l.reunioes.canceladas === 1 ? '' : 's')) + '</td>'
      + '<td class="num" data-label="Feedbacks"><b>' + l.feedbacks + '</b></td>'
      + '<td class="num" data-label="Cobranças"><b>' + l.cobrancas + '</b></td>'
      + '<td class="num" data-label="Revisões"><b>' + l.revisoes + '</b></td>'
      + '<td class="num" data-label="Treinamentos">' + num(l.treinamentos.dados, l.treinamentos.presencas + ' presença' + (l.treinamentos.presencas === 1 ? '' : 's')) + '</td>'
      + '<td data-label="Como closer">' + (p ? p.ligacoes + ' lig · ' + p.followups + ' fu · ' + p.leads_quentes + ' lq · ' + p.contratos + ' ct' : '—') + '</td>'
      + '<td data-label="Última atividade">' + ult + '</td>'
      + '<td class="alerta" data-label="Alerta">' + alerta + '</td></tr>';
  }
  async function carregarAtividades() {
    const meu = ++seqA, area = document.getElementById('prAtivLista'), subt = document.getElementById('prAtivSub');
    if (!area) return;
    area.setAttribute('aria-busy', 'true');
    if (!area.querySelector('table')) area.innerHTML = LD.skeleton();
    try {
      const d = await ler('get_atividades_lideres', periodo());
      if (meu !== seqA) return;
      if (!d.success) { const er = new Error(d.message || 'Erro'); er.migracao = d.code === 'migracao'; throw er; }
      const comAlerta = d.equipes.filter(l => l.alerta).length;
      if (subt) subt.textContent = d.equipes.length + ' equipe' + (d.equipes.length === 1 ? '' : 's') + (comAlerta ? ' · ' + comAlerta + ' com alerta' : '') + ' · alerta após ' + d.limite_dias + ' dias sem atividade';
      area.innerHTML = d.equipes.length
        ? '<div class="ld-scroll"><table class="ld-tbl ld-resp ld-ativ-tbl"><thead><tr><th>#</th><th class="l">Líder</th><th>Total</th><th>Reuniões</th><th>Feedbacks</th><th>Cobranças</th><th>Revisões</th><th>Treinamentos</th><th>Como closer</th><th>Última atividade</th><th class="l">Alerta</th></tr></thead><tbody>'
          + d.equipes.map(linhaAtiv).join('') + '</tbody></table></div>'
        : '<div class="ld-empty">Nenhuma equipe cadastrada.</div>';
    } catch (e) {
      if (meu !== seqA) return;
      area.innerHTML = LD.erroTela(e && e.migracao ? e.message : '', 'prAtivRetry');       // "rode a migração", quando é isso
      const b = document.getElementById('prAtivRetry'); if (b) b.onclick = carregarAtividades;
    } finally {
      if (meu === seqA) area.removeAttribute('aria-busy');
    }
  }
  async function abrirDetalhe(equipeId) {
    const d = await ler('get_atividade_lider_detalhe', { equipe_id: equipeId }).catch(() => ({ success: false }));
    if (!d.success) { LD.toast('Não foi possível carregar os registros.', 'err'); return; }
    const itens = d.registros.map(r => '<li><time datetime="' + E(r.quando) + '">' + LD.dm(r.quando) + '<br>' + String(r.quando).slice(11, 16) + '</time><b><span class="ld-det-t">' + (TIPOS[r.tipo] || E(r.tipo)) + '</span>' + (r.closer_nome ? E(r.closer_nome) : '') + '</b>'
      + '<span>' + E(r.descricao) + (r.status ? ' · ' + (STATUS[r.status] || E(r.status)) : '') + '</span></li>').join('');
    LD.drawer({
      titulo: d.lider_nome ? d.lider_nome + ' · ' + d.equipe.nome : 'Equipe ' + d.equipe.nome,
      corpo: d.lider_nome
        ? (d.registros.length ? '<p class="ld-help" style="margin:0 0 12px">Os últimos ' + d.registros.length + ' registros criados pelo líder, do mais novo para o mais antigo. Só leitura.</p><ul class="ld-det-l">' + itens + '</ul>' : '<div class="ld-empty"><strong>Nada registrado ainda</strong>Este líder ainda não criou nenhum registro de liderança.</div>')
        : '<div class="ld-empty"><strong>Equipe sem líder</strong>Defina o líder em Time › Equipes.</div>',
    });
  }
  const area = document.getElementById('prAtivLista');
  if (area) {
    area.addEventListener('click', e => { const tr = e.target.closest('tr[data-abrir]'); if (tr) abrirDetalhe(tr.dataset.equipe); });
    area.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('tr[data-abrir]')) { e.preventDefault(); abrirDetalhe(e.target.dataset.equipe); } });
  }

  // ── sub-abas ──────────────────────────────────────────────────────────────────────────────────
  function aplicar(qual) {
    sub = qual;
    Object.keys(painel).forEach(k => { if (painel[k]) painel[k].hidden = k !== qual; });
    if (filtros) filtros.classList.toggle('ld-sem-closer', qual !== 'closers');     // o filtro de closer não vale para líderes
  }
  function mostrar(qual) {
    aplicar(qual);
    LD.salvar('prSub', qual);
    window.prLidCarregar();
  }
  window.prLidCarregar = function prLidCarregar() {
    if (sub === 'lideres') carregarLideres();
    else if (sub === 'ativ') carregarAtividades();
  };
  const selecionar = LD.abas(barra, 'sub', mostrar);
  const guardada = LD.ler('prSub');
  if (guardada && painel[guardada] && guardada !== 'closers') { selecionar(guardada); aplicar(guardada); }    // só mostra: os dados carregam quando a aba abre
  if (PR.seq > 0) window.prLidCarregar();     // a aba já tinha carregado antes deste arquivo chegar
})();
