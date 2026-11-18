<?php
/**
 * Entrada do Portal do Closer (/closer/login): e-mail + senha, "lembrar de mim" por 7 dias. A página inteira; o /closer/login é só a casca.
 * @var \App\Core\View $v
 * @var string $csrf     token CSRF do portal (vai no cabeçalho de cada chamada ao closer-api.php)
 * @var string $logo     HTML da marca (brand_logo_html do site)
 * @var string $rodape   HTML do rodapé do site (_footer.php)
 * @var bool   $senhaRedefinida  veio de "senha redefinida" (?ok=senha)
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
<title>Entrar – Portal do Closer</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/footer.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/closer/auth.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/brand.css')) ?>">
</head>
<body>
<main class="au-main">
  <div class="au-card">
    <div class="au-head">
      <?= $logo ?>
      <h1>Portal do Closer</h1>
      <p>Entre para registrar suas ações do dia.</p>
    </div>

    <form class="au-form" id="loginForm" novalidate>
      <?php if (!empty($senhaRedefinida)): ?><div class="au-alert ok show" role="status"><?= $ic('check') ?><span>Senha redefinida! Entre com a nova senha.</span></div><?php endif; ?>
      <div class="au-alert err" id="loginAlert" role="alert"><span id="loginAlertIc" aria-hidden="true"><span data-ic="alert"><?= $ic('alert') ?></span><span data-ic="clock" hidden><?= $ic('clock') ?></span></span><span data-msg></span></div>

      <div class="au-fg"><label for="email">E-mail</label>
        <input class="au-in" id="email" type="email" maxlength="150" autocomplete="username" inputmode="email" placeholder="voce@email.com" required aria-describedby="emailErr">
        <div class="au-err" id="emailErr" aria-live="polite"></div></div>

      <div class="au-fg"><label for="senha">Senha</label>
        <div class="au-pw"><input class="au-in" id="senha" type="password" maxlength="72" autocomplete="current-password" placeholder="Sua senha" required aria-describedby="senhaErr">
          <button type="button" class="au-eye" data-eye="senha" aria-label="Mostrar senha" aria-pressed="false"></button></div>
        <div class="au-err" id="senhaErr" aria-live="polite"></div></div>

      <div class="au-row">
        <label class="au-check"><input type="checkbox" id="lembrar"> Lembrar de mim por 7 dias</label>
        <a class="au-link" href="/closer/recuperar">Esqueci minha senha</a>
      </div>

      <button class="au-btn" id="loginBtn" type="submit">Entrar</button>
    </form>

    <div class="au-foot">
      <span>Ainda não tem acesso? <a class="au-link" href="/closer/cadastro">Cadastre-se</a></span>
    </div>
  </div>
</main>
<?= $rodape ?>
<script src="<?= $v->e($v->asset('js/closer/auth.js')) ?>"></script>
<script>
const $ = id => document.getElementById(id);
$('loginForm').addEventListener('submit', async e => {
  e.preventDefault();
  crClearErrs($('loginForm')); crAlertHide('loginAlert');
  const email = $('email').value.trim(), senha = $('senha').value;
  if (!email) return crFieldErr('email', 'Informe seu e-mail.');
  if (!senha) return crFieldErr('senha', 'Informe sua senha.');
  const btn = $('loginBtn'); crBusy(btn, true, 'Entrando…');
  const r = await crApi('login', { email, senha, lembrar: $('lembrar').checked });
  if (r.success) { location.href = r.redirect || '/closer'; return; }
  crBusy(btn, false);
  // pendente = aviso (amarelo, não é erro seu); rejeitado/desativado = vermelho
  $('loginAlertIc').querySelector('[data-ic="clock"]').hidden = r.code !== 'pendente';
  $('loginAlertIc').querySelector('[data-ic="alert"]').hidden = r.code === 'pendente';
  crAlert('loginAlert', r.code === 'pendente' ? 'warn' : 'err', r.message || 'Não foi possível entrar.');
  if (!r.code) $('senha').select();
});
</script>
</body>
</html>
