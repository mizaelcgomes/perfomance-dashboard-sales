<?php
/**
 * Cadastro público do closer (/closer/cadastro): cria o pedido de acesso como PENDENTE. Aceita ?convite=TOKEN (link gerado pelo admin no card do
 * closer): o nome e a equipe já vêm do cadastro. A página inteira; o /closer/cadastro é só a casca.
 * @var \App\Core\View $v
 * @var string $csrf
 * @var string $logo
 * @var string $rodape
 * @var array<int, array<string, mixed>> $equipes   id, nome, cor
 * @var string $token            token do convite da URL (40 hex) ou ''
 * @var array<string, mixed>|null $convite   o convite válido (nome, equipe_id) ou null
 * @var bool $conviteInvalido    veio token na URL, mas o convite não vale
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
<title>Cadastro de Closer – TOP CLOSERS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/footer.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/closer/auth.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/brand.css')) ?>">
</head>
<body>
<main class="au-main">
  <div class="au-card">
    <div id="formView">
      <div class="au-head">
        <?= $logo ?>
        <h1>Cadastro de Closer</h1>
        <p>Crie seu acesso ao Portal do Closer. O administrador aprova antes da liberação.</p>
      </div>

      <?php if ($conviteInvalido): ?>
        <div class="au-alert warn show" role="alert" style="margin-bottom:14px"><?= $ic('alert') ?><span>Este convite é inválido ou expirou. Você pode se cadastrar normalmente abaixo ou pedir um novo link ao administrador.</span></div>
      <?php elseif ($convite): ?>
        <div class="au-alert info show" style="margin-bottom:14px"><?= $ic('mail') ?><span>Convite para <b><?= $v->e($convite['nome']) ?></b>. Sua equipe já está definida.</span></div>
      <?php endif; ?>

      <form class="au-form" id="regForm" novalidate autocomplete="on">
        <div class="au-alert err" id="regAlert" role="alert"><?= $ic('alert') ?><span data-msg></span></div>
        <input type="hidden" id="convite" value="<?= $convite ? $v->e($token) : '' ?>">

        <div class="au-fg"><label for="nome">Nome completo</label>
          <input class="au-in" id="nome" type="text" maxlength="100" autocomplete="name" placeholder="Como aparece no ranking" required value="<?= $convite ? $v->e($convite['nome']) : '' ?>" aria-describedby="nomeErr">
          <div class="au-err" id="nomeErr" aria-live="polite"></div></div>

        <div class="au-fg"><label for="email">E-mail</label>
          <input class="au-in" id="email" type="email" maxlength="150" autocomplete="email" inputmode="email" placeholder="voce@email.com" required aria-describedby="emailErr">
          <div class="au-err" id="emailErr" aria-live="polite"></div></div>

        <div class="au-fg"><label for="equipe_id">Equipe</label>
          <select class="au-in" id="equipe_id" required <?= $convite ? 'disabled' : '' ?> aria-describedby="equipe_idErr">
            <option value="">Selecione sua equipe…</option>
            <?php foreach ($equipes as $e): ?>
              <option value="<?= (int) $e['id'] ?>"<?= $convite && (int) $convite['equipe_id'] === (int) $e['id'] ? ' selected' : '' ?>><?= $v->e($e['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="au-err" id="equipe_idErr" aria-live="polite"></div></div>

        <div class="au-fg"><label for="senha">Senha</label>
          <div class="au-pw"><input class="au-in" id="senha" type="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Mínimo de 8 caracteres" required aria-describedby="senhaErr">
            <button type="button" class="au-eye" data-eye="senha" aria-label="Mostrar senha" aria-pressed="false"></button></div>
          <div class="au-err" id="senhaErr" aria-live="polite"></div></div>

        <div class="au-fg"><label for="senha2">Confirmar senha</label>
          <div class="au-pw"><input class="au-in" id="senha2" type="password" maxlength="72" autocomplete="new-password" placeholder="Repita a senha" required aria-describedby="senha2Err">
            <button type="button" class="au-eye" data-eye="senha2" aria-label="Mostrar senha" aria-pressed="false"></button></div>
          <div class="au-err" id="senha2Err" aria-live="polite"></div></div>

        <button class="au-btn" id="regBtn" type="submit">Criar meu acesso</button>
      </form>

      <div class="au-foot"><span>Já tem acesso? <a class="au-link" href="/closer/login">Entrar</a></span></div>
    </div>

    <div class="au-done" id="doneView" role="status" aria-live="polite">
      <div class="big" aria-hidden="true">⏳</div>
      <h2>Cadastro realizado!</h2>
      <p>Aguarde a aprovação do administrador. Assim que liberar, você entra com o e-mail e a senha que acabou de criar.</p>
      <a class="au-btn ghost" href="/closer/login" style="text-decoration:none;display:inline-block;padding:12px 26px">Ir para o login</a>
    </div>
  </div>
</main>
<?= $rodape ?>
<script src="<?= $v->e($v->asset('js/closer/auth.js')) ?>"></script>
<script>
const $ = id => document.getElementById(id);
$('regForm').addEventListener('submit', async e => {
  e.preventDefault();
  crClearErrs($('regForm')); crAlertHide('regAlert');
  const d = { nome: $('nome').value.trim(), email: $('email').value.trim(), equipe_id: $('equipe_id').value, senha: $('senha').value, senha2: $('senha2').value, convite: $('convite').value };
  // validação rápida no navegador (o servidor valida de novo)
  if (d.nome.length < 3) return crFieldErr('nome', 'Informe seu nome completo.');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) return crFieldErr('email', 'Informe um e-mail válido.');
  if (!d.convite && !d.equipe_id) return crFieldErr('equipe_id', 'Selecione sua equipe.');
  if (d.senha.length < 8) return crFieldErr('senha', 'A senha precisa ter pelo menos 8 caracteres.');
  if (d.senha !== d.senha2) return crFieldErr('senha2', 'As senhas não conferem.');
  const btn = $('regBtn'); crBusy(btn, true, 'Enviando…');
  const r = await crApi('register', d);
  crBusy(btn, false);
  if (r.success) { $('formView').style.display = 'none'; $('doneView').classList.add('show'); return; }
  if (r.field && $(r.field)) {
    if (r.code === 'email_existe') { crFieldErr('email', r.message); crAlert('regAlert', 'warn', 'Este e-mail já tem cadastro. Use "Entrar" ou recupere a senha no login.'); }
    else crFieldErr(r.field, r.message);
  } else crAlert('regAlert', 'err', r.message || 'Não foi possível concluir o cadastro.');
});
</script>
</body>
</html>
