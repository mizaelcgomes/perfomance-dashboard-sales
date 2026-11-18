/*
 * Portal do Líder, aba Configurações: as preferências de alerta do líder. Escolhe quais alertas quer ver (closer sem fechar contrato, meta
 * batida, atividade, feedback atrasado, agenda), a partir de quantos dias sem contrato o closer vira alerta e a partir de quantos dias sem
 * feedback ele vira pendência. Mostra também o perfil e a lista de atalhos de teclado. Ao salvar, o Dashboard e o sino são recalculados.
 * Dados: lider_preferencias (GET) e lider_preferencias_salvar (POST, com as mesmas faixas que o servidor confere).
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP) return;
  const E = LD.esc, $ = id => document.getElementById(id);
  const est = { carregou: false, seq: 0 };
  const ATALHOS = [['Busca global', 'Ctrl K'], ['Registro de ação rápida', 'Ctrl N ou Alt N'], ['Fechar painéis e menus', 'Esc']];

  function desenhar(r) {
    const p = r.preferencias, lim = r.limites;
    const sw = Object.keys(r.alertas_nomes).map(k => '<label class="lp-sw"><input type="checkbox" name="alerta_' + E(k) + '" data-alerta="' + E(k) + '"' + (p.alertas[k] ? ' checked' : '') + '><span>' + E(r.alertas_nomes[k]) + '</span></label>').join('');
    $('cfView').innerHTML = '<div class="lp-cfg">'
      + '<article class="lp-card" aria-labelledby="cfAlH"><div class="lp-card-h"><h2 id="cfAlH">Alertas e pendências</h2></div>'
      + '<form id="cfForm" novalidate><div class="lp-cfg-sw" role="group" aria-label="Quais alertas mostrar">' + sw + '</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:18px">'
      + '<div class="ld-fg"><label class="ld-lbl" for="cfSv">Dias sem contrato para alertar</label><input class="ld-in" id="cfSv" name="limite_dias_sem_venda" type="number" inputmode="numeric" min="' + lim.sem_venda[0] + '" max="' + lim.sem_venda[1] + '" value="' + p.limite_dias_sem_venda + '"><span class="ld-help">De ' + lim.sem_venda[0] + ' a ' + lim.sem_venda[1] + ' dias. Padrão: 3.</span><span class="ld-err" data-erro-de="limite_dias_sem_venda"></span></div>'
      + '<div class="ld-fg"><label class="ld-lbl" for="cfSf">Dias sem feedback para cobrar</label><input class="ld-in" id="cfSf" name="dias_sem_feedback" type="number" inputmode="numeric" min="' + lim.sem_feedback[0] + '" max="' + lim.sem_feedback[1] + '" value="' + p.dias_sem_feedback + '"><span class="ld-help">De ' + lim.sem_feedback[0] + ' a ' + lim.sem_feedback[1] + ' dias. Padrão: 14.</span><span class="ld-err" data-erro-de="dias_sem_feedback"></span></div></div>'
      + '<div class="ld-note err" role="alert" id="cfTopo" hidden></div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px"><button type="submit" class="ld-btn pri" id="cfSalvar">Salvar preferências</button><button type="button" class="ld-btn" id="cfPadrao">Restaurar padrão</button></div></form></article>'
      + '<div style="display:flex;flex-direction:column;gap:20px">'
      + '<article class="lp-card" aria-labelledby="cfPfH"><div class="lp-card-h"><h2 id="cfPfH">Seu perfil</h2></div>'
      + '<p style="margin:0 0 4px;font-size:15px;font-weight:700">' + E($('lpNome').textContent) + '</p><p style="margin:0;font-size:13px;color:var(--muted)">Líder da equipe ' + E(LP.boot.equipe) + '</p>'
      + '</article>'
      + '<article class="lp-card" aria-labelledby="cfAtH"><div class="lp-card-h"><h2 id="cfAtH">Atalhos de teclado</h2></div><ul class="lp-atalhos">'
      + ATALHOS.map(a => '<li><span>' + E(a[0]) + '</span><kbd>' + E(a[1]) + '</kbd></li>').join('') + '</ul></article></div></div>';

    const form = $('cfForm');
    $('cfPadrao').addEventListener('click', () => {
      form.querySelectorAll('[data-alerta]').forEach(c => { c.checked = true; });
      form.elements.limite_dias_sem_venda.value = 3; form.elements.dias_sem_feedback.value = 14;
      LD.toast('Valores padrão carregados — clique em Salvar para aplicar.', 'ok');
    });
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const botao = $('cfSalvar'); botao.disabled = true; $('cfTopo').hidden = true;
      const alertas = {}; form.querySelectorAll('[data-alerta]').forEach(c => { alertas[c.dataset.alerta] = c.checked; });
      const r2 = await LP.api('lider_preferencias_salvar', { limite_dias_sem_venda: form.elements.limite_dias_sem_venda.value.trim(), dias_sem_feedback: form.elements.dias_sem_feedback.value.trim(), alertas });
      botao.disabled = false;
      if (r2 && r2.success) {
        LD.mostrarErros(form, {});
        LD.toast('Preferências salvas.', 'ok');
        LP.carregarDash(true);
        return;
      }
      const marcou = LD.mostrarErros(form, (r2 && r2.errors) || {});
      if (!marcou) { $('cfTopo').textContent = (r2 && r2.message) || 'Não foi possível salvar.'; $('cfTopo').hidden = false; }
    });
  }

  async function carregar() {
    const meu = ++est.seq;
    if (!est.carregou) $('cfView').innerHTML = LD.skeleton();
    const r = await LP.api('lider_preferencias', {});
    if (meu !== est.seq) return;
    if (!r || !r.success) { $('cfView').innerHTML = LD.erroTela((r && r.message) || '', 'cfRetry'); const b = $('cfRetry'); if (b) b.onclick = carregar; return; }
    est.carregou = true; desenhar(r);
  }

  LP.mod.config = { mostrar() { if (!est.carregou) carregar(); } };
})();
