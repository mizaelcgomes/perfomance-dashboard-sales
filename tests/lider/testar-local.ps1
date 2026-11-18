<#
  testar-local.ps1 — prepara o ambiente LOCAL para você experimentar o módulo do líder à mão. Nada daqui toca no servidor.

  Uso:
    .\tests\lider\testar-local.ps1            # prepara o banco, sobe o núcleo em 127.0.0.1:8788 e mostra o passo a passo
    .\tests\lider\testar-local.ps1 tirar      # tira a liderança da equipe de teste (o acesso do líder cai na hora)
    .\tests\lider\testar-local.ps1 devolver   # devolve a liderança
    .\tests\lider\testar-local.ps1 parar      # desliga o servidor do núcleo (8788)

  Por que dá certo: o cookie de login (PHPSESSID) não separa por porta. Você entra no portal do closer pela porta 8787 (sistema atual)
  e o núcleo na porta 8788 enxerga a mesma sessão, como acontece no servidor (mesmo domínio, mesma pasta de sessões).
#>
param([Parameter(Position = 0)][ValidateSet('subir', 'tirar', 'devolver', 'parar')][string]$Comando = 'subir')
$ErrorActionPreference = 'Stop'
$proj   = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$testdb = Join-Path $env:LOCALAPPDATA 'dash-vendas-testdb'
$start  = Join-Path $testdb 'start-test-env.ps1'
$sess   = Join-Path $testdb 'sessions'
$pidf   = Join-Path $testdb 'nucleo-8788.pid'
$php    = 'C:\php\php.exe'; $ext = 'C:\php\ext'
function Test-Porta([int]$p) { [bool](Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue) }
function Parar-Nucleo {
  if (Test-Path $pidf) { Stop-Process -Id ([int](Get-Content $pidf)) -Force -ErrorAction SilentlyContinue; Remove-Item $pidf -Force -ErrorAction SilentlyContinue }
}
function Preparar([string]$modo) { & $php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $PSScriptRoot 'preparar-local.php') $modo }

if ($Comando -eq 'parar') { Parar-Nucleo; Write-Host 'Servidor do núcleo (8788) desligado.'; exit 0 }

if (-not (Test-Porta 8787) -or -not (Test-Porta 3306)) { Write-Host 'Subindo o ambiente de teste...'; & $start | Out-Null; Start-Sleep -Seconds 3 }
if (-not (Test-Porta 8787) -or -not (Test-Porta 3306)) { Write-Host 'O ambiente de teste (MySQL 3306 + php 8787) não subiu.'; exit 2 }

if ($Comando -in 'tirar', 'devolver') {
  Preparar $Comando | Out-Host
  Write-Host ($(if ($Comando -eq 'tirar') { 'Liderança REMOVIDA. Recarregue http://127.0.0.1:8788/api/lider/escopo logado como líder: deve dar 403.' } else { 'Liderança DEVOLVIDA. Recarregue http://127.0.0.1:8788/api/lider/escopo logado como líder: deve dar 200.' }))
  exit 0
}

Write-Host '== preparando o banco local (migração + equipe, líder e closer de teste)'
Preparar 'preparar' | Out-Host

if (-not (Test-Porta 8788)) {
  $env:NUCLEO_BASE = ''
  $p = Start-Process -FilePath $php -PassThru -WindowStyle Hidden -ArgumentList @('-d', "extension_dir=$ext", '-d', 'extension=pdo_mysql', '-d', 'display_errors=0', '-d', "session.save_path=$sess",
    '-S', '127.0.0.1:8788', '-t', "`"$(Join-Path $proj 'public')`"", "`"$(Join-Path $proj 'tests\nucleo\router-local.php')`"")
  Set-Content $pidf $p.Id
  for ($i = 0; $i -lt 15 -and -not (Test-Porta 8788); $i++) { Start-Sleep -Milliseconds 500 }
}
if (-not (Test-Porta 8788)) { Write-Host 'O servidor do núcleo (8788) não subiu.'; exit 2 }

@"

================  PASSO A PASSO (no seu navegador)  ================
Contas de teste (só existem neste banco local; senha: senha1234)
   Líder : lider.teste@teste.com
   Closer: closer.teste@teste.com

1) Entre no portal como LÍDER:      http://127.0.0.1:8787/closer-login.php
2) Abra (mesma janela do navegador): http://127.0.0.1:8788/api/lider/escopo
      -> esperado: {"success":true,"equipe_id":N}                (você é líder: 200)
3) Saia (logout do portal) e entre como CLOSER; reabra o endereço do passo 2
      -> esperado: {"success":false,"message":"Acesso restrito a líderes de equipe."}   (403)
4) Sem login nenhum (janela anônima) no endereço do passo 2
      -> esperado: {"success":false,"message":"Não autenticado"}   (401)
5) Teste "tirar a liderança na hora": entre como LÍDER, deixe a aba do passo 2 aberta e rode
      .\tests\lider\testar-local.ps1 tirar        -> recarregue a aba: agora dá 403 (sem sair da sessão)
      .\tests\lider\testar-local.ps1 devolver     -> recarregue: volta a dar 200
6) PORTAL DO LÍDER (fase L4): logado como LÍDER, abra http://127.0.0.1:8787/closer.php  -> você cai direto em lider.php (sidebar, Dashboard, Equipe,
   Agenda, Registros, Configurações; o Registro de Ação Rápida fica sempre visível). O portal de closer dele fica em closer.php?portal=closer.
   O CLOSER comum continua no closer.php de sempre (e se abrir lider.php volta para lá).
7) Dados de exemplo para a tela ficar cheia (números, reuniões, feedbacks, treinamento):
      php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\lider\preparar-local.php semear
   closer parado há 5 dias (gera um alerta):  ...preparar-local.php contratos 5 1      apagar tudo isso:  ...preparar-local.php limpar

Para desligar o servidor do núcleo depois:  .\tests\lider\testar-local.ps1 parar
Atenção: a equipe e as contas de teste ficam no banco local até o próximo reset-local-db.ps1 (que recria o banco).
Se for rodar as suítes de teste (run-all, golden), rode o reset antes: as 2 pessoas extras mudam as contagens do seed.
===================================================================
"@ | Write-Host
