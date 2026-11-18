/*
 * Portal do Closer: a cola do que só existe na página do closer. O botão flutuante (FAB) leva ao Registro de Ação Rápida do Meu dia (no celular, a folha
 * que sobe), "Ver histórico completo" do Meu dia abre a página Minhas ações, e a página Minhas ações monta o histórico completo (acoes.js) na primeira vez.
 * É carregado depois de acoes.js e meu-portal.js (que também servem o portal do líder) e só faz algo no portal do closer.
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP || !LP.closer) return;
  const $ = id => document.getElementById(id);

  $('cpFab').addEventListener('click', () => LP.abrirQuick());
  $('qfFechar').addEventListener('click', () => LP.fecharFolha());
  document.addEventListener('click', e => { if (document.body.classList.contains('cp-sheet') && !e.target.closest('#lpQuick, #cpFab')) LP.fecharFolha(); });   // clicar fora da folha fecha
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && document.body.classList.contains('cp-sheet')) LP.fecharFolha(); });

  // o botão do Meu dia não abre mais um cartão: vai para a página do histórico (o clique é tratado aqui antes do tratador do meu-portal.js)
  $('mdHist').addEventListener('click', e => { e.stopImmediatePropagation(); LP.ir('acoes'); }, true);

  // a página Minhas ações: o histórico completo, com filtros, montado na primeira visita
  const base = LP.mod.acoes;
  let montada = false;
  LP.mod.acoes = Object.assign({}, base, {
    mostrar() {
      if (!montada) { montada = true; base.montar($('acHost')); }
      else base.mostrar();
    },
  });

  // ── submenu da barra lateral (Meus contratos, Ranking e Minhas interações): abre a página já na opção escolhida ──
  const estado = { contratos: () => LP.mod.contratos.atual(), ranking: () => LP.mod.ranking.atual(), interacoes: () => LP.mod.interacoes.atual() };
  function marcarSub() {
    document.querySelectorAll('.sb-sub-b').forEach(b => {
      const [aba, valor] = b.dataset.sub.split(':');
      b.setAttribute('aria-current', LP.aba === aba && estado[aba] && estado[aba]() === valor ? 'true' : 'false');
    });
  }
  const irOriginal = LP.ir;
  LP.ir = function (aba, op) { irOriginal(aba, op); marcarSub(); };
  document.querySelectorAll('.sb-sub-b').forEach(b => b.addEventListener('click', () => {
    const [aba, valor] = b.dataset.sub.split(':');
    LP.ir(aba, aba === 'interacoes' ? { tipo: valor } : { per: valor });
  }));
  // trocar a opção dentro da própria página também atualiza a marca do submenu
  document.addEventListener('click', e => { if (e.target.closest('#ctSeg, #rkSeg, #inAbas')) setTimeout(marcarSub, 50); });

  // o botão flutuante não fica por cima do registro quando ele já está na tela
  const quick = $('lpQuick');
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(es => { $('cpFab').classList.toggle('cp-fab-off', es.some(e => e.isIntersecting && LP.aba === 'meu-dia')); }, { threshold: 0.6 }).observe(quick);
  }
})();
