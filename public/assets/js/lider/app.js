/*
 * Portal do Líder (lider.php) e Portal do Closer (closer.php): o núcleo da página (window.LP). O mesmo arquivo serve os dois: BOOT.papel === 'closer'
 * troca as abas (Meu dia, Minhas ações, Meus contratos, Ranking, Minhas interações, Configurações), o sino (interações novas) e o botão de ação rápida
 * flutuante; o resto (menu, atalhos, chamada ao servidor) é igual. Abaixo, a descrição do portal do líder: Aqui ficam a chamada ao servidor (closer-api.php), a navegação entre as abas,
 * o menu hambúrguer, os atalhos de teclado (Ctrl+K busca, Ctrl+N ação rápida, que fica no topo do Meu dia), o sino de notificações e o
 * Dashboard em cache, que todas as telas leem. Cada tela (dashboard.js, equipe.js, agenda.js, acoes.js, config.js, busca.js, quick.js)
 * se registra em LP.mod. O servidor decide a equipe pela conta logada (nunca por este script) e confere a liderança a cada chamada: se
 * ela acabar com a página aberta, o portal leva o usuário ao portal do closer (que só existe para quem NÃO é mais líder).
 */
(function () {
  'use strict';
  const LD = window.LD;
  if (!LD || typeof BOOT === 'undefined') return;
  const E = LD.esc, $ = id => document.getElementById(id);
  const CLOSER = BOOT.papel === 'closer';

  const LP = (window.LP = { boot: BOOT, hoje: BOOT.hoje, mod: {}, dash: null, aba: CLOSER ? 'meu-dia' : 'dashboard', closer: CLOSER, regs: {}, sujo: new Set(), ouvintes: [] });

  // ── servidor ───────────────────────────────────────────────────────────────────────────────────
  /** Chama uma ação do closer-api.php (POST + CSRF do portal). Sempre devolve um objeto JSON (com success). */
  LP.api = async function api(action, dados) {
    try {
      const r = await fetch(API_CLOSER_URL, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || BOOT.csrf },
        body: JSON.stringify(Object.assign({ action }, dados || {})),
      });
      const j = await r.json().catch(() => null);
      if (!j) return { success: false, message: 'Resposta inesperada do servidor.' };
      if (j.code === 'auth') { location.href = '/closer/login'; return j; }
      if (j.code === 'csrf') { LD.toast('Sessão expirada — recarregando…', 'err'); setTimeout(() => location.reload(), 1200); return j; }
      if (!CLOSER && !j.success && /restrito a l/i.test(j.message || '')) { location.href = '/closer'; return j; }   // deixou de ser líder
      return j;
    } catch (e) { return { success: false, message: 'Sem conexão. Verifique sua internet.' }; }
  };

  // ── datas e cabeçalho ───────────────────────────────────────────────────────────────────────────
  const asDate = s => new Date(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
  LP.asDate = asDate;
  LP.addDias = (s, n) => { const d = asDate(s); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  function cabecalho() {
    const t = asDate(LP.hoje).toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    $('lpData').textContent = t.charAt(0).toUpperCase() + t.slice(1);
    // o cadastro costuma estar em MAIÚSCULAS: "ANA SOUZA" → "Ana Souza"
    $('lpNome').textContent = String(BOOT.nome).toLowerCase().replace(/(^|\s)(\S)/g, (m, a, b) => a + b.toUpperCase());
    avatar(null);
  }
  function avatar(url) {
    const el = $('lpAvatar'); el.style.setProperty('--ac', LD.cor(BOOT.cor)); el.style.setProperty('--at', LD.tinta(BOOT.cor));
    el.textContent = LD.iniciais(BOOT.nome);
    if (url) {
      const img = new Image(); img.alt = ''; img.src = url;
      img.onload = () => { el.textContent = ''; el.appendChild(img); };
    }
  }

  // ── navegação ───────────────────────────────────────────────────────────────────────────────────
  // cada aba: migalhas [grupo, tela], a etiqueta, o título e o subtítulo do cabeçalho de página (o mesmo padrão do admin)
  const ABAS_CLOSER = {
    'meu-dia': { migalhas: ['Meu dia', 'Resumo do dia'], eyebrow: 'Portal do closer', titulo: 'Meu dia', sub: 'Sua meta, sua posição e o que fazer agora.' },
    acoes: { migalhas: ['Minhas ações', 'Histórico'], eyebrow: 'Portal do closer', titulo: 'Minhas ações', sub: 'Ligações, follow-ups e leads quentes que você registrou.' },
    contratos: { migalhas: ['Meus contratos', 'Evolução'], eyebrow: 'Portal do closer', titulo: 'Meus contratos', sub: 'Seus contratos por dia, semana ou mês e os benefícios fechados.' },
    ranking: { migalhas: ['Ranking', 'Minha posição'], eyebrow: 'Portal do closer', titulo: 'Ranking', sub: 'Sua posição na equipe e no Comercial.' },
    interacoes: { migalhas: ['Minhas interações', 'Do meu líder'], eyebrow: 'Portal do closer', titulo: 'Minhas interações', sub: 'Feedbacks, reuniões, cobranças, revisões e treinamentos.' },
    config: { migalhas: ['Configurações', 'Perfil e tema'], eyebrow: 'Conta', titulo: 'Configurações', sub: 'Seu perfil, o tema e os atalhos do portal.' },
  };
  const ABAS_LIDER = {
    dashboard: { migalhas: ['Dashboard', 'Visão Geral'], eyebrow: 'Liderança', titulo: 'Painel da equipe', sub: 'Performance, alertas e pendências da sua equipe.' },
    equipe: { migalhas: ['Equipe', 'Closers'], eyebrow: 'Liderança', titulo: 'Closers da equipe', sub: 'Desempenho e acompanhamento de cada closer.' },
    agenda: { migalhas: ['Agenda', 'Calendário'], eyebrow: 'Liderança', titulo: 'Agenda da liderança', sub: 'Reuniões, treinamentos, feedbacks, cobranças e revisões: tudo o que você faz com a equipe.' },
    'meu-dia': { migalhas: ['Meu dia', 'Resumo do dia'], eyebrow: 'Meu portal (closer)', titulo: 'Meu dia', sub: 'O que você fez hoje como closer.' },
    contratos: { migalhas: ['Meus contratos', 'Evolução'], eyebrow: 'Meu portal (closer)', titulo: 'Meus contratos', sub: 'Seus contratos por semana ou por mês e os benefícios fechados.' },
    config: { migalhas: ['Configurações', 'Preferências'], eyebrow: 'Conta', titulo: 'Configurações', sub: 'Alertas, preferências e atalhos do portal.' },
  };
  const ABAS = CLOSER ? ABAS_CLOSER : ABAS_LIDER, PRIMEIRA = CLOSER ? 'meu-dia' : 'dashboard';
  LP.migalhas = () => {};      // não há mais migalhas: a página ativa fica marcada na barra lateral
  function cabecalhoDaAba(aba) {
    const d = ABAS[aba];
    $('lpTitulo').textContent = d.titulo; $('lpSubtitulo').textContent = d.sub; $('lpTbTitle').textContent = d.migalhas[0];
  }

  /** Vai para uma aba. */
  LP.ir = function ir(aba, opcoes) {
    if (!ABAS[aba]) aba = PRIMEIRA;
    LP.aba = aba;
    document.querySelectorAll('.lp-nav-b[data-tab]').forEach(b => { if (b.dataset.tab === aba) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current'); });
    document.querySelectorAll('.lp-pane').forEach(p => { p.hidden = p.dataset.pane !== aba; });
    cabecalhoDaAba(aba);
    document.querySelectorAll('.sb-item').forEach(i => i.classList.toggle('open', i.dataset.tab === aba));      // o submenu da página ativa fica aberto
    LP.migalhas(ABAS[aba].migalhas[0], ABAS[aba].migalhas[1]);
    const m = LP.mod[aba];
    if (m && m.mostrar) m.mostrar(opcoes || {});
    if (history.replaceState) history.replaceState(null, '', '#' + aba);
    fecharMenu();
    $('lpMain').scrollTop = 0; window.scrollTo(0, 0);
  };
  LP.sujar = (...abas) => abas.forEach(a => { if (a !== LP.aba) LP.sujo.add(a); });

  // ── atividades: o motor de registros (formulários e ações) de cada entidade, montado só na primeira vez ──────────────────────
  /** O controlador do motor de registros de uma entidade (reunioes, feedbacks, cobrancas, revisoes, treinamentos). Não há tela de lista própria:
   *  a Agenda mostra tudo e este motor cuida do formulário, da edição e das ações. A área da lista fica fora da página. */
  LP.reg = function reg(entidade) {
    if (!LP.regs[entidade]) {
      LP.regs[entidade] = LD.registros(document.createElement('div'), entidade, { api: LP.api, aoMudar: ent => LP.mudou(ent) });
    }
    return LP.regs[entidade];
  };

  // ── dados compartilhados: o Dashboard em cache ─────────────────────────────────────────────────
  LP.aoDash = fn => LP.ouvintes.push(fn);
  /** Carrega o Dashboard (uma chamada serve Dashboard, sino, Equipe e menu). Devolve o pacote ou null se falhou. */
  LP.carregarDash = async function (forcar) {
    if (CLOSER) return carregarContagens();
    if (LP.dash && !forcar) return LP.dash;
    if (LP._carga) return LP._carga;
    LP._carga = (async () => {
      const r = await LP.api('lider_dashboard', {});
      LP._carga = null;
      if (!r || !r.success) { LP.erroDash = r; LP.dash = null; LP.ouvintes.forEach(f => f(null, r)); return null; }
      LP.dash = r; LP.erroDash = null;
      if (r.lider && r.lider.imageUrl) avatar(r.lider.imageUrl);
      const n = r.contadores.total, b = document.querySelector('[data-badge="dashboard"]');
      b.hidden = n === 0; b.textContent = n;
      LP.ouvintes.forEach(f => f(r));
      return r;
    })();
    return LP._carga;
  };
  /** Uma ação rápida foi criada, editada ou excluída (o líder também é closer): atualiza o histórico, o Meu dia e a produtividade do Dashboard.
   *  resumo = os contadores do dia que o servidor devolve junto (evita uma chamada a mais). */
  LP.acoesMudaram = function (resumo) {
    if (LP.mod.acoes && LP.mod.acoes.recarregar) LP.mod.acoes.recarregar();
    if (LP.mod.meuportal && LP.mod.meuportal.aposAcao) LP.mod.meuportal.aposAcao(resumo);
    if (LP.mod.dashboard) LP.mod.dashboard.recarregarProd();
  };
  /** Algo foi gravado (registro, nota, preferência…): atualiza o Dashboard e o que está na tela; as outras abas recarregam ao abrir. */
  LP.mudou = function (origem) {
    LP.carregarDash(true);
    ['equipe', 'agenda'].forEach(a => { if (LP.aba === a && LP.mod[a] && LP.mod[a].recarregar) LP.mod[a].recarregar(); else LP.sujar(a); });
    if (LP.aba === 'dashboard' && LP.mod.dashboard) LP.mod.dashboard.recarregarProd();
  };

  /** Portal do closer: só os contadores de interações novas (a bolinha do menu e do sino). Devolve o pacote ou null. */
  async function carregarContagens() {
    const r = await LP.api('portal_interacoes_contagem', {});
    const d = r && r.success ? r : null;
    LP.dash = d;
    const b = document.querySelector('[data-badge="interacoes"]');
    if (b) { b.hidden = !d || d.total === 0; b.textContent = d ? d.total : 0; }
    LP.ouvintes.forEach(fn => fn(d));
    return d;
  }

  // ── ações dos alertas e das pendências (os botões) ─────────────────────────────────────────────
  LP.acao = async function (a) {
    if (!a) return;
    switch (a.k) {
      case 'feedback': LP.reg('feedbacks').novo({ closer_id: a.closer_id, assunto: a.assunto }); break;
      case 'agenda': LP.ir('agenda'); break;
      case 'reuniao_concluir': LP.reg('reunioes').porId(a.id, 'realizar'); break;
      case 'novo_treinamento': LP.reg('treinamentos').novo(); break;
      case 'presenca': LP.reg('treinamentos').porId(a.id, 'presenca'); break;
      default: break;
    }
  };
  LP.itemHtml = (a, tipo) => {
    const nivel = tipo === 'pend' ? 'pend' : a.severidade;
    return '<li class="lp-item ' + nivel + '"><div class="lp-item-t">' + (tipo === 'pend' ? '' : '<span class="lp-nivel">' + (a.severidade === 'positivo' ? 'Positivo' : 'Atenção') + '</span>') + E(a.titulo) + '</div>'
      + '<div class="lp-item-d">' + E(a.detalhe || '') + '</div>'
      + (a.acao ? '<div class="lp-item-a"><button type="button" class="ld-btn sm' + (a.severidade === 'positivo' ? '' : ' pri') + '" data-acao="' + E(JSON.stringify(a.acao)) + '">' + E(a.acao.rotulo) + '</button></div>' : '') + '</li>';
  };
  // um único ouvinte de clique para todos os botões de ação (Dashboard, sino e cartões da Equipe)
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-acao]'); if (!b) return;
    let a; try { a = JSON.parse(b.dataset.acao); } catch (x) { return; }
    fecharSino();
    LP.acao(a);
  });

  // ── menu hambúrguer e folha do registro rápido (celular) ───────────────────────────────────────
  function abrirMenu() { document.body.classList.add('lp-menu-open'); $('lpScrim').hidden = false; $('lpBurger').setAttribute('aria-expanded', 'true'); }
  function fecharMenu() { document.body.classList.remove('lp-menu-open'); $('lpScrim').hidden = true; $('lpBurger').setAttribute('aria-expanded', 'false'); }
  /** O Registro de Ação Rápida mora no topo do Meu dia: leva o líder até lá e põe o cursor no número. */
  LP.abrirQuick = function () {
    if (LP.aba !== 'meu-dia') LP.ir('meu-dia');
    if (CLOSER && window.matchMedia('(max-width:768px)').matches) { document.body.classList.add('cp-sheet'); }   // no celular o registro sobe como uma folha
    else $('lpQuick').scrollIntoView({ behavior: 'smooth', block: 'center' });
    $('qfNum').focus();
  };
  LP.fecharFolha = () => document.body.classList.remove('cp-sheet');
  $('lpBurger').addEventListener('click', () => (document.body.classList.contains('lp-menu-open') ? fecharMenu() : abrirMenu()));
  $('lpScrim').addEventListener('click', fecharMenu);

  document.querySelectorAll('.lp-nav-b[data-tab]').forEach(b => b.addEventListener('click', () => LP.ir(b.dataset.tab)));
  $('lpLogo').addEventListener('click', () => LP.ir(PRIMEIRA));
  $('lpMenuX').addEventListener('click', () => { fecharMenu(); $('lpBurger').focus(); });

  // ── barra lateral recolhida (64px, só no desktop; a escolha fica guardada neste navegador, como no admin) ──────────────────
  const CHAVE_RECOLHER = 'lpRecolhido';
  function recolher(sim, guardar) {
    $('lpShell').classList.toggle('collapsed', sim);
    const b = $('lpCollapse');
    b.setAttribute('aria-expanded', String(!sim));
    b.querySelector('.sb-lbl').textContent = sim ? 'Expandir menu' : 'Recolher menu';
    if (sim) b.setAttribute('title', 'Expandir menu'); else b.removeAttribute('title');
    if (guardar) LD.salvar(CHAVE_RECOLHER, sim ? '1' : '0');
  }
  LP.recolher = recolher;
  $('lpCollapse').addEventListener('click', () => recolher(!$('lpShell').classList.contains('collapsed'), true));
  recolher(LD.ler(CHAVE_RECOLHER) === '1' && !window.matchMedia('(max-width:960px)').matches, false);

  // o nome de cada item aparece ao lado, com a barra recolhida, quando o mouse passa (o CSS lê data-label)
  document.querySelectorAll('.lp-nav-b[data-tab]').forEach(n => { n.dataset.label = n.querySelector('.sb-lbl').textContent.trim(); });

  // ── perfil (topo): Meu perfil, Configurações e Sair ──
  function fecharPerfil(foco) { const m = $('lpPerfilMenu'), b = $('lpPerfilBtn'); if (m.hidden) return; m.hidden = true; b.setAttribute('aria-expanded', 'false'); if (foco) b.focus(); }
  $('lpPerfilBtn').addEventListener('click', e => {
    e.stopPropagation();
    if (!$('lpPerfilMenu').hidden) { fecharPerfil(true); return; }
    fecharSino(); $('lpPerfilMenu').hidden = false; $('lpPerfilBtn').setAttribute('aria-expanded', 'true'); $('lpPerfilMenu').querySelector('button').focus();
  });
  document.addEventListener('click', e => { if (!e.target.closest('.tb-prof-w')) fecharPerfil(); });
  $('lpMenuPerfil').addEventListener('click', () => { fecharPerfil(); LP.ir('config'); setTimeout(() => { const h = $('cfPfH'); if (h) h.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 450); });
  $('lpMenuConfig').addEventListener('click', () => { fecharPerfil(); LP.ir('config'); });

  $('lpSair').addEventListener('click', async () => { $('lpSair').disabled = true; const r = await LP.api('logout', {}); location.href = (r && r.redirect) || '/closer/login'; });

  // ── sino de notificações ───────────────────────────────────────────────────────────────────────
  function sino(d) {
    const n = d ? (CLOSER ? d.total : d.contadores.total) : 0, b = $('lpSinoN');
    b.hidden = n === 0; b.textContent = n > 99 ? '99+' : n;
    $('lpSino').setAttribute('aria-label', n ? 'Notificações: ' + n + ' pendente' + (n === 1 ? '' : 's') : 'Notificações: nada pendente');
    if (!$('lpSinoPop').hidden) montarSino();
  }
  function montarSino() {
    const d = LP.dash, pop = $('lpSinoPop');
    if (!d) { pop.innerHTML = '<div class="ld-empty">Não foi possível carregar as notificações.</div>'; return; }
    if (CLOSER) {
      const nomes = { feedback: ['feedback', 'feedbacks'], cobranca: ['cobrança', 'cobranças'], revisao: ['revisão', 'revisões'] };
      const linhas = Object.keys(nomes).filter(k => d[k] > 0).map(k => '<li class="lp-item pend"><div class="lp-item-t">' + d[k] + ' ' + nomes[k][d[k] === 1 ? 0 : 1] + (d[k] === 1 ? ' novo' : ' novos') + '</div></li>');
      pop.innerHTML = (linhas.length ? '<h3>Do seu líder</h3><ul class="lp-lista">' + linhas.join('') + '</ul>' : '<div class="ld-empty"><strong>Tudo em dia</strong>Nenhuma interação nova do seu líder.</div>')
        + '<p style="margin:14px 0 0;text-align:center"><button type="button" class="ld-btn sm" id="lpSinoVer">Abrir Minhas interações</button></p>';
      $('lpSinoVer').onclick = () => { fecharSino(); LP.ir('interacoes'); };
      return;
    }
    const atencao = d.alertas.filter(a => a.severidade === 'atencao'), positivos = d.alertas.filter(a => a.severidade === 'positivo');
    pop.innerHTML = (atencao.length ? '<h3>Alertas</h3><ul class="lp-lista">' + atencao.slice(0, 8).map(a => LP.itemHtml(a)).join('') + '</ul>' : '')
      + (d.pendencias.length ? '<h3 style="margin-top:14px">Ações pendentes</h3><ul class="lp-lista">' + d.pendencias.slice(0, 8).map(p => LP.itemHtml(p, 'pend')).join('') + '</ul>' : '')
      + (positivos.length ? '<h3 style="margin-top:14px">Boas notícias</h3><ul class="lp-lista">' + positivos.slice(0, 4).map(a => LP.itemHtml(a)).join('') + '</ul>' : '')
      + (!atencao.length && !d.pendencias.length && !positivos.length ? '<div class="ld-empty"><strong>Tudo em dia</strong>Nenhum alerta nem pendência agora.</div>' : '')
      + '<p style="margin:14px 0 0;text-align:center"><button type="button" class="ld-btn sm" id="lpSinoVer">Abrir o Dashboard</button></p>';
    const v = $('lpSinoVer'); if (v) v.onclick = () => { fecharSino(); LP.ir('dashboard'); };
  }
  function abrirSino() { $('lpSinoPop').hidden = false; $('lpSino').setAttribute('aria-expanded', 'true'); montarSino(); }
  function fecharSino() { $('lpSinoPop').hidden = true; $('lpSino').setAttribute('aria-expanded', 'false'); }
  $('lpSino').addEventListener('click', e => { e.stopPropagation(); $('lpSinoPop').hidden ? abrirSino() : fecharSino(); });
  document.addEventListener('click', e => { if (!$('lpSinoPop').hidden && !e.target.closest('.lp-bell-w')) fecharSino(); });
  $('lpBuscar').addEventListener('click', () => LP.mod.busca && LP.mod.busca.abrir());
  LP.aoDash(sino);

  // ── atalhos de teclado ─────────────────────────────────────────────────────────────────────────
  document.addEventListener('keydown', e => {
    const ctrl = e.ctrlKey || e.metaKey;
    if (ctrl && !e.altKey && e.key.toLowerCase() === 'k') { e.preventDefault(); if (LP.mod.busca) LP.mod.busca.abrir(); }
    else if ((ctrl && !e.altKey && e.key.toLowerCase() === 'n') || (e.altKey && !ctrl && e.key.toLowerCase() === 'n')) { e.preventDefault(); LP.abrirQuick(); }   // alguns navegadores reservam o Ctrl+N: o Alt+N é o plano B
    else if (e.key === 'Escape') {
      if (!$('lpPerfilMenu').hidden) fecharPerfil(true);
      else if (!$('lpSinoPop').hidden) { fecharSino(); $('lpSino').focus(); }
      else if (document.body.classList.contains('lp-menu-open')) { fecharMenu(); $('lpBurger').focus(); }
    }
  });

  LP.iniciar = async function () {
    cabecalho();
    const alvo = (location.hash || '').replace('#', '');
    LP.ir(ABAS[alvo] ? alvo : PRIMEIRA);
    window.addEventListener('hashchange', () => { const h = (location.hash || '').replace('#', ''); if (ABAS[h] && h !== LP.aba) LP.ir(h); });
    LP.carregarDash();
  };
})();
