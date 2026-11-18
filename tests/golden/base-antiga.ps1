<#
  base-antiga.ps1 — guarda uma cópia do código ATUAL do projeto (a "linha de base" de uma fase) e a serve numa porta à parte,
  para o golden.js "diferencial" comparar, ao vivo e no mesmo banco, o código antigo com o novo.
  Só ambiente LOCAL. A cópia fica FORA do projeto: %LOCALAPPDATA%\dash-vendas-testdb\base-antiga

  Uso:
    .\tests\golden\base-antiga.ps1 criar      # copia o projeto como está agora e sobe o servidor em 127.0.0.1:8790
    .\tests\golden\base-antiga.ps1 subir      # só sobe o servidor da cópia que já existe
    .\tests\golden\base-antiga.ps1 parar      # desliga o servidor (a cópia fica)
    .\tests\golden\base-antiga.ps1 apagar     # desliga e apaga a cópia
  Rode "criar" ANTES de mexer no código da fase. A pasta uploads/ é compartilhada (junção) com o projeto; a pasta music/ é copiada
  (as chamadas que gravam música usam, cada servidor, a sua própria pasta).
#>
param([Parameter(Position = 0)][ValidateSet('criar', 'subir', 'parar', 'apagar')][string]$Comando = 'subir', [int]$Porta = 8790)
$ErrorActionPreference = 'Stop'
$proj  = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$testdb = Join-Path $env:LOCALAPPDATA 'dash-vendas-testdb'
$dest  = Join-Path $testdb 'base-antiga'
$sess  = Join-Path $testdb 'sessions'
$pidf  = Join-Path $testdb 'base-antiga.pid'
function Test-Porta([int]$p) { [bool](Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue) }
function Parar-Servidor {
  if (Test-Path $pidf) { $id = [int](Get-Content $pidf); Stop-Process -Id $id -Force -ErrorAction SilentlyContinue; Remove-Item $pidf -Force -ErrorAction SilentlyContinue }
  # se sobrou algo escutando na porta por conta própria, só desliga se for um php.exe
  Get-NetTCPConnection -LocalPort $Porta -State Listen -ErrorAction SilentlyContinue | ForEach-Object {
    $p = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue; if ($p -and $p.ProcessName -eq 'php') { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
  }
}
function Subir-Servidor {
  if (-not (Test-Path (Join-Path $dest 'api.php'))) { Write-Host 'Não há cópia da linha de base. Rode "criar".'; exit 2 }
  if (Test-Porta $Porta) { Write-Host "A porta $Porta já está em uso."; exit 2 }
  $p = Start-Process -FilePath 'C:\php\php.exe' -PassThru -WindowStyle Hidden -ArgumentList @('-d', 'extension_dir=C:\php\ext', '-d', 'extension=pdo_mysql', '-d', 'display_errors=0', '-d', "session.save_path=$sess", '-d', 'session.use_only_cookies=0', '-S', "127.0.0.1:$Porta", '-t', "`"$dest`"")
  Set-Content $pidf $p.Id
  for ($i = 0; $i -lt 15 -and -not (Test-Porta $Porta); $i++) { Start-Sleep -Milliseconds 500 }
  if (-not (Test-Porta $Porta)) { Write-Host 'O servidor da linha de base não subiu.'; exit 2 }
  Write-Host "linha de base no ar em http://127.0.0.1:$Porta/ (pasta: $dest)"
}
function Apagar-Copia {
  if (Test-Path "$dest\uploads") { cmd /c rmdir "$dest\uploads" 2>$null | Out-Null }   # remove só a junção, nunca o conteúdo
  if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
}

switch ($Comando) {
  'parar'  { Parar-Servidor; Write-Host 'servidor da linha de base desligado' }
  'apagar' { Parar-Servidor; Apagar-Copia; Write-Host 'cópia apagada' }
  'subir'  { Subir-Servidor }
  'criar'  {
    Parar-Servidor; Apagar-Copia
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    # tudo que o sistema precisa para rodar, menos o que é só de desenvolvimento
    & robocopy $proj $dest /E /NFL /NDL /NJH /NJS /NP /XD backup_archives tests planejamento-mvc public_html .git uploads node_modules /XF *.sql *.zip *.bak* testar-local.bat | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy falhou ($LASTEXITCODE)" }
    New-Item -ItemType Junction -Path "$dest\uploads" -Target "$proj\uploads" | Out-Null
    $arqs = (Get-ChildItem $dest -Recurse -File).Count
    Write-Host "linha de base copiada: $arqs arquivos (api.php $((Get-Item "$dest\api.php").Length) bytes)"
    Subir-Servidor
  }
}
exit 0
