/* closer-auth.js — helpers das páginas públicas do Portal do Closer (cadastro, login, recuperação) */
(function () {
  const csrf = () => (document.querySelector('meta[name="csrf"]') || {}).content || '';

  // POST JSON em closer-api.php (com o token CSRF da página). Sempre devolve {success, message, ...}
  window.crApi = async function (action, data) {
    try {
      const r = await fetch(API_CLOSER_URL, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify(Object.assign({ action: action }, data || {}))
      });
      const j = await r.json().catch(() => null);
      if (!j) return { success: false, message: 'Resposta inesperada do servidor. Tente de novo.' };
      if (j.code === 'csrf') { setTimeout(() => location.reload(), 1400); }
      return j;
    } catch (e) {
      return { success: false, message: 'Sem conexão. Verifique sua internet e tente de novo.' };
    }
  };

  // Caixa de aviso (kind: err | warn | ok | info)
  window.crAlert = function (id, kind, msg) {
    const el = document.getElementById(id); if (!el) return;
    el.className = 'au-alert ' + kind;
    el.querySelector('[data-msg]').textContent = msg;
    void el.offsetWidth; el.classList.add('show');
  };
  window.crAlertHide = function (id) { const el = document.getElementById(id); if (el) el.classList.remove('show'); };

  // Erro embaixo do campo (aria-invalid + texto)
  window.crFieldErr = function (inputId, msg) {
    const inp = document.getElementById(inputId); if (!inp) return;
    inp.setAttribute('aria-invalid', 'true');
    const e = document.getElementById(inputId + 'Err'); if (e) e.textContent = msg;
    inp.focus();
  };
  window.crClearErrs = function (root) {
    (root || document).querySelectorAll('[aria-invalid]').forEach(i => i.removeAttribute('aria-invalid'));
    (root || document).querySelectorAll('.au-err').forEach(e => { e.textContent = ''; });
  };

  window.crBusy = function (btn, on, label) {
    if (on) { btn.dataset.l = btn.textContent; btn.disabled = true; btn.textContent = label || 'Aguarde…'; }
    else { btn.disabled = false; btn.textContent = btn.dataset.l || btn.textContent; }
  };

  // Mostrar/ocultar senha
  document.querySelectorAll('[data-eye]').forEach(b => b.addEventListener('click', () => {
    const inp = document.getElementById(b.dataset.eye), show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    b.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
    b.setAttribute('aria-pressed', String(show));
  }));

  // Limpa o erro do campo assim que o usuário volta a digitar
  document.addEventListener('input', e => {
    const t = e.target; if (t && t.getAttribute && t.getAttribute('aria-invalid')) {
      t.removeAttribute('aria-invalid'); const er = document.getElementById(t.id + 'Err'); if (er) er.textContent = '';
    }
  });

  window.crEyeSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  document.querySelectorAll('[data-eye]').forEach(b => { b.innerHTML = window.crEyeSvg; });
})();
