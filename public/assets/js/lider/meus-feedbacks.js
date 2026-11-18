/*
 * Portal do closer: cartão "Meus feedbacks" — o que o líder registrou PARA o closer logado (feedbacks e cobranças de cadastramento).
 * Só leitura. Só aparece se já houver algo; quando há novidade o cartão abre sozinho, destaca o que é novo e, ao abrir, marca tudo como visto
 * (o aviso "novo" some na próxima visita, mas continua marcado nesta tela). O servidor identifica o closer pela sessão.
 * Usa do closer.php só a função api(action, dados).
 */
(function () {
  'use strict';
  const LD = window.LD;
  const sec = document.getElementById('mfSec');
  if (!LD || !sec || typeof api !== 'function') return;
  const E = LD.esc, $ = id => document.getElementById(id);
  let dados = null, aberto = false, marcou = false;

  function cartao(i) {
    const tipo = i.tipo === 'cobranca' ? 'Cobrança de cadastramento' : 'Feedback';
    return '<article class="ld-reg' + (i.novo ? ' novo' : '') + '"><div class="ld-reg-h"><h4>' + E(i.titulo) + '</h4>'
      + '<span>' + (i.novo ? '<span class="ld-st novo">' + (i.atualizado ? 'Atualizado' : 'Novo') + '</span>' : (i.atualizado ? '<span class="ld-chip mute">Atualizado</span>' : '')) + '</span></div>'
      + '<div class="ld-reg-m">' + (i.tipo === 'cobranca' ? '' : '<span class="ld-mf-tipo">' + tipo + '</span>') + '<span>de <b>' + E(i.lider_nome || 'seu líder') + '</b></span><span>' + LD.dataBR(i.data) + '</span></div>'
      + (i.texto ? '<p class="ld-reg-t">' + E(i.texto) + '</p>' : '') + '</article>';
  }

  function render() {
    $('mfLista').innerHTML = dados.itens.map(cartao).join('');
    const n = dados.novos, bd = $('mfBd');
    bd.hidden = !(n > 0 && !marcou);
    bd.textContent = n + (n === 1 ? ' novo' : ' novos');
    sec.classList.toggle('tem-novo', n > 0 && !marcou);
  }

  async function abrir(v) {
    aberto = v;
    $('mfBody').hidden = !v;
    const t = $('mfToggle'); t.textContent = v ? 'Recolher' : 'Abrir'; t.setAttribute('aria-expanded', v ? 'true' : 'false');
    if (v && dados && dados.novos > 0 && !marcou) {
      const r = await api('meus_feedbacks_vistos', {});
      if (r && r.success) { marcou = true; render(); }
    }
  }

  $('mfToggle').addEventListener('click', () => abrir(!aberto));
  (async function iniciar() {
    const r = await api('meus_feedbacks', {});
    if (!r || !r.success || !r.itens || !r.itens.length) return;      // nada recebido: o cartão continua escondido
    dados = r; sec.hidden = false; render();
    abrir(r.novos > 0);                                                // com novidade, abre sozinho
  })();
})();
