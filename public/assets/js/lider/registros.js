/*
 * Módulo do líder: o motor das telas de registros (reuniões, feedbacks, cobranças de cadastramento, revisões de atendimento e treinamentos).
 * Uma configuração por entidade (cartão da lista + campos do formulário) e um motor só: lista em cartões com filtros por data e por closer,
 * modal com erros por campo, ações de estado (realizar, cancelar, presença, nota) e valores pré-preenchidos para as ações dos alertas.
 * Não há exclusão. Fala com o servidor por um adaptador: api(action, dados) → Promise<json>.
 * Ações usadas: lider_registros (listar), lider_registro_salvar (criar ou editar) e lider_registro_acao (realizar, cancelar, presencas, nota).
 * O motor devolve um controlador (novo, editar, realizar, cancelar, presenca, nota, pronto, recarregar) para a Agenda e o Dashboard chamarem.
 */
(function () {
  'use strict';
  const LD = window.LD;
  if (!LD || LD.registros) return;
  const E = LD.esc;

  const fld = (rotulo, nome, controle, ajuda) =>
    '<div class="ld-fld"><label for="f_' + nome + '">' + rotulo + '</label>' + controle + (ajuda ? '<span class="ld-help">' + ajuda + '</span>' : '') + '<div class="ld-err" data-erro-de="' + nome + '" role="alert"></div></div>';
  const fldGrupo = (rotulo, nome, html, ajuda) =>
    '<div class="ld-fld" role="group" aria-label="' + E(rotulo) + '"><span class="ld-lbl">' + rotulo + '</span>' + html + (ajuda ? '<span class="ld-help">' + ajuda + '</span>' : '') + '<div class="ld-err" data-erro-de="' + nome + '" role="alert"></div></div>';
  const input = (nome, tipo, valor, extra) => '<input class="ld-in" id="f_' + nome + '" name="' + nome + '" type="' + tipo + '" value="' + E(valor || '') + '" ' + (extra || '') + '>';
  const area = (nome, valor, max, extra) => '<textarea class="ld-in" id="f_' + nome + '" name="' + nome + '" maxlength="' + max + '" ' + (extra || '') + '>' + E(valor || '') + '</textarea><div class="ld-count" data-conta="' + nome + '" data-max="' + max + '" aria-hidden="true"></div>';
  const selecaoCloser = (closers, atual, nome) => '<select class="ld-in" id="f_' + (nome || 'closer_id') + '" name="' + (nome || 'closer_id') + '"><option value="">Escolha o closer</option>'
    + closers.map(c => '<option value="' + c.id + '"' + (String(c.id) === String(atual) ? ' selected' : '') + '>' + E(c.nome) + '</option>').join('') + '</select>';
  const somenteLeitura = (rotulo, valor) => '<div class="ld-fld"><span class="ld-lbl">' + rotulo + '</span><input class="ld-in" value="' + E(valor) + '" readonly aria-readonly="true"></div>';
  const vistoChip = it => it.visto ? '<span class="ld-chip ok">Visto pelo closer</span>' : '<span class="ld-chip warn">Ainda não visto</span>';
  const botao = (op, rotulo, cls) => '<button type="button" class="ld-btn sm ' + (cls || '') + '" data-op="' + op + '">' + rotulo + '</button>';
  const checks = (nome, lista, marcados) => '<div class="ld-checks">' + lista.map(c => '<label class="ld-check"><input type="checkbox" name="' + nome + '" value="' + c.id + '"' + (marcados.has(c.id) ? ' checked' : '') + '><span>' + E(c.nome) + (c.lider ? ' <small>(você)</small>' : '') + '</span></label>').join('') + '</div>';

  // ── uma configuração por entidade ──────────────────────────────────────────────────────────────
  const ENT = {
    reunioes: {
      nome: 'Reuniões', rotulo: 'reunião', novo: 'Nova reunião', vazioT: 'Nenhuma reunião ainda', vazioD: 'Marque a primeira reunião do time e registre o horário em que ela aconteceu.',
      data: it => String(it.data_hora_marcada).slice(0, 10),
      cartao: it => '<article class="ld-reg" data-id="' + it.id + '"><div class="ld-reg-h"><h4>' + E(it.titulo) + '</h4><span class="ld-st ' + it.status + '">' + { agendada: 'Agendada', realizada: 'Realizada', cancelada: 'Cancelada' }[it.status] + '</span></div>'
        + '<div class="ld-reg-m"><span>Marcada para <b>' + LD.dhBR(it.data_hora_marcada) + '</b></span>' + (it.data_hora_realizada ? '<span>Realizada em <b>' + LD.dhBR(it.data_hora_realizada) + '</b></span>' : '') + '<span>Participantes: <b>' + (it.participantes || []).length + '</b></span></div>'
        + '<div class="ld-reg-a">' + (it.status === 'agendada' ? botao('realizar', 'Registrar como realizada', 'pri') + botao('editar', 'Editar') + botao('cancelar', 'Cancelar reunião') : '') + (it.status === 'realizada' ? botao('editar', 'Editar') : '') + (it.status !== 'cancelada' ? botao('nota', 'Adicionar nota') : '') + '</div></article>',
      form: (it, ctx) => fld('Título', 'titulo', input('titulo', 'text', it && it.titulo, 'maxlength="120" required'))
        + fld('Horário marcado', 'data_hora_marcada', input('data_hora_marcada', 'datetime-local', it ? LD.paraCampoDH(it.data_hora_marcada) : '', 'required' + (it && it.status !== 'agendada' ? ' readonly' : '')), it && it.status !== 'agendada' ? 'O horário marcado só muda enquanto a reunião está agendada.' : '')
        + (it && it.status === 'realizada' ? fld('Horário em que foi realizada', 'data_hora_realizada', input('data_hora_realizada', 'datetime-local', LD.paraCampoDH(it.data_hora_realizada), 'max="' + LD.agoraCampoDH() + '"')) : '')
        + fldGrupo('Participantes', 'participantes', checks('participantes', ctx.time, new Set(it ? it.participantes : ctx.time.filter(c => !c.lider).map(c => c.id))), it ? '' : 'Por padrão, todos os closers da equipe.'),
      dados: (f, it) => {
        const d = { titulo: f.titulo.value, participantes: [...f.querySelectorAll('input[name="participantes"]:checked')].map(c => Number(c.value)) };
        if (!it || it.status === 'agendada') d.data_hora_marcada = f.data_hora_marcada.value;
        if (it && it.status === 'realizada') d.data_hora_realizada = f.data_hora_realizada.value;
        return d;
      },
    },
    feedbacks: {
      nome: 'Feedbacks', rotulo: 'feedback', novo: 'Novo feedback', comCloser: true, vazioT: 'Nenhum feedback ainda', vazioD: 'Escreva o primeiro feedback individual para um closer da equipe. Ele vê tudo o que receber no portal.',
      data: it => it.data,
      cartao: it => '<article class="ld-reg" data-id="' + it.id + '"><div class="ld-reg-h"><h4>' + E(it.assunto) + '</h4>' + vistoChip(it) + '</div>'
        + '<div class="ld-reg-m"><span>Para <b>' + E(it.closer_nome) + '</b></span><span>' + LD.dataBR(it.data) + '</span>' + (it.atualizado_em ? '<span>editado em ' + LD.dataBR(it.atualizado_em) + '</span>' : '') + '</div>'
        + '<p class="ld-reg-t">' + E(it.texto) + '</p><div class="ld-reg-a">' + botao('editar', 'Editar') + '</div></article>',
      form: (it, ctx) => (it ? somenteLeitura('Closer', it.closer_nome) : fld('Closer', 'closer_id', selecaoCloser(ctx.closers, ctx.preset.closer_id, 'closer_id')))
        + fld('Assunto', 'assunto', input('assunto', 'text', it ? it.assunto : ctx.preset.assunto, 'maxlength="120" required'))
        + fld('Feedback', 'texto', area('texto', it && it.texto, 2000, 'rows="6" required'))
        + (it ? somenteLeitura('Data', LD.dataBR(it.data)) : fld('Data', 'data', input('data', 'date', ctx.hoje, 'max="' + ctx.hoje + '"'))),
      dados: (f, it) => it ? { assunto: f.assunto.value, texto: f.texto.value } : { closer_id: f.closer_id.value, assunto: f.assunto.value, texto: f.texto.value, data: f.data.value },
    },
    cobrancas: {
      nome: 'Cobranças de cadastramento', rotulo: 'cobrança', novo: 'Nova cobrança', comCloser: true, vazioT: 'Nenhuma cobrança registrada', vazioD: 'Registre cada vez que você cobrar um closer sobre o cadastramento. O cadastramento em si é feito fora do sistema: aqui fica só o registro de que você cobrou.',
      data: it => it.data,
      cartao: it => '<article class="ld-reg" data-id="' + it.id + '"><div class="ld-reg-h"><h4>Cobrança para ' + E(it.closer_nome) + '</h4>' + vistoChip(it) + '</div>'
        + '<div class="ld-reg-m"><span>' + LD.dataBR(it.data) + '</span>' + (it.atualizado_em ? '<span>editada em ' + LD.dataBR(it.atualizado_em) + '</span>' : '') + '</div>'
        + (it.observacao ? '<p class="ld-reg-t">' + E(it.observacao) + '</p>' : '') + '<div class="ld-reg-a">' + botao('editar', 'Editar') + '</div></article>',
      form: (it, ctx) => (it ? somenteLeitura('Closer', it.closer_nome) : fld('Closer', 'closer_id', selecaoCloser(ctx.closers, ctx.preset.closer_id, 'closer_id')))
        + fld('Observação <small>(opcional)</small>', 'observacao', area('observacao', it && it.observacao, 255, 'rows="3"'))
        + (it ? somenteLeitura('Data', LD.dataBR(it.data)) : fld('Data da cobrança', 'data', input('data', 'date', ctx.hoje, 'max="' + ctx.hoje + '"'))),
      dados: (f, it) => it ? { observacao: f.observacao.value } : { closer_id: f.closer_id.value, observacao: f.observacao.value, data: f.data.value },
    },
    revisoes: {
      nome: 'Revisões de atendimento', rotulo: 'revisão', novo: 'Nova revisão', comCloser: true, vazioT: 'Nenhuma revisão ainda', vazioD: 'Registre a revisão de um atendimento: o closer, o telefone do cliente e a sua observação.',
      data: it => it.data,
      cartao: it => '<article class="ld-reg" data-id="' + it.id + '"><div class="ld-reg-h"><h4>Atendimento de ' + E(it.closer_nome) + '</h4></div>'
        + '<div class="ld-reg-m"><span>Cliente <b>' + E(it.telefone_fmt) + '</b></span><span>' + LD.dataBR(it.data) + '</span>' + (it.atualizado_em ? '<span>editada em ' + LD.dataBR(it.atualizado_em) + '</span>' : '') + '</div>'
        + (it.observacao ? '<p class="ld-reg-t">' + E(it.observacao) + '</p>' : '') + '<div class="ld-reg-a">' + botao('editar', 'Editar') + '</div></article>',
      form: (it, ctx) => (it ? somenteLeitura('Closer', it.closer_nome) : fld('Closer', 'closer_id', selecaoCloser(ctx.closers, ctx.preset.closer_id, 'closer_id')))
        + fld('Telefone do cliente', 'telefone', input('telefone', 'tel', it ? LD.fmtFone(it.telefone) : '', 'inputmode="tel" autocomplete="off" placeholder="(11) 98765-4321" required'))
        + fld('Observação <small>(opcional)</small>', 'observacao', area('observacao', it && it.observacao, 255, 'rows="3"'))
        + (it ? somenteLeitura('Data', LD.dataBR(it.data)) : fld('Data', 'data', input('data', 'date', ctx.hoje, 'max="' + ctx.hoje + '"'))),
      dados: (f, it) => it ? { telefone: f.telefone.value, observacao: f.observacao.value } : { closer_id: f.closer_id.value, telefone: f.telefone.value, observacao: f.observacao.value, data: f.data.value },
      aoAbrir: form => { if (form.elements.telefone) LD.mascaraFone(form.elements.telefone); },
    },
    treinamentos: {
      nome: 'Treinamentos', rotulo: 'treinamento', novo: 'Novo treinamento', vazioT: 'Nenhum treinamento ainda', vazioD: 'Registre um treinamento da equipe e marque quem esteve presente.',
      data: it => String(it.data_hora).slice(0, 10),
      cartao: (it, ctx) => '<article class="ld-reg" data-id="' + it.id + '"><div class="ld-reg-h"><h4>' + E(it.assunto) + '</h4><span>' + (it.cancelado ? '<span class="ld-st cancelada">Cancelado</span>' : '') + (it.geral ? '<span class="ld-chip mute">Geral</span>' : '') + '</span></div>'
        + '<div class="ld-reg-m"><span><b>' + LD.dhBR(it.data_hora) + '</b></span>' + (it.cancelado ? '' : '<span>Presentes: <b>' + it.presentes.length + '</b> de ' + ctx.time.length + '</span>') + '</div>'
        + '<div class="ld-reg-a">' + (it.cancelado ? '' : botao('presenca', 'Marcar presença', 'pri') + (it.editavel ? botao('editar', 'Editar') + botao('cancelar', 'Cancelar treinamento') : '') + botao('nota', 'Adicionar nota')) + '</div></article>',
      form: it => fld('Assunto', 'assunto', input('assunto', 'text', it && it.assunto, 'maxlength="120" required'))
        + fld('Data e hora', 'data_hora', input('data_hora', 'datetime-local', it ? LD.paraCampoDH(it.data_hora) : '', 'required')),
      dados: f => ({ assunto: f.assunto.value, data_hora: f.data_hora.value }),
    },
  };

  /**
   * Monta uma entidade dentro de um contêiner. ctx: { api(action, dados) → Promise<json>, aoMudar() }.
   * Devolve o controlador { pronto, recarregar, novo, editar, realizar, cancelar, presenca, nota }.
   */
  LD.registros = function montar(container, entidade, ctx) {
    const conf = ENT[entidade];
    const estado = { itens: [], closers: [], time: [], hoje: LD.hojeISO(), filtro: '', de: '', ate: '', preset: {}, carregou: false, seq: 0 };
    if (!conf) { container.innerHTML = '<div class="ld-empty">Tela desconhecida.</div>'; return {}; }
    let esperando = [];
    const contexto = () => ({ closers: estado.closers, time: estado.time, hoje: estado.hoje, preset: estado.preset });
    const mudou = () => { if (ctx.aoMudar) ctx.aoMudar(entidade); };

    // ── lista: a barra de filtros é montada uma vez; só a lista é refeita ao filtrar (o foco do campo não se perde) ──
    function visiveis() {
      return estado.itens.filter(i => {
        if (conf.comCloser && estado.filtro && String(i.closer_id) !== estado.filtro) return false;
        const d = conf.data(i);
        return !(estado.de && d < estado.de) && !(estado.ate && d > estado.ate);
      });
    }
    function montarEstrutura() {
      if (!estado.itens.length) {
        container.innerHTML = '<div class="ld-empty"><strong>' + conf.vazioT + '</strong>' + conf.vazioD + '<br><button type="button" class="ld-btn pri sm" data-op="novo">+ ' + conf.novo + '</button></div>';
        return;
      }
      const closer = conf.comCloser
        ? '<label class="ld-fg"><span class="ld-lbl">Closer</span><select class="ld-in" data-filtro><option value="">Todos os closers</option>' + estado.closers.map(c => '<option value="' + c.id + '"' + (String(c.id) === estado.filtro ? ' selected' : '') + '>' + E(c.nome) + '</option>').join('') + '</select></label>' : '';
      container.innerHTML = '<div class="ld-barra lp-filtros"><label class="ld-fg"><span class="ld-lbl">De</span><input type="date" class="ld-in" data-de value="' + E(estado.de) + '" max="9999-12-31"></label>'
        + '<label class="ld-fg"><span class="ld-lbl">Até</span><input type="date" class="ld-in" data-ate value="' + E(estado.ate) + '" max="9999-12-31"></label>' + closer
        + '<span class="ld-help lp-lim" data-contagem aria-live="polite"></span><button type="button" class="ld-btn pri" data-op="novo">+ ' + conf.novo + '</button></div><div data-lista></div>';
      lista();
    }
    function lista() {
      const area = container.querySelector('[data-lista]'); if (!area) return;
      const itens = visiveis();
      container.querySelector('[data-contagem]').textContent = itens.length + (itens.length === estado.itens.length ? '' : ' de ' + estado.itens.length) + ' registro' + (estado.itens.length === 1 ? '' : 's');
      area.innerHTML = itens.length ? '<div class="ld-regs">' + itens.map(i => conf.cartao(i, contexto())).join('') + '</div>' : '<div class="ld-empty">Nenhum registro com estes filtros.</div>';
    }

    async function recarregar() {
      const meu = ++estado.seq;
      if (!estado.carregou) container.innerHTML = LD.skeleton();
      const r = await ctx.api('lider_registros', { entidade });
      if (meu !== estado.seq) return;
      if (!r || !r.success) {
        if (r && r.code === 'auth') return;
        container.innerHTML = LD.erroTela((r && r.message) || '', 'ldRetry' + entidade);
        const b = document.getElementById('ldRetry' + entidade); if (b) b.onclick = recarregar;
        return;
      }
      estado.itens = r.itens; estado.closers = r.closers; estado.time = r.time || []; estado.hoje = r.hoje || estado.hoje; estado.carregou = true;
      montarEstrutura();
      esperando.forEach(f => f()); esperando = [];
    }
    /** Resolve quando a lista já foi carregada (os menus de closer e a lista de presença dependem dela). */
    function pronto() { return estado.carregou ? Promise.resolve() : new Promise(res => { esperando.push(res); }); }

    async function salvar(it, form) {
      const dados = Object.assign({ entidade }, conf.dados(form, it));
      if (it) dados.id = it.id;
      const r = await ctx.api('lider_registro_salvar', dados);
      if (r && r.success) { await recarregar(); mudou(); }
      return r;
    }
    async function abrirForm(it, preset) {
      await pronto();
      estado.preset = preset || {};
      const m = LD.modal({ titulo: it ? 'Editar ' + conf.rotulo : conf.novo, corpo: '<div class="ld-form">' + conf.form(it, contexto()) + '</div>', salvar: it ? 'Salvar alterações' : 'Registrar', onSubmit: form => salvar(it, form) });
      estado.preset = {};
      m.form.querySelectorAll('[data-conta]').forEach(c => {
        const t = m.form.elements[c.dataset.conta], max = Number(c.dataset.max);
        const atualiza = () => { c.textContent = t.value.length + ' / ' + max; };
        t.addEventListener('input', atualiza); atualiza();
      });
      if (conf.aoAbrir) conf.aoAbrir(m.form);
      return m;
    }
    function abrirRealizada(it) {
      LD.modal({
        titulo: 'Registrar reunião realizada',
        corpo: '<div class="ld-form"><p style="font-size:14px;color:var(--ld-muted)">' + E(it.titulo) + '</p>' + fld('Horário em que foi realizada', 'data_hora_realizada', input('data_hora_realizada', 'datetime-local', LD.agoraCampoDH(), 'required max="' + LD.agoraCampoDH() + '"'), 'Não pode ser antes do horário marcado (' + LD.dhBR(it.data_hora_marcada) + ') nem no futuro.') + '</div>',
        salvar: 'Registrar',
        onSubmit: async form => { const r = await ctx.api('lider_registro_acao', { entidade, id: it.id, acao: 'realizar', data_hora_realizada: form.data_hora_realizada.value }); if (r && r.success) { await recarregar(); mudou(); } return r; },
      });
    }
    async function cancelar(it) {
      const titulo = it.titulo || it.assunto;
      if (!(await LD.confirmar('Cancelar ' + conf.rotulo, 'Cancelar "' + titulo + '"? Fica no histórico como cancelado e não pode mais ser alterado.', 'Cancelar ' + conf.rotulo))) return;
      const r = await ctx.api('lider_registro_acao', { entidade, id: it.id, acao: 'cancelar' });
      if (r && r.success) { LD.toast(conf.rotulo.charAt(0).toUpperCase() + conf.rotulo.slice(1) + ' cancelado.', 'ok'); await recarregar(); mudou(); } else LD.toast((r && r.message) || 'Não foi possível cancelar.', 'err');
    }
    async function presenca(it) {
      await pronto();
      const marcados = new Set(it.presentes);
      LD.modal({
        titulo: 'Presença · ' + it.assunto,
        corpo: '<div class="ld-form"><p style="font-size:13px;color:var(--ld-muted)">' + LD.dhBR(it.data_hora) + ' · marque quem esteve presente.</p>' + checks('presentes', estado.time, marcados)
          + '<div class="ld-err" data-erro-de="presentes" role="alert"></div></div>',
        salvar: 'Salvar presença',
        onSubmit: async form => {
          const ids = [...form.querySelectorAll('input[name="presentes"]:checked')].map(c => Number(c.value));
          const r = await ctx.api('lider_registro_acao', { entidade, id: it.id, acao: 'presencas', presentes: ids });
          if (r && r.success) { await recarregar(); mudou(); }
          return r;
        },
      });
    }
    function nota(it) {
      LD.modal({
        titulo: 'Adicionar nota · ' + (it.titulo || it.assunto),
        corpo: '<div class="ld-form">' + fld('Nota', 'texto', area('texto', '', 500, 'rows="4" required'), 'A nota fica registrada no evento com o seu nome e a data. Não dá para editar nem apagar depois.') + '</div>',
        salvar: 'Adicionar nota',
        onSubmit: async form => { const r = await ctx.api('lider_registro_acao', { entidade, id: it.id, acao: 'nota', texto: form.texto.value }); if (r && r.success) mudou(); return r; },
      }).form.querySelectorAll('[data-conta]').forEach(c => {
        const t = c.closest('form').elements[c.dataset.conta];
        const atualiza = () => { c.textContent = t.value.length + ' / ' + c.dataset.max; };
        t.addEventListener('input', atualiza); atualiza();
      });
    }

    const acoes = { novo: () => abrirForm(null), editar: abrirForm, realizar: abrirRealizada, cancelar, presenca, nota };
    container.addEventListener('click', e => {
      const b = e.target.closest('[data-op]'); if (!b) return;
      const card = b.closest('[data-id]'), it = card ? estado.itens.find(x => String(x.id) === card.dataset.id) : null;
      const op = b.dataset.op;
      if (op === 'novo') abrirForm(null);
      else if (it && acoes[op]) acoes[op](it);
    });
    container.addEventListener('change', e => {
      if (e.target.matches('[data-filtro]')) estado.filtro = e.target.value;
      else if (e.target.matches('[data-de]')) estado.de = e.target.value;
      else if (e.target.matches('[data-ate]')) estado.ate = e.target.value;
      else return;
      lista();
    });

    recarregar();
    return {
      pronto, recarregar,
      /** Abre o formulário de novo registro. preset: { closer_id, assunto } pré-preenche o closer e o assunto. */
      novo: preset => abrirForm(null, preset),
      editar: it => abrirForm(it),
      realizar: abrirRealizada, cancelar, presenca, nota,
      /** Acha o registro pelo id (já carregado) e executa a ação; avisa se não achar. */
      async porId(id, acao) {
        await pronto();
        const it = estado.itens.find(x => x.id === id);
        if (!it) { LD.toast('Não encontrei este registro na lista.', 'err'); return; }
        acoes[acao](it);
      },
    };
  };
  LD.registros.ENTIDADES = ['reunioes', 'feedbacks', 'cobrancas', 'revisoes', 'treinamentos'];
  LD.registros.NOMES = Object.keys(ENT).reduce((o, k) => { o[k] = ENT[k].nome; return o; }, {});
})();
