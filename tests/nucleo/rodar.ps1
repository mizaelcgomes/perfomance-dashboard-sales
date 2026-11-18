<#
  rodar.ps1 — testes do núcleo MVC (fase 1), só no ambiente LOCAL de teste

  Uso:   .\tests\nucleo\rodar.ps1

  O que faz:
    1. garante o ambiente de teste (MySQL + php -S 8787 do sistema atual)
    2. sobe dois servidores só para a pasta public/: 8788 (núcleo na raiz do site) e 8789 (núcleo "montado em /nova", como na HostGator)
       com a mesma pasta de sessões do ambiente de teste, para provar que a sessão e o token CSRF são compartilhados com o sistema antigo
    3. roda os testes de unidade (tests\nucleo\unit.php) e os testes HTTP (tests\nucleo\http.js)
    4. desliga os servidores que ele ligou e limpa o controle de tentativas (storage\rate-limit)
  Sai com código diferente de zero se qualquer teste falhar.
#>
$ErrorActionPreference = 'Continue'
$proj   = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$testdb = Join-Path $env:LOCALAPPDATA 'dash-vendas-testdb'
$start  = Join-Path $testdb 'start-test-env.ps1'
$sess   = Join-Path $testdb 'sessions'
$php    = 'C:\php\php.exe'
$ext    = 'C:\php\ext'
$router = Join-Path $PSScriptRoot 'router-local.php'
$publico = Join-Path $proj 'public'

function Test-Porta([int]$p) { [bool](Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue) }
function Iniciar-Servidor([int]$porta, [string]$base) {
  if (Test-Porta $porta) { Write-Host "porta $porta já está em uso: reutilizando"; return $null }
  $env:NUCLEO_BASE = $base
  $p = Start-Process -FilePath $php -PassThru -WindowStyle Hidden -ArgumentList @(
    '-d', "extension_dir=$ext", '-d', 'extension=pdo_mysql', '-d', 'display_errors=0', '-d', "session.save_path=$sess",
    '-S', "127.0.0.1:$porta", '-t', "`"$publico`"", "`"$router`"")
  $env:NUCLEO_BASE = ''
  for ($i = 0; $i -lt 15 -and -not (Test-Porta $porta); $i++) { Start-Sleep -Milliseconds 500 }
  return $p
}

if (-not (Test-Porta 8787)) { Write-Host 'Subindo o ambiente de teste...'; & $start | Out-Null; Start-Sleep -Seconds 3 }
if (-not (Test-Porta 8787) -or -not (Test-Porta 3306)) { Write-Host 'O ambiente de teste (MySQL 3306 + php 8787) não subiu.'; exit 2 }

$servidores = @()
$codigo = 0
try {
  $servidores += Iniciar-Servidor 8788 ''
  $servidores += Iniciar-Servidor 8789 '/nova'
  if (-not (Test-Porta 8788) -or -not (Test-Porta 8789)) { Write-Host 'Os servidores do núcleo (8788/8789) não subiram.'; exit 2 }

  Write-Host '== testes de unidade (PHP)'
  & $php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $PSScriptRoot 'unit.php') | Out-Host
  $u = [int]$LASTEXITCODE

  Write-Host "`n== testes do módulo Relatórios (PHP, dados sintéticos num banco descartável)"
  & $php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $PSScriptRoot 'relatorios.php') | Out-Host
  if ([int]$LASTEXITCODE -ne 0 -and $u -eq 0) { $u = [int]$LASTEXITCODE }

  Write-Host "`n== testes da TV (PHP, dados sintéticos num banco descartável; com GD para os testes de imagem)"
  & $php -d "extension_dir=$ext" -d extension=pdo_mysql -d extension=gd (Join-Path $PSScriptRoot 'tv.php') | Out-Host
  if ([int]$LASTEXITCODE -ne 0 -and $u -eq 0) { $u = [int]$LASTEXITCODE }

  Write-Host "`n== testes dos rankings (PHP, dados sintéticos num banco descartável; Meta Ads simulado, sem rede)"
  & $php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $PSScriptRoot 'ranking.php') | Out-Host
  if ([int]$LASTEXITCODE -ne 0 -and $u -eq 0) { $u = [int]$LASTEXITCODE }

  Write-Host "`n== testes do módulo do líder, fase L1 (PHP, banco descartável; acesso, escopo e migração)"
  & $php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $proj 'tests\lider\escopo.php') | Out-Host
  if ([int]$LASTEXITCODE -ne 0 -and $u -eq 0) { $u = [int]$LASTEXITCODE }

  Write-Host "`n== testes do módulo do líder, fase L2 (PHP, banco descartável; visão do time, resumo do admin e rotas)"
  & $php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $proj 'tests\lider\visao.php') | Out-Host
  if ([int]$LASTEXITCODE -ne 0 -and $u -eq 0) { $u = [int]$LASTEXITCODE }

  Write-Host "`n== testes do módulo do líder, fase L3 (PHP, banco descartável; registros, 'Meus feedbacks', CSRF, isolamento e atividades dos líderes)"
  & $php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $proj 'tests\lider\registros.php') | Out-Host
  if ([int]$LASTEXITCODE -ne 0 -and $u -eq 0) { $u = [int]$LASTEXITCODE }
  & $php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $proj 'tests\lider\atividades.php') | Out-Host
  if ([int]$LASTEXITCODE -ne 0 -and $u -eq 0) { $u = [int]$LASTEXITCODE }

  Write-Host "`n== testes do módulo do líder, fase L4 (PHP, banco descartável; Dashboard, Equipe, Agenda, busca, preferências e isolamento)"
  & $php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $proj 'tests\lider\portal.php') | Out-Host
  if ([int]$LASTEXITCODE -ne 0 -and $u -eq 0) { $u = [int]$LASTEXITCODE }

  Write-Host "`n== testes HTTP (Node)"
  Push-Location $proj
  & node (Join-Path $PSScriptRoot 'http.js') | Out-Host
  $h = [int]$LASTEXITCODE
  Pop-Location

  $codigo = if ($u -ne 0) { $u } elseif ($h -ne 0) { $h } else { 0 }
  Write-Host ("`nRESULTADO: unidade={0} http={1} → {2}" -f $(if ($u) { 'FALHOU' } else { 'ok' }), $(if ($h) { 'FALHOU' } else { 'ok' }), $(if ($codigo) { 'FALHOU' } else { 'TUDO OK' }))
} finally {
  foreach ($s in $servidores) { if ($s) { Stop-Process -Id $s.Id -Force -ErrorAction SilentlyContinue } }
  Get-ChildItem (Join-Path $proj 'storage\rate-limit') -Filter *.json -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
}
exit $codigo
