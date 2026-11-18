/*
 * Portal do Líder, Meu dia › Histórico completo das ações: o histórico das ações (ligações, follow-ups e leads quentes) que o próprio líder registrou,
 * com filtros por tipo e por data e busca por número ou observação. É a mesma fonte do histórico do portal do closer (closer-api.php, ação
 * "acoes"): o líder também é closer, então estas ações contam na produtividade dele. O número aparece mascarado, como no portal do closer.
 * Cada linha abre a edição (LP.editarAcao: número, tipo, observação, data e hora; e Excluir), a mesma do portal do closer. LP.editarAcao também é
 * usada pela lista "Últimas ações" do Meu dia (meu-portal.js).
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP) return;
  const E = LD.esc;
  const TIPOS = { ligacao: { l: 'Ligação', c: 'var(--lig)', i: 'phone' }, followup: { l: 'Follow-up', c: 'var(--fu)', i: 'refresh' }, lead_quente: { l: 'Lead quente', c: 'var(--lq)', i: 'flame' } };
  const DOW = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  const H = { tipo: '', periodo: 'hoje', data: '', q: '', offset: 0, total: 0, hasMore: false, seq: 0, carregou: false, ultimoDia: null, itens: [], agrupar: false };
  let raiz = null, temporizador = 0;
  const $ = sel => raiz.querySelector(sel);

  function rotuloDia(s) {
    if (s === LP.hoje) return 'Hoje';
    if (s === LP.addDias(LP.hoje, -1)) return 'Ontem';
    return DOW[LP.asDate(s).getDay()] + ', ' + s.slice(8, 10) + '/' + s.slice(5, 7) + (s.slice(0, 4) !== LP.hoje.slice(0, 4) ? '/' + s.slice(0, 4) : '');
  }
  function linha(a) {
    const t = TIPOS[a.tipo] || { l: a.tipo, c: 'var(--ld-gold)', i: 'phone' };
    return '<div class="lp-ac" data-id="' + a.id + '" role="button" tabindex="0" aria-label="Editar a ação das ' + E(a.hora) + '" style="--c:' + t.c + '"><time>' + E(a.hora) + '</time><span class="lp-ac-t">' + (LP.boot.ic[t.i] || '') + E(t.l) + '</span>'
      + '<div class="lp-ac-n">' + E(a.mascarado) + (a.observacao ? '<small>' + E(a.observacao) + '</small>' : '') + '</div></div>';
  }
  LP.linhaAcao = linha;

  /** Abre a edição de uma ação do próprio líder (a mesma do portal do closer). Excluir pede confirmação. */
  LP.editarAcao = async function editarAcao(id) {
    const g = await LP.api('acao_get', { id });
    if (!g || !g.success) { LD.toast((g && g.message) || 'Não foi possível abrir a ação.', 'err'); return; }
    const a = g.acao;
    const opcoes = Object.keys(TIPOS).map(k => '<option value="' + k + '"' + (k === a.tipo ? ' selected' : '') + '>' + E(TIPOS[k].l) + '</option>').join('');
    const fld = (rotulo, nome, controle) => '<div class="ld-fld"><label for="f_' + nome + '">' + rotulo + '</label>' + controle + '<div class="ld-err" data-erro-de="' + nome + '" role="alert"></div></div>';
    const m = LD.modal({
      titulo: 'Editar ação',
      corpo: '<div class="ld-form">'
        + fld('Número', 'telefone', '<input class="ld-in" id="f_telefone" name="telefone" type="tel" inputmode="tel" autocomplete="off" value="' + E(a.telefone) + '">')
        + fld('Tipo', 'tipo', '<select class="ld-in" id="f_tipo" name="tipo">' + opcoes + '</select>')
        + fld('Observação (opcional)', 'observacao', '<textarea class="ld-in" id="f_observacao" name="observacao" maxlength="255" placeholder="Ex.: pediu retorno às 15h">' + E(a.observacao || '') + '</textarea>')
        + fld('Data e hora', 'registrado_em', '<input class="ld-in" id="f_registrado_em" name="registrado_em" type="datetime-local" value="' + E(a.dt_local || '') + '" max="' + E(LD.agoraCampoDH()) + '">')
        + '</div>',
      salvar: 'Salvar',
      onSubmit: async form => {
        const r = await LP.api('acao_editar', { id, telefone: LD.digitosFone(form.elements.telefone.value), tipo: form.elements.tipo.value, observacao: form.elements.observacao.value, registrado_em: form.elements.registrado_em.value });
        if (r && r.success) { LP.acoesMudaram(r.resumo); return { success: true, message: r.message }; }
        return { success: false, message: r && r.message, errors: r && r.field ? { [r.field]: r.message } : {} };
      },
    });
    LD.mascaraFone(m.form.elements.telefone);
    m.form.querySelector('.ld-dlg-f').insertAdjacentHTML('afterbegin', '<button type="button" class="ld-btn" data-excluir style="margin-right:auto;color:var(--ld-bad);border-color:rgba(255,138,128,.4)">Excluir</button>');
    m.form.querySelector('[data-excluir]').addEventListener('click', async () => {
      if (!(await LD.confirmar('Excluir ação', 'Excluir esta ação? Não dá para desfazer.', 'Excluir'))) return;
      const r = await LP.api('acao_excluir', { id });
      if (r && r.success) { m.fechar(); LD.toast(r.message || 'Ação excluída.', 'ok'); LP.acoesMudaram(r.resumo); }
      else LD.toast((r && r.message) || 'Não foi possível excluir.', 'err');
    });
  };

  /** Desenha as ações carregadas: por dia (o padrão) ou agrupadas por cliente (o mesmo número mascarado), com a data em cada linha. */
  function desenhar() {
    const lista = $('[data-lista]');
    if (!H.itens.length) {
      const filtrado = H.q || H.tipo || H.periodo !== 'hoje';
      lista.innerHTML = '<div class="ld-empty"><strong>' + (filtrado ? 'Nenhuma ação encontrada' : 'Nenhuma ação registrada hoje') + '</strong>' + (filtrado ? 'Tente outros filtros.' : 'Use o Registro de Ação Rápida no Meu dia para registrar uma ligação, um follow-up ou um lead quente.') + '</div>';
      return;
    }
    let html = '';
    if (H.agrupar) {
      const grupos = new Map();
      H.itens.forEach(a => { if (!grupos.has(a.mascarado)) grupos.set(a.mascarado, []); grupos.get(a.mascarado).push(a); });
      grupos.forEach((lista2, num) => {
        html += '<h3 class="lp-dia-h">' + E(num) + ' <span class="lp-sub">' + lista2.length + (lista2.length === 1 ? ' ação' : ' ações') + '</span></h3>';
        lista2.forEach(a => { html += '<div class="lp-ac-dt">' + E(rotuloDia(a.data)) + '</div>' + linha(a); });
      });
    } else {
      let ult = null;
      H.itens.forEach(a => {
        if (a.data !== ult) { html += '<h3 class="lp-dia-h">' + E(rotuloDia(a.data)) + '</h3>'; ult = a.data; }
        html += linha(a);
      });
    }
    lista.innerHTML = html;
  }

  async function carregar(reset) {
    const meu = ++H.seq, lista = $('[data-lista]');
    if (reset) { H.offset = 0; H.ultimoDia = null; H.itens = []; if (!H.carregou) lista.innerHTML = LD.skeleton(); }
    const r = await LP.api('acoes', { tipo: H.tipo, periodo: H.periodo, data: H.data, q: H.q, offset: H.offset, limit: 30 });
    if (meu !== H.seq) return;
    if (!r || !r.success) { lista.innerHTML = LD.erroTela((r && r.message) || '', 'acRetry'); const b = document.getElementById('acRetry'); if (b) b.onclick = () => carregar(true); return; }
    H.carregou = true; H.total = r.total; H.hasMore = r.has_more; H.offset += r.acoes.length;
    if (r.hoje) LP.hoje = r.hoje;
    $('[data-contagem]').textContent = r.total + (r.total === 1 ? ' ação' : ' ações');
    $('[data-mais]').hidden = !r.has_more;
    H.itens = H.itens.concat(r.acoes);
    desenhar();
  }

  function montar(container) {
    raiz = container;
    container.innerHTML = '<div class="ld-barra lp-filtros">'
      + '<label class="ld-fg"><span class="ld-lbl">Tipo</span><select class="ld-in" data-tipo><option value="">Todos</option><option value="ligacao">Ligação</option><option value="followup">Follow-up</option><option value="lead_quente">Lead quente</option></select></label>'
      + '<label class="ld-fg"><span class="ld-lbl">Data</span><select class="ld-in" data-periodo><option value="hoje">Hoje</option><option value="semana">Esta semana</option><option value="mes">Este mês</option><option value="ontem">Ontem</option><option value="7d">Últimos 7 dias</option><option value="30d">Últimos 30 dias</option><option value="data">Escolher data…</option></select></label>'
      + '<label class="ld-fg" data-dia-w hidden><span class="ld-lbl">Dia</span><input type="date" class="ld-in" data-dia></label>'
      + '<label class="ld-fg" style="flex:1;min-width:200px"><span class="ld-lbl">Buscar</span><input type="search" class="ld-in" data-q placeholder="Número ou observação" maxlength="40" autocomplete="off"></label>'
      + '<label class="lp-agrupar"><input type="checkbox" data-agrupar> Agrupar por cliente</label>'
      + '<span class="ld-help lp-lim" data-contagem aria-live="polite"></span></div>'
      + '<div class="lp-acoes-l" data-lista aria-live="polite"></div><div style="text-align:center;margin-top:14px"><button type="button" class="ld-btn" data-mais hidden>Carregar mais</button></div>';
    container.addEventListener('change', e => {
      if (e.target.matches('[data-agrupar]')) { H.agrupar = e.target.checked; desenhar(); return; }
      else if (e.target.matches('[data-tipo]')) H.tipo = e.target.value;
      else if (e.target.matches('[data-periodo]')) { H.periodo = e.target.value; $('[data-dia-w]').hidden = H.periodo !== 'data'; if (H.periodo === 'data' && !H.data) { H.data = LP.hoje; $('[data-dia]').value = H.data; } }
      else if (e.target.matches('[data-dia]')) H.data = e.target.value;
      else return;
      carregar(true);
    });
    container.addEventListener('input', e => { if (!e.target.matches('[data-q]')) return; clearTimeout(temporizador); temporizador = setTimeout(() => { H.q = e.target.value.trim(); carregar(true); }, 300); });
    container.querySelector('[data-mais]').addEventListener('click', () => carregar(false));
    container.addEventListener('click', e => { const l = e.target.closest('.lp-ac[data-id]'); if (l) LP.editarAcao(+l.dataset.id); });
    container.addEventListener('keydown', e => { if (e.key !== 'Enter' && e.key !== ' ') return; const l = e.target.closest('.lp-ac[data-id]'); if (l) { e.preventDefault(); LP.editarAcao(+l.dataset.id); } });
    carregar(true);
  }

  LP.mod.acoes = {
    montar,
    mostrar() { if (raiz && !H.carregou) carregar(true); },
    recarregar() { if (raiz && H.carregou) carregar(true); },
  };
})();
