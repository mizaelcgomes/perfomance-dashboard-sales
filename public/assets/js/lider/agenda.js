/*
 * Portal do Líder, aba Agenda (gestão de tempo): o calendário do mês com todas as ATIVIDADES da liderança — reuniões (verde), treinamentos (azul),
 * feedbacks (âmbar), cobranças (vermelho) e revisões de atendimento (roxo) — e a lista das reuniões e treinamentos dos próximos 7 dias, com os
 * botões Editar, Cancelar e Adicionar nota (e Registrar como realizada / Marcar presença). Feedbacks, cobranças e revisões só têm o dia (sem
 * horário) e abrem a edição. "+ Nova atividade" cria qualquer uma das cinco. Os formulários e as ações são os do motor de registros
 * (registros.js); aqui só se desenha a agenda. Dados: lider_agenda.
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP) return;
  const E = LD.esc, $ = id => document.getElementById(id);
  const hojeData = () => LP.asDate(LP.hoje);
  const est = { ano: hojeData().getFullYear(), mes: hojeData().getMonth() + 1, eventos: [], proximos: [], carregou: false, seq: 0, porChave: {} };
  const DOW = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  const STATUS = { reuniao: { agendada: 'Agendada', realizada: 'Realizada', cancelada: 'Cancelada' }, treinamento: { agendado: 'Agendado', realizado: 'Realizado', cancelada: 'Cancelado' } };

  const ENT = { reuniao: 'reunioes', treinamento: 'treinamentos', feedback: 'feedbacks', cobranca: 'cobrancas', revisao: 'revisoes' };
  const ROTULO = { reuniao: 'Reunião de equipe', treinamento: 'Treinamento', feedback: 'Feedback', cobranca: 'Cobrança de cadastramento', revisao: 'Revisão de atendimento' };
  const CURTO = { feedback: 'Feedback', cobranca: 'Cobrança', revisao: 'Revisão' };
  const chave = e => e.tipo + ':' + e.id;
  function indexar() { est.porChave = {}; est.eventos.concat(est.proximos).forEach(e => { est.porChave[chave(e)] = e; }); }
  const hora = e => String(e.quando).slice(11, 16);
  /** Em cada dia, primeiro o que tem horário (reunião, treinamento) e depois os registros do dia (feedback, cobrança, revisão). */
  const doDia = (a, b) => (a.sem_hora ? 1 : 0) - (b.sem_hora ? 1 : 0) || String(a.quando).localeCompare(String(b.quando));
  const quando = e => LD.dm(e.quando) + ' · ' + hora(e);
  /** O texto curto do calendário: "09:00 Título" (reunião, treinamento) ou "Feedback · Fulano" (os registros do dia). */
  const chip = e => (e.sem_hora ? CURTO[e.tipo] + ' · ' + String(e.closer_nome || '').split(' ')[0] : hora(e) + ' ' + e.titulo);

  /** Feedback, cobrança ou revisão: um registro do dia (sem horário), com o texto e o botão Editar. */
  function cartaoRegistro(e) {
    const visto = e.visto === null ? '' : '<span class="ld-chip ' + (e.visto ? 'ok' : 'warn') + '">' + (e.visto ? 'Visto pelo closer' : 'Ainda não visto') + '</span>';
    return '<article class="lp-evc ' + e.tipo + '" data-chave="' + chave(e) + '"><div class="lp-evc-h"><h4>' + E(e.titulo) + '</h4>' + visto + '</div>'
      + '<div class="lp-evc-m"><span><b>' + LD.dm(e.quando) + '</b></span><span>' + ROTULO[e.tipo] + '</span><span>Closer: <b>' + E(e.closer_nome) + '</b></span></div>'
      + (e.detalhe ? '<p class="lp-evc-t">' + E(e.detalhe) + '</p>' : '')
      + '<div class="lp-evc-a"><button type="button" class="ld-btn sm" data-ev="editar">Editar</button></div></article>';
  }

  function cartao(e) {
    if (e.sem_hora) return cartaoRegistro(e);
    const st = STATUS[e.tipo][e.status] || e.status;
    const nomes = e.participantes.map(p => E(p.nome)).join(', ');
    const partic = e.tipo === 'reuniao' ? 'Participantes: <b>' + (nomes || 'ninguém marcado') + '</b>' : (e.status === 'agendado' ? 'Presença: <b>ainda não registrada</b>' : 'Presentes: <b>' + (nomes || 'ninguém registrado') + '</b>');
    const notas = e.notas.slice(0, 2).map(n => '<p class="lp-evc-n"><b>' + E(n.autor) + '</b> · ' + LD.dm(n.quando) + ': ' + E(n.texto) + '</p>').join('');
    const b = (op, rot, cls) => '<button type="button" class="ld-btn sm ' + (cls || '') + '" data-ev="' + op + '">' + rot + '</button>';
    let acoes = '';
    if (e.tipo === 'reuniao') {
      if (e.status === 'agendada') acoes = b('realizar', 'Registrar como realizada', 'pri') + b('editar', 'Editar') + b('cancelar', 'Cancelar');
      else if (e.status === 'realizada') acoes = b('editar', 'Editar');
    } else if (e.status !== 'cancelada') {
      acoes = b('presenca', 'Marcar presença', 'pri') + (e.editavel ? b('editar', 'Editar') : '') + (e.cancelavel ? b('cancelar', 'Cancelar') : '');
    }
    if (e.status !== 'cancelada') acoes += b('nota', 'Adicionar nota');
    return '<article class="lp-evc ' + e.tipo + (e.status === 'cancelada' ? ' cancelada' : '') + '" data-chave="' + chave(e) + '"><div class="lp-evc-h"><h4>' + E(e.titulo) + '</h4><span class="ld-st ' + (e.status === 'cancelada' ? 'cancelada' : e.status === 'agendada' || e.status === 'agendado' ? 'agendada' : 'realizada') + '">' + st + '</span></div>'
      + '<div class="lp-evc-m"><span><b>' + quando(e) + '</b></span><span>' + (e.tipo === 'reuniao' ? 'Reunião de equipe' : 'Treinamento') + (e.geral ? ' (geral)' : '') + '</span><span>' + partic + '</span></div>'
      + notas + (acoes ? '<div class="lp-evc-a">' + acoes + '</div>' : '') + '</article>';
  }

  // ── calendário ────────────────────────────────────────────────────────────────────────────────
  function calendario() {
    const primeiro = new Date(est.ano, est.mes - 1, 1), dias = new Date(est.ano, est.mes, 0).getDate();
    const porDia = {};
    est.eventos.forEach(e => { (porDia[String(e.quando).slice(0, 10)] ||= []).push(e); });
    Object.values(porDia).forEach(lista => lista.sort(doDia));
    let h = '<div class="lp-cal" role="grid" aria-label="' + E(LD.MESES[est.mes - 1]) + ' de ' + est.ano + '">' + DOW.map(d => '<div class="lp-cal-d" role="columnheader">' + d + '</div>').join('');
    for (let i = 0; i < primeiro.getDay(); i++) h += '<div class="lp-dia vazio" aria-hidden="true"></div>';
    for (let d = 1; d <= dias; d++) {
      const iso = est.ano + '-' + String(est.mes).padStart(2, '0') + '-' + String(d).padStart(2, '0'), ev = porDia[iso] || [], ehHoje = iso === LP.hoje;
      const chips = ev.slice(0, 2).map(e => '<span class="lp-ev ' + e.tipo + (e.status === 'cancelada' ? ' cancelada' : '') + '" title="' + E(chip(e)) + '">' + E(chip(e)) + '</span>').join('')
        + (ev.length > 2 ? '<span class="lp-dia-mais">+' + (ev.length - 2) + ' mais</span>' : '')
        + (ev.length ? '<span class="lp-pts" aria-hidden="true">' + ev.map(e => '<i class="' + e.tipo + '"></i>').join('') + '</span>' : '');
      const rot = d + ' de ' + LD.MESES[est.mes - 1] + (ev.length ? ': ' + ev.length + ' atividade' + (ev.length === 1 ? '' : 's') : ': sem atividades');
      h += ev.length ? '<button type="button" class="lp-dia' + (ehHoje ? ' hoje' : '') + '" data-dia="' + iso + '" aria-label="' + E(rot) + '"><span class="lp-dia-n">' + d + '</span>' + chips + '</button>'
        : '<div class="lp-dia' + (ehHoje ? ' hoje' : '') + '" role="gridcell" aria-label="' + E(rot) + '"><span class="lp-dia-n">' + d + '</span></div>';
    }
    $('agCal').innerHTML = h + '</div>';
  }
  function render() {
    $('agTitulo').textContent = LD.MESES[est.mes - 1].replace(/^./, c => c.toUpperCase()) + ' ' + est.ano;
    calendario();
    $('agProx').innerHTML = est.proximos.length ? '<div class="lp-lista">' + est.proximos.map(cartao).join('') + '</div>'
      : '<div class="ld-empty"><strong>Nada nos próximos 7 dias</strong>Marque uma reunião ou um treinamento.<br><button type="button" class="ld-btn pri sm" data-novo>+ Nova atividade</button></div>';
  }

  async function carregar() {
    const meu = ++est.seq;
    if (!est.carregou) { $('agCal').innerHTML = LD.skeleton(); $('agProx').innerHTML = LD.skeleton(); }
    const r = await LP.api('lider_agenda', { ano: est.ano, mes: est.mes });
    if (meu !== est.seq) return;
    if (!r || !r.success) {
      const m = LD.erroTela((r && r.message) || '', 'agRetry'); $('agCal').innerHTML = m; $('agProx').innerHTML = '';
      const b = $('agRetry'); if (b) b.onclick = carregar; return;
    }
    est.carregou = true; est.ano = r.ano; est.mes = r.mes; est.eventos = r.eventos; est.proximos = r.proximos; indexar(); render();
  }

  // ── ações sobre os eventos (as do motor de registros) ─────────────────────────────────────────
  function agir(op, ev) {
    const eng = LP.reg(ENT[ev.tipo]);
    if (typeof eng[op] === 'function') eng[op](ev.item);
  }
  function aoClicar(e, fechar) {
    const b = e.target.closest('[data-ev]'); if (!b) return false;
    const ev = est.porChave[b.closest('[data-chave]').dataset.chave]; if (!ev) return true;
    if (fechar) fechar();
    agir(b.dataset.ev, ev);
    return true;
  }
  $('agProx').addEventListener('click', e => { if (aoClicar(e)) return; if (e.target.closest('[data-novo]')) novaAtividade(); });

  function abrirDia(iso) {
    const ev = est.eventos.filter(e => String(e.quando).slice(0, 10) === iso).sort(doDia);
    const t = LP.asDate(iso).toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' });
    const d = LD.drawer({ titulo: t.charAt(0).toUpperCase() + t.slice(1), corpo: '<div class="lp-lista">' + ev.map(cartao).join('') + '</div>' });
    d.dlg.addEventListener('click', e => { aoClicar(e, d.fechar); });
  }
  $('agCal').addEventListener('click', e => { const b = e.target.closest('[data-dia]'); if (b) abrirDia(b.dataset.dia); });

  /** "+ Nova atividade": escolhe a atividade (as mesmas cinco do calendário) e abre o formulário dela. */
  const ATIVIDADES = [
    ['reunioes', 'Reunião de equipe', 'Marque a reunião e escolha quem participa.'],
    ['treinamentos', 'Treinamento', 'Agende o treinamento e depois marque a presença.'],
    ['feedbacks', 'Feedback', 'Registre um feedback individual para um closer.'],
    ['cobrancas', 'Cobrança de cadastramento', 'Registre que você cobrou um closer sobre o cadastramento.'],
    ['revisoes', 'Revisão de atendimento', 'Registre a revisão de um atendimento pelo número do cliente.'],
  ];
  function novaAtividade() {
    let escolha = null;
    const m = LD.modal({
      titulo: 'Nova atividade',
      corpo: '<p style="margin:0 0 12px;font-size:14px;color:var(--ld-muted)">O que você quer registrar?</p><div class="ld-checks">'
        + ATIVIDADES.map((a, i) => '<label class="ld-check"><input type="radio" name="tipo" value="' + a[0] + '"' + (i === 0 ? ' checked' : '') + '><span><b>' + E(a[1]) + '</b><br><small style="color:var(--ld-muted)">' + E(a[2]) + '</small></span></label>').join('') + '</div>',
      salvar: 'Continuar',
      onSubmit: async form => { escolha = form.querySelector('input[name="tipo"]:checked').value; return { success: true }; },
    });
    m.dlg.addEventListener('close', () => { if (escolha) LP.reg(escolha).novo(); });
  }
  $('agNovo').addEventListener('click', novaAtividade);
  $('agPrev').addEventListener('click', () => { est.mes--; if (est.mes < 1) { est.mes = 12; est.ano--; } carregar(); });
  $('agNext').addEventListener('click', () => { est.mes++; if (est.mes > 12) { est.mes = 1; est.ano++; } carregar(); });
  $('agHoje').addEventListener('click', () => { est.ano = hojeData().getFullYear(); est.mes = hojeData().getMonth() + 1; carregar(); });

  LP.mod.agenda = {
    mostrar() { if (!est.carregou || LP.sujo.delete('agenda')) carregar(); },
    recarregar() { carregar(); },
  };
})();
