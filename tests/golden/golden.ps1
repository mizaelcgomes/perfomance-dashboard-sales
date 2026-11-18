<#
  golden.ps1 — roda os golden tests no ambiente LOCAL de teste (nunca em produção)

  Uso (PowerShell, a partir de qualquer pasta):
    .\tests\golden\golden.ps1 capture        # grava os snapshots (banco recriado antes)
    .\tests\golden\golden.ps1 compare        # compara o código atual com os snapshots (banco recriado antes)
    .\tests\golden\golden.ps1 estabilidade   # captura, recria o banco e compara: tudo precisa ficar igual
    .\tests\golden\golden.ps1 cobertura      # toda ação do api.php tem chamada no catálogo?
    .\tests\golden\golden.ps1 compare -Dir snapshots-baseline   # compara com outra pasta de snapshots

  O que faz: sobe o ambiente se estiver parado, recria o banco de teste (as chamadas de escrita alteram dados), roda o golden.js
  com --escritas e --closer, apaga os arquivos de limite de tentativas (login do admin e do closer) e deixa o banco no estado
  habitual dos testes (-Mix). Sai com código diferente de zero se algo diferir.
#>
param(
  [Parameter(Position = 0)][ValidateSet('capture', 'compare', 'estabilidade', 'cobertura')][string]$Comando = 'compare',
  [string]$Dir = 'snapshots',
  [string]$Base = 'http://127.0.0.1:8787/'
)
$ErrorActionPreference = 'Continue'
$testdb = Join-Path $env:LOCALAPPDATA 'dash-vendas-testdb'
$reset  = Join-Path $testdb 'reset-local-db.ps1'
$start  = Join-Path $testdb 'start-test-env.ps1'
$proj   = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$golden = Join-Path $PSScriptRoot 'golden.js'

if ($Base -notmatch '^https?://(127\.0\.0\.1|localhost)(:\d+)?/') { Write-Host 'Recusado: --Base precisa ser local.'; exit 2 }
if (-not (Test-Path $reset)) { Write-Host "Ambiente de teste não encontrado em $testdb"; exit 2 }

function Test-Servidor { try { (Invoke-WebRequest -UseBasicParsing -TimeoutSec 8 ($Base + 'api.php?action=get_public_contratos_total')).StatusCode -eq 200 } catch { $false } }
function Limpar-Limites {
  foreach ($f in @('uploads\.login_attempts.json', 'uploads\.closer_rate.json', 'storage\rate-limit\closer.json')) { $p = Join-Path $proj $f; if (Test-Path $p) { Remove-Item $p -Force -ErrorAction SilentlyContinue } }
}
function Rodar-Node([string[]]$a) { & node $golden @a | Out-Host; return [int]$LASTEXITCODE }

if (-not (Test-Servidor)) { Write-Host 'Subindo o ambiente de teste...'; & $start | Out-Null; Start-Sleep -Seconds 3 }
if (-not (Test-Servidor)) { Write-Host 'O servidor local (127.0.0.1:8787) não respondeu.'; exit 2 }

if ($Comando -eq 'cobertura') { exit (Rodar-Node @('cobertura', '--base', $Base)) }

$flags = @('--escritas', '--closer', '--sucesso', '--base', $Base)
$codigo = 0
try {
  Write-Host '== recriando o banco de teste (padrão)'; & $reset | Out-Null; Limpar-Limites
  switch ($Comando) {
    'capture' { $codigo = Rodar-Node (@('capture', '--dir', $Dir) + $flags) }
    'compare' { $codigo = Rodar-Node (@('compare', '--dir', $Dir) + $flags) }
    'estabilidade' {
      $tmp = 'snapshots-estabilidade'
      $c1 = Rodar-Node (@('capture', '--dir', $tmp) + $flags)
      Write-Host '== recriando o banco para a segunda rodada'; & $reset | Out-Null; Limpar-Limites
      $c2 = Rodar-Node (@('compare', '--dir', $tmp) + $flags)
      $codigo = if ($c1 -ne 0) { $c1 } else { $c2 }
      Remove-Item (Join-Path $PSScriptRoot $tmp) -Recurse -Force -ErrorAction SilentlyContinue
    }
  }
} finally {
  Limpar-Limites
  Write-Host '== deixando o banco no estado habitual (-Mix)'; & $reset -Mix | Out-Null
}
exit $codigo
