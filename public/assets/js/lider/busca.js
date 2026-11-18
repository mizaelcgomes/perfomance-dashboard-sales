/*
 * Portal do Líder: a busca global (Ctrl+K ou o botão Buscar do topo). Uma caixa que acha, ao mesmo tempo, telas e ações do portal, closers da
 * equipe e atividades (reuniões, feedbacks, cobranças, revisões e treinamentos). Setas movem a seleção, Enter abre e Esc fecha. Só enxerga a
 * equipe do próprio líder: quem decide é o servidor (lider_busca), que também exige ao menos 2 letras.
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP) return;
  const E = LD.esc;
  const TELAS_LIDER = [
    { t: 'Dashboard', d: 'Performance, alertas e pendências', k: 'dashboard performance alertas pendencias comparacao', f: () => LP.ir('dashboard') },
    { t: 'Equipe', d: 'Closers da equipe', k: 'equipe closers time historico', f: () => LP.ir('equipe') },
    { t: 'Agenda', d: 'Reuniões, treinamentos, feedbacks, cobranças e revisões', k: 'agenda calendario atividades reunioes treinamentos feedbacks cobrancas revisoes eventos', f: () => LP.ir('agenda') },
    { t: 'Configurações', d: 'Preferências de alerta e atalhos', k: 'configuracoes preferencias alertas atalhos', f: () => LP.ir('config') },
    { t: 'Meu dia', d: 'Resumo do dia, últimas ações e evolução (portal do closer)', k: 'meu dia resumo hoje ultimas acoes evolucao closer historico', f: () => LP.ir('meu-dia') },
    { t: 'Registrar ação rápida', d: 'Ligação, follow-up ou lead quente pelo número do cliente', k: 'registrar acao rapida ligacao followup lead quente numero', f: () => LP.abrirQuick() },
    { t: 'Meus contratos', d: 'Contratos por semana ou mês e benefícios fechados', k: 'meus contratos semana mes beneficios fechados closer', f: () => LP.ir('contratos') },
    { t: 'Novo feedback', d: 'Registrar um feedback para um closer', k: 'novo feedback criar', f: () => LP.reg('feedbacks').novo() },
    { t: 'Nova reunião', d: 'Marcar uma reunião de equipe', k: 'nova reuniao marcar criar', f: () => LP.reg('reunioes').novo() },
    { t: 'Novo treinamento', d: 'Agendar um treinamento', k: 'novo treinamento agendar criar', f: () => LP.reg('treinamentos').novo() },
    { t: 'Nova cobrança', d: 'Cobrar um cadastramento fora do sistema', k: 'nova cobranca cadastramento criar', f: () => LP.reg('cobrancas').novo() },
    { t: 'Nova revisão de atendimento', d: 'Registrar uma revisão', k: 'nova revisao atendimento criar', f: () => LP.reg('revisoes').novo() },
  ];
  // Portal do Closer: as telas e ações dele (a busca de closers e atividades é só do líder)
  const TELAS_CLOSER = [
    { t: 'Meu dia', d: 'Meta, ranking, ações e interações de hoje', k: 'meu dia resumo hoje meta evolucao', f: () => LP.ir('meu-dia') },
    { t: 'Registrar ação rápida', d: 'Ligação, follow-up ou lead quente pelo número do cliente', k: 'registrar acao rapida ligacao followup lead quente numero', f: () => LP.abrirQuick() },
    { t: 'Minhas ações', d: 'Histórico de ligações, follow-ups e leads quentes', k: 'minhas acoes historico ligacoes followups leads', f: () => LP.ir('acoes') },
    { t: 'Meus contratos', d: 'Contratos por dia, semana ou mês e benefícios fechados', k: 'meus contratos semana mes hoje beneficios fechados meta', f: () => LP.ir('contratos') },
    { t: 'Ranking', d: 'Minha posição na equipe e no Comercial', k: 'ranking posicao equipe comercial desempate', f: () => LP.ir('ranking') },
    { t: 'Minhas interações', d: 'Feedbacks, reuniões, cobranças, revisões e treinamentos', k: 'minhas interacoes feedbacks reunioes cobrancas revisoes treinamentos lider', f: () => LP.ir('interacoes') },
    { t: 'Feedbacks do meu líder', d: 'Abrir a lista de feedbacks', k: 'feedbacks feedback lider', f: () => LP.ir('interacoes', { tipo: 'feedback' }) },
    { t: 'Treinamentos', d: 'Treinamentos da minha equipe', k: 'treinamentos treinamento equipe', f: () => LP.ir('interacoes', { tipo: 'treinamento' }) },
    { t: 'Configurações', d: 'Perfil, tema e atalhos', k: 'configuracoes perfil tema escuro claro atalhos', f: () => LP.ir('config') },
  ];
  const TELAS = LP.closer ? TELAS_CLOSER : TELAS_LIDER;
  const REGISTRO = { reuniao: ['reunioes', 'Reunião'], feedback: ['feedbacks', 'Feedback'], cobranca: ['cobrancas', 'Cobrança'], revisao: ['revisoes', 'Revisão'], treinamento: ['treinamentos', 'Treinamento'] };
  const norm = s => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  let aberta = null;

  function abrir() {
    if (aberta && aberta.open) { aberta.querySelector('input').focus(); return; }   // (uma busca recém-fechada ainda não limpou a referência: não vale como aberta)
    const dlg = document.createElement('dialog'); dlg.className = 'ld-modal lp-busca'; dlg.setAttribute('aria-label', 'Busca global');
    dlg.innerHTML = '<div class="lp-busca-i"><span aria-hidden="true" style="display:inline-flex;color:var(--gold2)"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg></span><input type="search" id="lpBq" placeholder="Buscar closer, registro ou tela…" autocomplete="off" maxlength="60" role="combobox" aria-expanded="true" aria-controls="lpBr" aria-label="Buscar"></div>'
      + '<div class="lp-busca-r" id="lpBr" role="listbox" aria-label="Resultados"></div>'
      + '<p class="ld-help" style="margin:0;padding:8px 18px 12px">↑ ↓ para escolher · Enter para abrir · Esc para fechar</p>';
    LD.host().appendChild(dlg);
    aberta = dlg;
    const campo = dlg.querySelector('input'), lista = dlg.querySelector('#lpBr');
    let itens = [], sel = 0, seq = 0, timer = 0;
    const fechar = () => { try { dlg.close(); } catch (e) { /* já fechado */ } };
    dlg.addEventListener('close', () => { if (aberta === dlg) aberta = null; dlg.remove(); });
    dlg.addEventListener('click', e => { if (e.target === dlg) fechar(); });

    function marcar() {
      lista.querySelectorAll('.lp-res').forEach((b, i) => { b.setAttribute('aria-selected', i === sel ? 'true' : 'false'); if (i === sel) { b.scrollIntoView({ block: 'nearest' }); campo.setAttribute('aria-activedescendant', b.id); } });
    }
    function desenhar(grupos) {
      itens = []; let h = '';
      grupos.forEach(g => {
        if (!g.itens.length) return;
        h += '<h3 class="lp-busca-g">' + E(g.nome) + '</h3>';
        g.itens.forEach(i => { itens.push(i); h += '<button type="button" class="lp-res" role="option" id="lpBi' + (itens.length - 1) + '" data-i="' + (itens.length - 1) + '" aria-selected="false"><span>' + E(i.t) + (i.d ? '<small>' + E(i.d) + '</small>' : '') + '</span></button>'; });
      });
      lista.innerHTML = h || '<div class="ld-empty"><strong>Nada encontrado</strong>Tente outro nome, número ou assunto.</div>';
      sel = 0; marcar();
    }
    const telas = q => { const n = norm(q); return TELAS.filter(t => !n || norm(t.t + ' ' + t.k).includes(n)).slice(0, 6).map(t => ({ t: t.t, d: t.d, f: t.f })); };
    desenhar([{ nome: 'Telas e ações', itens: telas('') }]);

    async function buscar() {
      const q = campo.value.trim(), meu = ++seq;
      if (q.length < 2) { desenhar([{ nome: 'Telas e ações', itens: telas(q) }]); return; }
      if (LP.closer) {                                    // closer: telas e as minhas próprias ações (por número ou observação)
        const a = await LP.api('acoes', { periodo: 'todos', q, limit: 6 });
        if (meu !== seq) return;
        const acoes = a && a.success ? a.acoes.map(x => ({ t: x.tipo_label + ' · ' + x.mascarado, d: LD.dm(x.data) + ' ' + x.hora + (x.observacao ? ' — ' + x.observacao : ''), f: () => LP.ir('acoes') })) : [];
        desenhar([{ nome: 'Minhas ações', itens: acoes }, { nome: 'Telas e ações', itens: telas(q) }]);
        return;
      }
      const r = await LP.api('lider_busca', { q });
      if (meu !== seq) return;
      if (!r || !r.success) { desenhar([{ nome: 'Telas e ações', itens: telas(q) }]); lista.insertAdjacentHTML('afterbegin', '<p class="ld-note err" role="alert" style="margin:8px">' + E((r && r.message) || 'Não foi possível buscar agora.') + '</p>'); return; }
      desenhar([
        { nome: 'Closers', itens: r.closers.map(c => ({ t: c.nome, d: 'Ver histórico', f: () => { LP.ir('equipe'); LP.mod.equipe.abrirCloser(c.id); } })) },
        { nome: 'Atividades', itens: r.registros.map(g => {
          const m = REGISTRO[g.tipo] || ['reunioes', g.tipo];
          return { t: g.titulo, d: m[1] + (g.closer_nome ? ' · ' + g.closer_nome : '') + (g.quando ? ' · ' + LD.dm(g.quando) : '') + (g.detalhe ? ' — ' + g.detalhe : ''), f: () => { LP.ir('agenda'); LP.reg(m[0]).porId(g.id, 'editar'); } };
        }) },
        { nome: 'Telas e ações', itens: telas(q) },
      ]);
    }
    campo.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(buscar, 220); });
    const abrirItem = i => { const it = itens[i]; if (!it) return; fechar(); setTimeout(it.f, 0); };
    lista.addEventListener('click', e => { const b = e.target.closest('.lp-res'); if (b) abrirItem(+b.dataset.i); });
    lista.addEventListener('mousemove', e => { const b = e.target.closest('.lp-res'); if (b && +b.dataset.i !== sel) { sel = +b.dataset.i; marcar(); } });
    campo.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (itens.length) { sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + itens.length) % itens.length; marcar(); } }
      else if (e.key === 'Enter') { e.preventDefault(); abrirItem(sel); }
      else if (e.key === 'Escape') { e.preventDefault(); fechar(); }       // num campo de busca o Esc só limparia o texto: aqui ele fecha a busca
    });
    dlg.showModal(); campo.focus();
  }

  LP.mod.busca = { abrir };
})();
