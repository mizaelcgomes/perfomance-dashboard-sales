/*
 * Portal do Closer, página Configurações: o perfil (nome, equipe e líder, só leitura), o tema (escuro ou claro, guardado neste navegador) e os atalhos
 * de teclado. O tema vale na hora: o atributo data-tema do <html> troca as cores (closer.css); o script do cabeçalho da página já o aplica antes de
 * desenhar, para a página não "piscar".
 */
(function () {
  'use strict';
  const LD = window.LD, LP = window.LP;
  if (!LD || !LP || !LP.closer) return;
  const E = LD.esc, $ = id => document.getElementById(id);
  const ATALHOS = [['Busca global', 'Ctrl K'], ['Registrar ação rápida', 'Ctrl N ou Alt N'], ['Fechar painéis e menus', 'Esc']];
  const CHAVE = 'cpTema';

  function aplicar(tema) {
    document.documentElement.dataset.tema = tema;
    const m = document.querySelector('meta[name="theme-color"]'); if (m) m.content = tema === 'claro' ? '#f4efe3' : '#050505';
  }
  function desenhar() {
    const tema = document.documentElement.dataset.tema === 'claro' ? 'claro' : 'escuro', b = LP.boot;
    $('cfView').innerHTML = '<div class="lp-cfg">'
      + '<article class="lp-card" aria-labelledby="cfPfH"><div class="lp-card-h"><h2 id="cfPfH">Meu perfil</h2></div>'
      + '<dl class="cp-perfil"><div><dt>Nome</dt><dd>' + E($('lpNome').textContent) + '</dd></div><div><dt>Equipe</dt><dd>' + E(b.equipe) + '</dd></div><div><dt>Líder</dt><dd>' + E(b.lider || 'Sem líder definido') + '</dd></div></dl>'
      + '<p class="ld-help">Para mudar nome, equipe ou foto, fale com o seu líder ou com o administrador.</p></article>'
      + '<div style="display:flex;flex-direction:column;gap:20px"><article class="lp-card" aria-labelledby="cfTmH"><div class="lp-card-h"><h2 id="cfTmH">Tema</h2></div>'
      + '<div class="ld-seg cp-tema" id="cfTema" role="radiogroup" aria-label="Tema do portal">'
      + ['escuro', 'claro'].map(t => '<button type="button" role="radio" data-tema="' + t + '" aria-checked="' + (t === tema) + '" class="' + (t === tema ? 'active' : '') + '">' + (t === 'escuro' ? '🌙 Escuro' : '☀️ Claro') + '</button>').join('')
      + '</div><p class="ld-help">A escolha fica guardada neste navegador.</p></article>'
      + '<article class="lp-card" aria-labelledby="cfAtH"><div class="lp-card-h"><h2 id="cfAtH">Atalhos de teclado</h2></div><ul class="lp-atalhos">'
      + ATALHOS.map(a => '<li><span>' + E(a[0]) + '</span><kbd>' + E(a[1]) + '</kbd></li>').join('') + '</ul></article></div></div>';
    $('cfTema').addEventListener('click', e => {
      const x = e.target.closest('[data-tema]'); if (!x) return;
      aplicar(x.dataset.tema); LD.salvar(CHAVE, x.dataset.tema);
      document.querySelectorAll('#cfTema button').forEach(n => { const on = n === x; n.classList.toggle('active', on); n.setAttribute('aria-checked', String(on)); });
    });
  }
  LP.mod.config = { mostrar: desenhar };
})();
