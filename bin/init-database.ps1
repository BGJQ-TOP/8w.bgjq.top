<#
.SYNOPSIS
    渲染并导入 8W通行证系统 数据库结构。

.DESCRIPTION
    仓库里的 database/8w_passport.sql 不含任何真实密钥，密码位置是占位符
    __DB_PASSWORD__。本脚本从 .env 读取真实值，渲染到临时文件后交给 mysql 执行，
    执行完立即删除临时文件，确保密钥不会落进版本库。

.PARAMETER RootUser
    MySQL 管理员账号，默认 root。

.PARAMETER RootPassword
    管理员密码。不传则交互式提示输入（推荐，避免留在命令历史里）。

.PARAMETER DryRun
    只渲染不导入，用于人工检查生成的 SQL。

.EXAMPLE
    pwsh ./bin/init-database.ps1
    pwsh ./bin/init-database.ps1 -RootUser root -DryRun
#>
[CmdletBinding()]
param(
    [string]$RootUser = 'root',
    [string]$RootPassword,
    [string]$MysqlHost = 'localhost',
    [string]$MysqlExe = 'mysql',
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$sqlTemplate = Join-Path $projectRoot 'database/8w_passport.sql'
$envFile     = Join-Path $projectRoot '.env'

if (-not (Test-Path $sqlTemplate)) { throw "找不到 SQL 模板：$sqlTemplate" }
if (-not (Test-Path $envFile))     { throw "找不到 .env：$envFile（请从 .env.example 复制并填写）" }

# ---------------------------------------------------------------------------
# 1. 解析 .env（只取需要的键，不做任何输出，避免密钥进日志）
# ---------------------------------------------------------------------------
function Read-DotEnv([string]$Path) {
    $result = @{}
    foreach ($line in Get-Content -LiteralPath $Path -Encoding UTF8) {
        $line = $line.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { continue }
        $idx = $line.IndexOf('=')
        if ($idx -lt 1) { continue }
        $key = $line.Substring(0, $idx).Trim()
        $val = $line.Substring($idx + 1).Trim()
        if ($val.Length -ge 2) {
            $first = $val.Substring(0, 1); $last = $val.Substring($val.Length - 1, 1)
            if (($first -eq '"' -and $last -eq '"') -or ($first -eq "'" -and $last -eq "'")) {
                $val = $val.Substring(1, $val.Length - 2)
            }
        }
        $result[$key] = $val
    }
    return $result
}

$config = Read-DotEnv $envFile
foreach ($required in @('DB_NAME', 'DB_USER', 'DB_PASS')) {
    if (-not $config.ContainsKey($required) -or [string]::IsNullOrWhiteSpace($config[$required])) {
        throw ".env 缺少必填项 $required"
    }
}

$dbName = $config['DB_NAME']
$dbUser = $config['DB_USER']
$dbPass = $config['DB_PASS']

if ($dbName -eq 'bgjq') {
    throw "DB_NAME 仍是已废弃的旧库名 bgjq，请先改成一个新库名。"
}
if ($dbPass -match '__DB_PASSWORD__') {
    throw ".env 里的 DB_PASS 还是占位符，请填入真实密码。"
}

# ---------------------------------------------------------------------------
# 2. 渲染 SQL
# ---------------------------------------------------------------------------
Write-Host "正在渲染 SQL 模板 ..." -ForegroundColor Cyan
$sql = Get-Content -LiteralPath $sqlTemplate -Raw -Encoding UTF8

$bt = [char]96   # 反引号：MySQL 标识符引用符
$sql = $sql.Replace('__DB_PASSWORD__', $dbPass)
$sql = $sql.Replace("${bt}bgjq8w${bt}", "${bt}${dbName}${bt}")
$sql = $sql.Replace("'bgjq8w'@'localhost'", "'$dbUser'@'localhost'")

# 渲染结果只写到系统临时目录，绝不落在仓库里
$rendered = Join-Path ([System.IO.Path]::GetTempPath()) ("8w_passport_{0}.sql" -f ([guid]::NewGuid().ToString('N')))
$keepRendered = $false

try {
    # UTF-8 无 BOM，避免 mysql 客户端把 BOM 当成 SQL 语法错误
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($rendered, $sql, $utf8NoBom)

    Write-Host "  目标库：$dbName"   -ForegroundColor Green
    Write-Host "  目标账号：$dbUser@$MysqlHost" -ForegroundColor Green
    Write-Host "  渲染文件：$rendered" -ForegroundColor DarkGray

    if ($DryRun) {
        $keepRendered = $true
        Write-Host "`n[DryRun] 未执行导入。渲染后的 SQL 保留在：$rendered" -ForegroundColor Yellow
        Write-Host "确认无误后请手动删除该文件（内含真实密码，切勿提交）。" -ForegroundColor Yellow
        return
    }

    # -----------------------------------------------------------------------
    # 3. 导入
    # -----------------------------------------------------------------------
    if (-not (Get-Command $MysqlExe -ErrorAction SilentlyContinue)) {
        throw "找不到 mysql 客户端（$MysqlExe）。请安装 MySQL/MariaDB 客户端，或用 -MysqlExe 指定完整路径。"
    }

    if (-not $PSBoundParameters.ContainsKey('RootPassword')) {
        $secure = Read-Host -Prompt "请输入 MySQL 管理员（$RootUser）密码" -AsSecureString
        $RootPassword = [System.Net.NetworkCredential]::new('', $secure).Password
    }

    Write-Host "正在导入，请稍候 ..." -ForegroundColor Cyan
    # 通过 MYSQL_PWD 传密码，避免出现在进程命令行里
    $previousPwd = $env:MYSQL_PWD
    $env:MYSQL_PWD = $RootPassword
    try {
        $output = & $MysqlExe --host=$MysqlHost --user=$RootUser --default-character-set=utf8mb4 --execute="source $($rendered -replace '\\','/')" 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $env:MYSQL_PWD = $previousPwd
    }

    if ($exitCode -ne 0) {
        Write-Host $output -ForegroundColor Red
        throw "导入失败（mysql 退出码 $exitCode）。"
    }

    Write-Host "`n导入成功！" -ForegroundColor Green
    Write-Host "  数据库：$dbName"
    Write-Host "  账号：  $dbUser@$MysqlHost"
    Write-Host "`n下一步：确认 .env 中的 DB_NAME / DB_USER / DB_PASS 与上面一致，然后访问站点验证。" -ForegroundColor Cyan
    Write-Host "旧库 bgjq 仍然保留；确认新库运行正常后，再手动执行 8w_passport.sql 末尾注释掉的 DROP 语句。" -ForegroundColor Yellow
}
finally {
    if (-not $keepRendered -and (Test-Path -LiteralPath $rendered)) {
        Remove-Item -LiteralPath $rendered -Force -ErrorAction SilentlyContinue
        Write-Host "已清理临时 SQL 文件。" -ForegroundColor DarkGray
    }
}
