$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

$required = @(
  'api.js', 'rbac.js', 'ui-system.js', 'css/pawpos-ui.css',
  'dashboard.html', 'pos.html', 'inventory.html', 'customers.html',
  'suppliers.html', 'pets.html', 'appointments.html', 'reports.html',
  'users.html', 'audit_logs.html', 'petshop_pos.sql'
)

foreach ($file in $required) {
  if (-not (Test-Path -LiteralPath (Join-Path $root $file))) {
    throw "Missing required file: $file"
  }
}

Get-ChildItem -LiteralPath $root -Filter '*.js' | ForEach-Object {
  & node --check $_.FullName
  if ($LASTEXITCODE -ne 0) { throw "JavaScript syntax failed: $($_.Name)" }
}

Get-ChildItem -LiteralPath $root -Filter '*.php' | ForEach-Object {
  & php -l $_.FullName | Out-Null
  if ($LASTEXITCODE -ne 0) { throw "PHP syntax failed: $($_.Name)" }
}

$sqlFiles = @(Get-ChildItem -LiteralPath $root -Filter '*.sql')
if ($sqlFiles.Count -ne 1 -or $sqlFiles[0].Name -ne 'petshop_pos.sql') {
  throw 'Expected exactly one canonical SQL file: petshop_pos.sql'
}

Write-Output 'PAWPOS smoke checks passed.'
