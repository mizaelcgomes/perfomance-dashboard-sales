/*
 * Portal do Closer, página Minhas Interações: o que o líder registrou PARA mim (feedbacks, cobranças de cadastramento e revisões de atendimento, que posso
 * responder) e o que é da minha equipe (reuniões e treinamentos, só leitura). Cada sub-aba é uma linha do tempo, da mais recente para a mais antiga. Item
 * novo (ainda não visto) traz o selo "Novo" e o contador no menu; abrir o item o marca como lido na hora. As respostas ficam no próprio item (até 500
 * letras). Os dados vêm de portal_interacoes, portal_interacao_vista e portal_interacao_responder; o servidor só lê o que é do closer da sessão.
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP || !LP.closer) return;
  const E = LD.esc, $ = id => document.getElementById(id);
  const ABAS = [['feedback', 'Feedbacks'], ['reuniao', 'Reuniões'], ['cobranca', 'Cobranças'], ['revisao', 'Revisões'], ['treinamento', 'Treinamentos']];
  const VAZIO = { feedback: 'Nenhum feedback ainda.', reuniao: 'Nenhuma reunião da sua equipe ainda.', cobranca: 'Nenhuma cobrança ainda.', revisao: 'Nenhuma revisão de atendimento ainda.', treinamento: 'Nenhum treinamento da sua equipe ainda.' };
  const STATUS = { agendada: 'Agendada', realizada: 'Realizada', cancelada: 'Cancelada', agendado: 'Agendado', realizado: 'Realizado', cancelado: 'Cancelado' };
  const I = { tipo: 'feedback', seq: 0, dados: {} };

  function badgeAbas() {
    const c = LP.dash || {};
    ABAS.forEach(([k]) => { const b = document.querySelector('#inAbas [data-tipo="' + k + '"] .cp-tab-n'); if (!b) return; const n = c[k] || 0; b.hidden = n === 0; b.textContent = n; });
  }
  LP.aoDash(badgeAbas);

  function respostas(i) {
    return (i.respostas || []).map(r => '<div class="cp-resp"><small>Sua resposta · ' + LD.dhBR(r.criado_em) + '</small><p>' + E(r.texto) + '</p></div>').join('');
  }
  function formResposta(i, d) {
    if (!d.pode_responder) return '';
    return '<form class="cp-resp-f" data-id="' + i.id + '" novalidate><label class="ld-lbl" for="rp' + i.id + '">Responder (opcional)</label>'
      + '<textarea class="ld-in" id="rp' + i.id + '" name="texto" maxlength="' + d.maximo + '" rows="2" placeholder="Escreva sua resposta ao seu líder"></textarea>'
      + '<div class="cp-resp-b"><small class="ld-help" data-cont>0 / ' + d.maximo + '</small><button type="submit" class="ld-btn sm pri">Enviar resposta</button></div><div class="ld-err" role="alert" data-erro></div></form>';
  }
  function cartao(i, d) {
    const meta = [];
    if (i.lider_nome) meta.push('<span>' + (i.tipo === 'reuniao' || i.tipo === 'treinamento' ? 'por ' : 'de ') + '<b>' + E(i.lider_nome) + '</b></span>');
    meta.push('<span>' + (i.quando ? LD.dhBR(i.quando) : LD.dataBR(i.data)) + '</span>');
    if (i.cliente) meta.push('<span>Cliente ' + E(i.cliente) + '</span>');
    if (i.status) meta.push('<span class="ld-chip mute">' + E(STATUS[i.status] || i.status) + '</span>');
    if (i.tipo === 'treinamento' && i.status !== 'agendado' && i.status !== 'cancelado') meta.push('<span class="ld-chip ' + (i.presente ? '' : 'mute') + '">' + (i.presente ? 'Você esteve presente' : 'Sem presença registrada') + '</span>');
    const estado = i.novo ? '<span class="ld-st novo">' + (i.atualizado ? 'Atualizado' : '🆕 Novo') + '</span>' : (i.tipo === 'feedback' || i.tipo === 'cobranca' || i.tipo === 'revisao' ? '<span class="ld-chip mute">✓ Lido</span>' : '');
    return '<li class="cp-int' + (i.novo ? ' novo' : '') + '" data-id="' + i.id + '" data-novo="' + (i.novo ? '1' : '0') + '"><button type="button" class="cp-int-h" aria-expanded="false"><span class="cp-int-t">' + E(i.titulo) + '</span>' + estado + '</button>'
      + '<div class="cp-int-m">' + meta.join('') + '</div>'
      + '<div class="cp-int-c" hidden>' + (i.texto ? '<p class="cp-int-x">' + E(i.texto) + '</p>' : '') + '<div class="cp-resps">' + respostas(i) + '</div>' + formResposta(i, d) + '</div></li>';
  }
  function desenhar(d) {
    const box = $('inLista');
    if (d.migracao_pendente) { box.innerHTML = '<div class="ld-empty"><strong>Em preparação</strong>Esta área ainda está sendo ativada. Tente de novo mais tarde.</div>'; return; }
    if (!d.itens.length) { box.innerHTML = '<div class="ld-empty"><strong>' + E(VAZIO[I.tipo]) + '</strong>Seu líder registra aqui as conversas com você.</div>'; return; }
    box.innerHTML = '<ul class="cp-timeline">' + d.itens.map(i => cartao(i, d)).join('') + '</ul>';
  }
  async function carregar() {
    const meu = ++I.seq, tipo = I.tipo, box = $('inLista');
    box.innerHTML = LD.skeleton();
    const r = await LP.api('portal_interacoes', { tipo });
    if (meu !== I.seq) return;
    if (!r || !r.success) { box.innerHTML = LD.erroTela((r && r.message) || '', 'inRetry'); const b = $('inRetry'); if (b) b.onclick = carregar; return; }
    I.dados[tipo] = r; desenhar(r);
  }
  function sincAbas() { document.querySelectorAll('#inAbas [role="tab"]').forEach(b => { const on = b.dataset.tipo === I.tipo; b.classList.toggle('active', on); b.setAttribute('aria-selected', String(on)); b.tabIndex = on ? 0 : -1; }); }
  const sel = LD.abas($('inAbas'), 'tipo', t => { I.tipo = t; sincAbas(); carregar(); });

  // abrir um item: expande, e se for novo marca como lido (o selo e o contador do menu somem)
  async function abrir(li) {
    const h = li.querySelector('.cp-int-h'), c = li.querySelector('.cp-int-c'), abrindo = c.hidden;
    c.hidden = !abrindo; h.setAttribute('aria-expanded', String(abrindo));
    if (!abrindo || li.dataset.novo !== '1') return;
    li.dataset.novo = '0';
    const r = await LP.api('portal_interacao_vista', { tipo: I.tipo, id: +li.dataset.id });
    if (r && r.success) {
      li.classList.remove('novo'); const st = li.querySelector('.ld-st'); if (st) st.outerHTML = '<span class="ld-chip mute">✓ Lido</span>';
      LP.carregarDash(true);
    }
  }
  $('inLista').addEventListener('click', e => { const h = e.target.closest('.cp-int-h'); if (h) abrir(h.closest('.cp-int')); });
  $('inLista').addEventListener('input', e => { if (e.target.name !== 'texto') return; const f = e.target.form; f.querySelector('[data-cont]').textContent = e.target.value.length + ' / ' + e.target.maxLength; });
  $('inLista').addEventListener('submit', async e => {
    const f = e.target.closest('.cp-resp-f'); if (!f) return;
    e.preventDefault();
    const botao = f.querySelector('[type="submit"]'), erro = f.querySelector('[data-erro]'), texto = f.elements.texto.value.trim();
    erro.textContent = '';
    if (!texto) { erro.textContent = 'Escreva a resposta antes de enviar.'; f.elements.texto.focus(); return; }
    botao.disabled = true;
    const r = await LP.api('portal_interacao_responder', { tipo: I.tipo, id: +f.dataset.id, texto });
    botao.disabled = false;
    if (!r || !r.success) { erro.textContent = (r && r.message) || 'Não foi possível enviar.'; return; }
    f.closest('.cp-int-c').querySelector('.cp-resps').insertAdjacentHTML('beforeend', '<div class="cp-resp"><small>Sua resposta · ' + LD.dhBR(r.resposta.criado_em) + '</small><p>' + E(r.resposta.texto) + '</p></div>');
    f.elements.texto.value = ''; f.querySelector('[data-cont]').textContent = '0 / ' + f.elements.texto.maxLength;
    LD.toast(r.message || 'Resposta enviada.', 'ok');
    const li = f.closest('.cp-int'); if (li.classList.contains('novo')) { li.classList.remove('novo'); li.dataset.novo = '0'; const st = li.querySelector('.ld-st'); if (st) st.outerHTML = '<span class="ld-chip mute">✓ Lido</span>'; LP.carregarDash(true); }
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && LP.aba === 'interacoes') { LP.carregarDash(true); carregar(); } });

  LP.mod.interacoes = {
    mostrar(op) {
      if (op && op.tipo && ABAS.some(a => a[0] === op.tipo)) { I.tipo = op.tipo; sel(I.tipo); }
      sincAbas(); badgeAbas(); carregar();
    },
    recarregar: carregar,
    atual: () => I.tipo,
  };
})();
