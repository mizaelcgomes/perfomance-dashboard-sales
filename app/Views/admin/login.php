<?php
/**
 * Login do admin (usuário e senha). A página inteira; o login em si é a ação "login" da API (ApiPorAcaoController).
 * @var \App\Core\View $v
 * @var string $logo   HTML da marca (Marca::logoHtml)
 * @var string $rodape HTML do rodapé do site
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<?= $v->parcial('partials/api') ?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - TOP CLOSERS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Cinzel:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/footer.css')) ?>">
<link rel="stylesheet" href="<?= $v->e($v->asset('css/brand.css')) ?>">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
body{background:#0a0a0a;color:#fff;height:100vh;display:flex;align-items:center;justify-content:center;background-image:radial-gradient(ellipse at 20% 30%,rgba(212,175,55,0.15) 0%,transparent 50%),radial-gradient(ellipse at 80% 70%,rgba(139,115,20,0.1) 0%,transparent 50%);}
.login-card{width:100%;max-width:420px;background:rgba(10,10,10,0.9);border:1px solid rgba(212,175,55,0.3);border-radius:24px;padding:40px 32px;backdrop-filter:blur(20px);box-shadow:0 20px 60px rgba(0,0,0,0.5);animation:slideIn 0.6s ease-out;}
@keyframes slideIn{from{opacity:0;transform:translateY(30px);}to{opacity:1;transform:translateY(0);}}
.login-header{text-align:center;margin-bottom:32px;}
.login-header h1{font-family:'Cinzel',serif;font-size:22px;font-weight:700;color:#d4af37;margin-bottom:6px;}
.login-header p{font-size:13px;color:rgba(212,175,55,0.6);}
.login-form{display:flex;flex-direction:column;gap:16px;}
.login-input-group{display:flex;flex-direction:column;gap:6px;}
.login-input-group label{font-size:11px;font-weight:600;color:rgba(212,175,55,0.7);text-transform:uppercase;letter-spacing:0.5px;}
.login-input{padding:12px 14px;background:rgba(26,26,26,0.8);border:1px solid rgba(212,175,55,0.3);border-radius:12px;color:#fff;font-size:14px;transition:0.3s;}
.login-input:focus{outline:none;border-color:#d4af37;box-shadow:0 0 0 3px rgba(212,175,55,0.2);}
.login-btn{padding:14px;background:linear-gradient(135deg,#d4af37,#fbbf24);border:none;border-radius:12px;color:#0a0a0a;font-size:14px;font-weight:700;cursor:pointer;transition:0.3s;box-shadow:0 4px 15px rgba(212,175,55,0.3);margin-top:8px;}
.login-btn:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(212,175,55,0.5);}
.login-btn:disabled{opacity:0.6;cursor:not-allowed;transform:none;}
.login-error{background:rgba(139,0,0,0.2);border:1px solid rgba(139,0,0,0.4);border-radius:10px;padding:10px 14px;font-size:12px;color:#ff6b6b;display:none;align-items:center;gap:8px;}
.login-error.show{display:flex;animation:shake 0.4s ease;}
@keyframes shake{0%,100%{transform:translateX(0);}25%{transform:translateX(-8px);}75%{transform:translateX(8px);}}
.login-footer{text-align:center;margin-top:20px;padding-top:20px;border-top:1px solid rgba(212,175,55,0.2);}
.login-footer a{color:rgba(212,175,55,0.7);font-size:12px;text-decoration:none;}
.login-footer a:hover{color:#ffd700;}
/* footer positioning for login (body is flex-centered) */
.site-footer{position:fixed;bottom:0;left:0;right:0;}
</style>
</head>
<body>
<div class="login-card">
  <div class="login-header">
    <?= $logo ?>
    <h1>Acesso Administrativo</h1>
    <p>Faça login para gerenciar o ranking</p>
  </div>
  <div class="login-form">
    <div class="login-error" id="loginError"><span>️</span><span id="loginErrorMsg">Erro</span></div>
    <div class="login-input-group"><label>Usuário</label><input type="text" class="login-input" id="loginUser" placeholder="Digite seu usuário" autocomplete="username"></div>
    <div class="login-input-group"><label>Senha</label><input type="password" class="login-input" id="loginPass" placeholder="Digite sua senha" autocomplete="current-password"></div>
    <button class="login-btn" id="loginBtn" onclick="doLogin()">Entrar</button>
  </div>
  <div class="login-footer"><a href="/">← Voltar ao Dashboard</a></div>
</div>
<script>
function showError(msg){document.getElementById('loginErrorMsg').textContent=msg;document.getElementById('loginError').classList.add('show');}
async function doLogin(){
  const user=document.getElementById('loginUser').value.trim(),pass=document.getElementById('loginPass').value,btn=document.getElementById('loginBtn');
  document.getElementById('loginError').classList.remove('show');
  if(!user||!pass){showError('Preencha usuário e senha');return;}
  btn.disabled=true;btn.textContent='Entrando...';
  try{
    const res=await fetch(API_URL+'?action=login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`user=${encodeURIComponent(user)}&pass=${encodeURIComponent(pass)}`}),
          data=await res.json();
    if(data.success){window.location.href='/admin';}
    else{showError(data.message||'Erro ao fazer login');btn.disabled=false;btn.textContent='Entrar';}
  }catch(e){showError('Erro de conexão');btn.disabled=false;btn.textContent='Entrar';}
}
document.getElementById('loginPass').addEventListener('keypress',e=>{if(e.key==='Enter')doLogin();});
document.getElementById('loginUser').addEventListener('keypress',e=>{if(e.key==='Enter')document.getElementById('loginPass').focus();});
</script>
<?= $rodape ?>
</body>
</html>