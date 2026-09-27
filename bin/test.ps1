<#
.SYNOPSIS
    提交前闸门：全量 PHP 语法检查 + 通行证核心逻辑测试。

.DESCRIPTION
    项目没有 Composer / PHPUnit，本脚本用最朴素的方式保证"提交前必须全绿"：
      1. php -l 扫描全部 .php 文件
      2. 运行 passport/tests/smoke.php（不依赖数据库与外部网络）

.PARAMETER PhpExe
    php 可执行文件路径。不传则依次尝试 PATH、常见本地安装位置。

.EXAMPLE
    pwsh ./bin/test.ps1
#>
[CmdletBinding()]
param(
    [string]$PhpExe
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot

# ---------------------------------------------------------------------------
# 1. 定位 php
# ---------------------------------------------------------------------------
function Resolve-Php([string]$Explicit)
{
    if ($Explicit) {
        if (Test-Path -LiteralPath $Explicit) { return $Explicit }
        throw "指定的 php 不存在：$Explicit"
    }

    $fromPath = Get-Command php -ErrorAction SilentlyContinue
    if ($fromPath) { return $fromPath.Source }

    $candidates = @(
        'C:\Program Files\HBuilderX\plugins\php\php.exe',
        'C:\php\php.exe',
        'C:\xampp\php\php.exe',
        'C:\laragon\bin\php\php.exe',
        'C:\phpstudy_pro\Extensions\php'
    )
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
    }

    throw "找不到 php。请安装 PHP 或用 -PhpExe 指定路径。"
}

$php = Resolve-Php $PhpExe
$phpVersion = (& $php -r 'echo PHP_VERSION;')
Write-Host "PHP: $php (v$phpVersion)" -ForegroundColor DarkGray

# ---------------------------------------------------------------------------
# 2. 全量语法检查
# ---------------------------------------------------------------------------
Write-Host "`n[1/2] PHP 语法检查 ..." -ForegroundColor Cyan

$skip = @('\\vendor\\', '\\node_modules\\', '\\storage\\')
$files = Get-ChildItem -LiteralPath $projectRoot -Recurse -Filter *.php -File |
    Where-Object { $path = $_.FullName; -not ($skip | Where-Object { $path -like "*$_*" }) }

$syntaxFailures = @()
foreach ($file in $files) {
    $output = & $php -l $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        $syntaxFailures += [pscustomobject]@{ File = $file.FullName; Output = ($output -join "`n") }
    }
}

if ($syntaxFailures.Count -gt 0) {
    foreach ($failure in $syntaxFailures) {
        Write-Host "  ✗ $($failure.File)" -ForegroundColor Red
        Write-Host "    $($failure.Output)" -ForegroundColor DarkRed
    }
    Write-Host "`n语法检查失败：$($syntaxFailures.Count)/$($files.Count)" -ForegroundColor Red
    exit 1
}
Write-Host "  ✓ 全部通过（$($files.Count) 个文件）" -ForegroundColor Green

# ---------------------------------------------------------------------------
# 3. 核心逻辑测试
# ---------------------------------------------------------------------------
Write-Host "`n[2/2] 通行证核心逻辑测试 ..." -ForegroundColor Cyan

$smoke = Join-Path $projectRoot 'passport/tests/smoke.php'
if (-not (Test-Path -LiteralPath $smoke)) {
    Write-Host "  ✗ 找不到测试脚本：$smoke" -ForegroundColor Red
    exit 1
}

& $php $smoke
$exitCode = $LASTEXITCODE

if ($exitCode -ne 0) {
    Write-Host "`n测试未通过。" -ForegroundColor Red
    exit $exitCode
}

Write-Host "`n全部闸门通过，可以提交。" -ForegroundColor Green
exit 0
