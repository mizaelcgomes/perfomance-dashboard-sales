/*
 * Módulo do líder: utilidades compartilhadas pelo portal do closer e pelo admin (window.LD).
 * Não depende de nenhuma função global das páginas: cada tela recebe o seu "adaptador" de API (portal: closer-api.php; admin: api.php).
 * Aqui ficam: escape de HTML, números e datas em português, cores e contraste, telefone, toast, modal, drawer, abas com teclado e o
 * esqueleto de carregamento. Tudo que mostra texto vindo do servidor passa por LD.esc.
 */
(function () {
  'use strict';
  const LD = (window.LD = window.LD || {});
  if (LD.base) return;            // vários cartões da mesma página incluem este arquivo: só roda uma vez
  LD.base = true;

  // ── texto, números e datas ──────────────────────────────────────────────────────────────────────
  LD.esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  LD.num = v => String(v).replace('.', ',');
  LD.MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  /** 2026-11-10 → 10/11 */
  LD.dm = iso => String(iso || '').slice(8, 10) + '/' + String(iso || '').slice(5, 7);
  /** 2026-11-10 → 10/11/2026 */
  LD.dataBR = iso => (iso ? LD.dm(iso) + '/' + String(iso).slice(0, 4) : '—');
  /** 2026-11-10 09:30:00 → 10/11/2026 às 09:30 */
  LD.dhBR = s => (s ? LD.dataBR(s) + ' às ' + String(s).slice(11, 16) : '—');
  /** valor de <input type="datetime-local"> a partir de 2026-11-10 09:30:00 */
  LD.paraCampoDH = s => (s ? String(s).replace(' ', 'T').slice(0, 16) : '');
  LD.hojeISO = () => {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  };
  LD.agoraCampoDH = () => {
    const d = new Date();
    return LD.hojeISO() + 'T' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  };
  LD.mobile = () => window.matchMedia('(max-width:640px)').matches;
  LD.salvar = (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* sem armazenamento: tudo bem */ } };
  LD.ler = k => { try { return localStorage.getItem(k); } catch (e) { return null; } };

  // ── cores, avatares, variação, posição ─────────────────────────────────────────────────────────
  LD.cor = c => (/^#[0-9a-f]{6}$/i.test(c || '') ? c : '#d4af37');
  /** cor do texto legível sobre o fundo c (equipes escuras como azul-marinho ou preto ficam com texto branco) */
  LD.tinta = c => {
    const h = LD.cor(c), r = parseInt(h.slice(1, 3), 16), g = parseInt(h.slice(3, 5), 16), b = parseInt(h.slice(5, 7), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) > 140 ? '#111' : '#fff';
  };
  LD.iniciais = nome => {
    const p = String(nome || '').trim().split(/\s+/).filter(Boolean);
    return p.length ? ((p[0][0] || '') + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase() : '—';
  };
  LD.avatar = (nome, cor) => '<span class="ld-av" style="--ac:' + LD.cor(cor) + ';--at:' + LD.tinta(cor) + '">' + LD.esc(LD.iniciais(nome)) + '</span>';
  LD.quem = (nome, cor, extra) => '<div class="ld-who">' + LD.avatar(nome, cor) + '<span><span class="ld-nm">' + LD.esc(nome) + '</span>' + (extra || '') + '</span></div>';
  LD.pos = i => '<span class="ld-pos' + (i <= 3 ? ' p' + i : '') + '">' + i + '</span>';
  /** variação contra o período anterior (pp = diferença em pontos percentuais) */
  LD.delta = (cur, prev, pp) => {
    if (cur === null || prev === null || cur === undefined || prev === undefined) return '';
    if (pp) {
      const x = Math.round((cur - prev) * 10) / 10;
      return x === 0 ? '<span class="ld-dl flat">= 0 pp</span>' : '<span class="ld-dl ' + (x > 0 ? 'up' : 'down') + '">' + (x > 0 ? '▲ +' + LD.num(x) : '▼ ' + LD.num(Math.abs(x))) + ' pp</span>';
    }
    if (prev === 0) return cur > 0 ? '<span class="ld-dl up">▲ novo</span>' : '<span class="ld-dl flat">—</span>';
    const p = Math.round((cur - prev) / prev * 100);
    return p === 0 ? '<span class="ld-dl flat">= 0%</span>' : '<span class="ld-dl ' + (p > 0 ? 'up' : 'down') + '">' + (p > 0 ? '▲ +' + p : '▼ ' + Math.abs(p)) + '%</span>';
  };
  /** barra de progresso (0 a 100) com o texto ao lado */
  LD.meter = (rot, vendas, meta, score) => {
    const pct = Math.max(0, Math.min(100, Number(score) || 0));
    return '<div class="ld-meta-r"><span>' + rot + '</span><div class="ld-bar' + (pct >= 100 ? ' ok' : '') + '"><i style="--p:' + pct + '%"></i></div><b>' + vendas + ' / ' + meta + ' · ' + LD.num(score) + '%</b></div>';
  };

  // ── telefone (a mesma máscara do portal) ───────────────────────────────────────────────────────
  LD.digitosFone = v => { let d = String(v || '').replace(/\D/g, ''); if (d.length > 11 && d.indexOf('55') === 0) d = d.slice(2); return d.slice(0, 11); };
  LD.fmtFone = d => {
    if (!d) return '';
    if (d.length <= 2) return '(' + d;
    if (d.length <= 6) return '(' + d.slice(0, 2) + ') ' + d.slice(2);
    if (d.length <= 10) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + (d.length > 6 ? '-' + d.slice(6) : '');
    return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
  };
  LD.mascaraFone = el => el.addEventListener('input', () => { el.value = LD.fmtFone(LD.digitosFone(el.value)); });

  // ── toast ──────────────────────────────────────────────────────────────────────────────────────
  let toastT;
  LD.toast = (msg, tipo) => {
    let t = document.getElementById('ldToast');
    if (!t) {
      t = document.createElement('div'); t.id = 'ldToast'; t.className = 'ld-toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite');
      LD.host().appendChild(t);
    }
    t.textContent = msg; t.className = 'ld-toast on ' + (tipo || 'ok');
    clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('on'), 3200);
  };

  // ── esqueleto de carregamento ──────────────────────────────────────────────────────────────────
  LD.skeleton = tipo => tipo === 'kpis'
    ? '<div class="ld-skels kpis" aria-hidden="true">' + '<span class="ld-skel k"></span>'.repeat(6) + '</div>'
    : '<div class="ld-skels" aria-hidden="true"><span class="ld-skel k"></span><span class="ld-skel k"></span><span class="ld-skel k"></span></div>';
  LD.erroTela = (msg, idRetry) => '<div class="ld-empty" role="alert"><strong>Não foi possível carregar</strong>' + LD.esc(msg || 'Tente de novo em instantes.') + '<br><button type="button" class="ld-btn sm" id="' + idRetry + '">Tentar de novo</button></div>';

  // ── elemento "raiz" para modais, drawers e toast (as variáveis de cor valem só dentro de .ld) ──────
  LD.host = () => {
    let h = document.getElementById('ldHost');
    if (!h) { h = document.createElement('div'); h.id = 'ldHost'; h.className = 'ld'; document.body.appendChild(h); }
    return h;
  };

  // ── abas com teclado (setas, Home e End) ───────────────────────────────────────────────────────
  /** container tem botões role="tab"; a função devolve selecionar(valor) e chama aoMudar(valor) em clique ou seta. attr = atributo data-* que guarda o valor. */
  LD.abas = (container, attr, aoMudar) => {
    const botoes = () => [...container.querySelectorAll('[role="tab"]')];
    const selecionar = (valor, foco) => {
      botoes().forEach(b => { const on = b.dataset[attr] === valor; b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; b.classList.toggle('active', on); if (on && foco) b.focus(); });
    };
    container.addEventListener('click', e => { const b = e.target.closest('[role="tab"]'); if (b && container.contains(b)) { selecionar(b.dataset[attr]); aoMudar(b.dataset[attr]); } });
    container.addEventListener('keydown', e => {
      const lista = botoes(); const i = lista.indexOf(document.activeElement);
      if (i < 0 || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
      e.preventDefault();
      const j = e.key === 'Home' ? 0 : e.key === 'End' ? lista.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + lista.length) % lista.length;
      selecionar(lista[j].dataset[attr], true); aoMudar(lista[j].dataset[attr]);
    });
    const atual = botoes().find(b => b.getAttribute('aria-selected') === 'true');
    if (atual) selecionar(atual.dataset[attr]);
    return selecionar;
  };

  // ── formulários: erros por campo ───────────────────────────────────────────────────────────────
  /** marca os campos com erro (name = chave do objeto errors) e põe a mensagem ao lado; devolve se algum campo foi marcado */
  LD.mostrarErros = (form, erros) => {
    let algum = false;
    form.querySelectorAll('.ld-in').forEach(c => { c.removeAttribute('aria-invalid'); });
    form.querySelectorAll('.ld-err').forEach(e => { e.textContent = ''; });
    Object.keys(erros || {}).forEach(nome => {
      const campo = form.elements[nome], alvo = form.querySelector('[data-erro-de="' + nome + '"]');
      if (campo && typeof campo.setAttribute === 'function') { campo.setAttribute('aria-invalid', 'true'); algum = true; }
      if (alvo) { alvo.textContent = erros[nome]; algum = true; }       // o erro aparece ao lado do campo (ou da lista, como a de presença)
    });
    const primeiro = form.querySelector('[aria-invalid="true"]'); if (primeiro) primeiro.focus();
    return algum;
  };

  // ── modal (desktop) / folha que sobe (celular) e drawer lateral ───────────────────────────────
  let idDlg = 0;
  function criarDialogo(classe, titulo, corpo, rodape, onSubmit) {
    const gatilho = document.activeElement;
    const dlg = document.createElement('dialog'); const id = 'ldDlg' + (++idDlg);
    dlg.className = classe; dlg.setAttribute('aria-labelledby', id + 't');
    dlg.innerHTML = '<div class="ld-dlg-h"><h2 id="' + id + 't">' + LD.esc(titulo) + '</h2><button type="button" class="ld-dlg-x" aria-label="Fechar">✕</button></div>'
      + '<form class="ld-dlg-form" novalidate><div class="ld-dlg-b"><div class="ld-note err" role="alert" data-topo hidden></div>' + corpo + '</div>' + (rodape || '') + '</form>';
    LD.host().appendChild(dlg);
    const form = dlg.querySelector('form'), topo = dlg.querySelector('[data-topo]');
    const fechar = () => { try { dlg.close(); } catch (e) { /* já fechado */ } };
    dlg.addEventListener('close', () => { dlg.remove(); if (gatilho && gatilho.focus && document.contains(gatilho)) gatilho.focus(); });
    dlg.querySelector('.ld-dlg-x').addEventListener('click', fechar);
    dlg.addEventListener('click', e => { if (e.target === dlg) fechar(); });     // clique no fundo escuro
    dlg.querySelectorAll('[data-cancelar]').forEach(b => b.addEventListener('click', fechar));
    form.addEventListener('submit', async e => {
      e.preventDefault();
      if (!onSubmit) return;
      const salvar = form.querySelector('[type="submit"]'); if (salvar) salvar.disabled = true;
      topo.hidden = true;
      let r;
      try { r = await onSubmit(form); } catch (x) { r = { success: false, message: 'Sem conexão. Verifique sua internet.' }; }
      if (salvar) salvar.disabled = false;
      if (r && r.success) { fechar(); if (r.message) LD.toast(r.message, 'ok'); return; }
      const marcou = LD.mostrarErros(form, (r && r.errors) || {});
      if (!marcou || (r && r.message && !(r.errors && Object.keys(r.errors).length))) { topo.textContent = (r && r.message) || 'Não foi possível salvar.'; topo.hidden = false; }
    });
    dlg.showModal();
    const primeiro = form.querySelector('.ld-in:not([readonly]):not(:disabled), input:not([type=hidden])'); if (primeiro) primeiro.focus();
    return { dlg, form, fechar };
  }
  /** Modal com formulário. opts: titulo, corpo (HTML dos campos), salvar (rótulo), onSubmit(form) → Promise<{success, message, errors}>. */
  LD.modal = opts => criarDialogo('ld-modal', opts.titulo, opts.corpo,
    '<div class="ld-dlg-f"><button type="button" class="ld-btn" data-cancelar>Cancelar</button><button type="submit" class="ld-btn pri">' + LD.esc(opts.salvar || 'Salvar') + '</button></div>', opts.onSubmit);
  /** Painel lateral só de leitura. */
  LD.drawer = opts => criarDialogo('ld-drawer', opts.titulo, opts.corpo, '<div class="ld-dlg-f"><button type="button" class="ld-btn" data-cancelar>Fechar</button></div>', null);
  /** Confirmação simples. Devolve Promise<boolean>. */
  LD.confirmar = (titulo, texto, rotulo) => new Promise(resolve => {
    let feito = false;
    const m = LD.modal({ titulo, corpo: '<p style="font-size:14px;line-height:1.6">' + LD.esc(texto) + '</p>', salvar: rotulo || 'Confirmar', onSubmit: async () => { feito = true; return { success: true }; } });
    m.dlg.addEventListener('close', () => resolve(feito));
  });
})();
