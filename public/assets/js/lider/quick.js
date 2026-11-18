/*
 * Portal do Líder: o Registro de Ação Rápida, o cartão no topo do Meu dia (Ctrl+N leva até ele). É o mesmo registro do portal do closer, com o mesmo comportamento:
 * digite o número (máscara (XX) XXXXX-XXXX), clique no tipo (Ligação, Follow-up ou Lead Quente) e salva na hora, com as mesmas regras de telefone.
 * O líder também é closer: estas ações contam na produtividade dele, como as que ele registra no portal do closer.
 * Dois cliques seguidos no mesmo tipo com o mesmo número só enviam uma vez (enquanto o envio anterior não voltou).
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP) return;
  const $ = id => document.getElementById(id);
  const num = $('qfNum'), err = $('qfErr');
  const PH_MSG = 'Telefone inválido. Use DDD + número, ex.: (11) 98765-4321.';

  // as mesmas regras do servidor (cr_phone_valid em closer-lib.php)
  function valido(d) {
    if (d.length !== 10 && d.length !== 11) return false;
    const ddd = +d.slice(0, 2); if (ddd < 11 || ddd > 99) return false;
    if (d.length === 11 && d[2] !== '9') return false;
    if (d.length === 10 && (d[2] === '0' || d[2] === '1')) return false;
    if (/^(\d)\1+$/.test(d.slice(2))) return false;
    return true;
  }
  function erro(msg) {
    if (msg) { num.setAttribute('aria-invalid', 'true'); err.textContent = msg; num.focus(); } else { num.removeAttribute('aria-invalid'); err.textContent = ''; }
  }
  // máscara mantendo o cursor no mesmo dígito (editar no meio não pula para o fim)
  num.addEventListener('input', () => {
    const pos = num.selectionStart, antes = (num.value.slice(0, pos).match(/\d/g) || []).length;
    const f = LD.fmtFone(LD.digitosFone(num.value)); num.value = f;
    let n = 0, p = 0; if (antes) { for (p = 0; p < f.length && n < antes; p++) if (/\d/.test(f[p])) n++; }
    try { num.setSelectionRange(p, p); } catch (e) { /* campo sem seleção */ }
  });
  $('qfPaste').addEventListener('click', async () => {
    try {
      const d = LD.digitosFone(await navigator.clipboard.readText());
      if (!d) return LD.toast('Nada com número na área de transferência.', 'err');
      num.value = LD.fmtFone(d); erro(''); num.focus();
    } catch (e) { LD.toast('Não consegui acessar a área de transferência — cole com ' + (LD.mobile() ? 'toque longo' : 'Ctrl+V') + ' no campo.', 'err'); }
  });
  num.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    if (!valido(LD.digitosFone(num.value))) return erro(PH_MSG);
    $('qfTipos').querySelector('.lp-qtb').focus();
  });

  const enviando = new Set();
  async function salvar(telefone, tipo) {
    const chave = tipo + '|' + telefone;
    if (enviando.has(chave)) return null;
    enviando.add(chave);
    try { return await LP.api('acao_criar', { telefone, tipo }); } finally { enviando.delete(chave); }
  }
  $('qfTipos').addEventListener('click', async e => {
    const b = e.target.closest('.lp-qtb'); if (!b) return;
    const d = LD.digitosFone(num.value); erro('');
    if (!valido(d)) return erro(PH_MSG);
    const r = await salvar(d, b.dataset.t);
    if (r && r.success) {
      LD.toast('Ação registrada!', 'ok');
      try { if (navigator.vibrate) navigator.vibrate(40); } catch (x) { /* sem vibração */ }
      if (LD.digitosFone(num.value) === d) { num.value = ''; if (LD.mobile() || window.matchMedia('(max-width:960px)').matches) num.blur(); else num.focus(); }
      LP.acoesMudaram(r.resumo);       // histórico, Meu dia e ranking do Dashboard
      if (LP.fecharFolha) LP.fecharFolha();   // no celular do closer o registro sobe como uma folha: fecha ao salvar
    } else if (r) {
      r.field === 'telefone' ? erro(r.message) : LD.toast(r.message || 'Não foi possível salvar.', 'err');
    }
  });
})();
