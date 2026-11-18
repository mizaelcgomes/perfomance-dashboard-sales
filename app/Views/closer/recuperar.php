<?php
/**
 * Recuperação de senha do closer (/closer/recuperar), SEM e-mail:
 *   1) o closer pede a redefinição (o pedido aparece no card dele em Equipes);
 *   2) o admin gera um token e envia (WhatsApp etc.);
 *   3) o closer usa o token aqui para criar a nova senha.
 * A página inteira; o /closer/recuperar é só a casca.
 * @var \App\Core\View $v
 * @var string $csrf
 * @var string $logo
 * @var string $rodape
 * @var string $aba   'pedir' ou 'token'
 */
$ic = static fn (string $nome): string => \App\Services\IconesPortal::svg($nome);
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<?= $v->parcial('partials/api') ?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf" content="<?= $v->e($csrf) ?>">
<meta name="robots" content="noindex,nofollow">
<title>Recuperar senha – Portal do Closer</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/footer.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/closer/auth.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/brand.css')) ?>">
<style>.au-token{letter-spacing:3px;text-transform:uppercase;font-weight:700;font-size:17px;text-align:center;}</style>
</head>
<body>
<main class="au-main">
  <div class="au-card">
    <div class="au-head">
      <?= $logo ?>
      <h1>Recuperar senha</h1>
      <p>Peça um token ao administrador e use-o para criar uma nova senha.</p>
    </div>

    <div class="au-tabs" role="tablist" aria-label="Etapas da recuperação">
      <button class="au-tab" role="tab" id="tabPedir" aria-controls="paneP" aria-selected="<?= $aba === 'pedir' ? 'true' : 'false' ?>" type="button">1 · Pedir token</button>
      <button class="au-tab" role="tab" id="tabToken" aria-controls="paneT" aria-selected="<?= $aba === 'token' ? 'true' : 'false' ?>" type="button">2 · Tenho um token</button>
    </div>

    <!-- 1) pedir ao administrador -->
    <form class="au-form" id="paneP" role="tabpanel" aria-labelledby="tabPedir" novalidate <?= $aba === 'pedir' ? '' : 'hidden' ?>>
      <div class="au-alert err" id="pAlert" role="alert"><?= $ic('alert') ?><span data-msg></span></div>
      <div class="au-alert ok" id="pOk" role="status"><?= $ic('check') ?><span data-msg></span></div>
      <div class="au-fg"><label for="pEmail">E-mail do cadastro</label>
        <input class="au-in" id="pEmail" type="email" maxlength="150" autocomplete="email" inputmode="email" placeholder="voce@email.com" required aria-describedby="pEmailErr">
        <div class="au-err" id="pEmailErr" aria-live="polite"></div></div>
      <button class="au-btn" id="pBtn" type="submit">Avisar o administrador</button>
      <p class="au-hint" style="text-align:center">O administrador vai gerar um token e te enviar por mensagem. Depois, siga para a etapa 2.</p>
    </form>

    <!-- 2) usar o token -->
    <form class="au-form" id="paneT" role="tabpanel" aria-labelledby="tabToken" novalidate <?= $aba === 'token' ? '' : 'hidden' ?>>
      <div class="au-alert err" id="tAlert" role="alert"><?= $ic('alert') ?><span data-msg></span></div>
      <div class="au-fg"><label for="tEmail">E-mail do cadastro</label>
        <input class="au-in" id="tEmail" type="email" maxlength="150" autocomplete="username" inputmode="email" placeholder="voce@email.com" required aria-describedby="tEmailErr">
        <div class="au-err" id="tEmailErr" aria-live="polite"></div></div>
      <div class="au-fg"><label for="tToken">Token recebido</label>
        <input class="au-in au-token" id="tToken" type="text" maxlength="14" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXXX-XXXXX" required aria-describedby="tTokenErr">
        <div class="au-err" id="tTokenErr" aria-live="polite"></div></div>
      <div class="au-fg"><label for="tSenha">Nova senha</label>
        <div class="au-pw"><input class="au-in" id="tSenha" type="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Mínimo de 8 caracteres" required aria-describedby="tSenhaErr">
          <button type="button" class="au-eye" data-eye="tSenha" aria-label="Mostrar senha" aria-pressed="false"></button></div>
        <div class="au-err" id="tSenhaErr" aria-live="polite"></div></div>
      <div class="au-fg"><label for="tSenha2">Confirmar nova senha</label>
        <div class="au-pw"><input class="au-in" id="tSenha2" type="password" maxlength="72" autocomplete="new-password" placeholder="Repita a senha" required aria-describedby="tSenha2Err">
          <button type="button" class="au-eye" data-eye="tSenha2" aria-label="Mostrar senha" aria-pressed="false"></button></div>
        <div class="au-err" id="tSenha2Err" aria-live="polite"></div></div>
      <button class="au-btn" id="tBtn" type="submit">Redefinir senha</button>
    </form>

    <div class="au-foot"><a class="au-link" href="/closer/login">← Voltar ao login</a></div>
  </div>
</main>
<?= $rodape ?>
<script src="<?= $v->e($v->asset('js/closer/auth.js')) ?>"></script>
<script>
const $ = id => document.getElementById(id);
function setTab(t){
  const p = t === 'pedir';
  $('tabPedir').setAttribute('aria-selected', p); $('tabToken').setAttribute('aria-selected', !p);
  $('paneP').hidden = !p; $('paneT').hidden = p;
  history.replaceState(null, '', p ? '/closer/recuperar' : '/closer/recuperar?tab=token');
}
$('tabPedir').onclick = () => setTab('pedir'); $('tabToken').onclick = () => setTab('token');

$('paneP').addEventListener('submit', async e => {
  e.preventDefault(); crClearErrs($('paneP')); crAlertHide('pAlert'); crAlertHide('pOk');
  const email = $('pEmail').value.trim();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return crFieldErr('pEmail', 'Informe um e-mail válido.');
  const btn = $('pBtn'); crBusy(btn, true, 'Enviando…');
  const r = await crApi('forgot', { email });
  crBusy(btn, false);
  if (r.success) { crAlert('pOk', 'ok', r.message); $('tEmail').value = email; }
  else crAlert('pAlert', 'err', r.message || 'Não foi possível enviar o pedido.');
});

// aceita o token com ou sem hífen/espaços/minúsculas; mostra sempre XXXXX-XXXXX
$('tToken').addEventListener('input', () => {
  const v = $('tToken').value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 10);
  $('tToken').value = v.length > 5 ? v.slice(0, 5) + '-' + v.slice(5) : v;
});
$('paneT').addEventListener('submit', async e => {
  e.preventDefault(); crClearErrs($('paneT')); crAlertHide('tAlert');
  const d = { email: $('tEmail').value.trim(), token: $('tToken').value, senha: $('tSenha').value, senha2: $('tSenha2').value };
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) return crFieldErr('tEmail', 'Informe o e-mail do cadastro.');
  if (d.token.replace(/[^A-Za-z0-9]/g, '').length < 10) return crFieldErr('tToken', 'O token tem 10 caracteres (XXXXX-XXXXX).');
  if (d.senha.length < 8) return crFieldErr('tSenha', 'A senha precisa ter pelo menos 8 caracteres.');
  if (d.senha !== d.senha2) return crFieldErr('tSenha2', 'As senhas não conferem.');
  const btn = $('tBtn'); crBusy(btn, true, 'Salvando…');
  const r = await crApi('reset', d);
  if (r.success) { location.href = r.redirect || '/closer/login?ok=senha'; return; }
  crBusy(btn, false);
  const map = { email: 'tEmail', token: 'tToken', senha: 'tSenha', senha2: 'tSenha2' };
  if (r.field && map[r.field]) crFieldErr(map[r.field], r.message); else crAlert('tAlert', 'err', r.message || 'Não foi possível redefinir a senha.');
});
</script>
</body>
</html>
