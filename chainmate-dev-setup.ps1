<#
.SYNOPSIS
    Sets up a local ChainMate (Snipe-IT fork) development environment on Windows 11.

.DESCRIPTION
    Installs PHP 8.3, Composer and MariaDB, then configures this checkout: php.ini,
    .env, database, composer install, migrations, front-end build and the ChainMate
    branding.

    The script is idempotent - re-running it skips anything already done.

        powershell -ExecutionPolicy Bypass -File .\chainmate-dev-setup.ps1

    If Laravel Herd is installed and has finished provisioning its own PHP, the
    script uses that instead and serves the site at http://snipe-it.test. Herd's
    PHP is only provisioned after the desktop app has been opened once, which a
    script cannot do, so the winget PHP is the fallback.

.PARAMETER DbPassword
    Password for the 'snipeit' MariaDB user (and for root on a fresh install).
    Generated if not supplied, and printed at the end.

.PARAMETER DbRootPassword
    Existing MariaDB root password, when MariaDB is already installed with one.
    Defaults to -DbPassword.

.PARAMETER AppUrl
    APP_URL for .env. Defaults to the 'php artisan serve' address.

.PARAMETER SkipInstall
    Skip the PHP / Composer / MariaDB installs and only configure the project.

.PARAMETER SkipBranding
    Skip 'php artisan chainmate:branding'. That command needs the setup wizard to
    have run first, so it is skipped automatically on a brand new database.
#>

[CmdletBinding()]
param(
    [string] $DbPassword,
    [string] $DbRootPassword,
    [string] $AppUrl = 'http://localhost:8000',
    [switch] $SkipInstall,
    [switch] $SkipBranding
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = $PSScriptRoot
$DbName = 'snipeit'
$DbUser = 'snipeit'
$PhpPackage = 'PHP.PHP.8.3'
$ComposerHome = "$env:LOCALAPPDATA\Programs\composer"

# Extensions Snipe-IT needs that are not compiled into the Windows build.
$PhpExtensions = @(
    'curl', 'exif', 'fileinfo', 'gd', 'intl', 'ldap', 'mbstring', 'mysqli',
    'openssl', 'pdo_mysql', 'pdo_sqlite', 'soap', 'sockets', 'sodium', 'sqlite3', 'zip'
)

function Write-Step([string] $Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Write-Skip([string] $Message) {
    Write-Host "    (skipped) $Message" -ForegroundColor DarkGray
}

function Update-PathFromEnvironment {
    # Installers extend PATH for new processes only; pull it in so the rest of
    # this script can call php / composer straight away.
    $machine = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $user = [Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = ($machine, $user | Where-Object { $_ }) -join ';'
}

function Test-Command([string] $Name) {
    return [bool] (Get-Command $Name -ErrorAction SilentlyContinue)
}

function Add-ToUserPath([string] $Directory) {
    $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
    if ($userPath -notlike "*$Directory*") {
        [Environment]::SetEnvironmentVariable('Path', "$userPath;$Directory", 'User')
    }
    if ($env:Path -notlike "*$Directory*") {
        $env:Path = "$env:Path;$Directory"
    }
}

function Find-MysqlClient {
    if (Test-Command 'mysql') { return (Get-Command mysql).Source }

    $candidate = Get-ChildItem 'C:\Program Files\MariaDB*\bin\mysql.exe' -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending | Select-Object -First 1

    if ($candidate) { return $candidate.FullName }

    return $null
}

function Install-WingetPackage([string] $Id, [string] $Label, [string[]] $ExtraArgs) {
    $installed = winget list --id $Id --exact 2>$null | Select-String -SimpleMatch $Id
    if ($installed) {
        Write-Skip "$Label is already installed"
        return
    }

    Write-Host "    Installing $Label ..."
    $arguments = @('install', '--id', $Id, '--exact', '--accept-package-agreements', '--accept-source-agreements', '--disable-interactivity')
    if ($ExtraArgs) { $arguments += $ExtraArgs }

    winget @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "winget failed to install $Label (exit code $LASTEXITCODE). Install it manually, then re-run with -SkipInstall."
    }
}

function Initialize-PhpIni {
    $phpDir = Split-Path (Get-Command php).Source
    $ini = Join-Path $phpDir 'php.ini'

    if (Test-Path $ini) {
        Write-Skip "php.ini already exists at $ini"
        return
    }

    if (-not (Test-Path (Join-Path $phpDir 'php.ini-development'))) {
        # Herd ships its own php.ini per version; nothing to do.
        Write-Skip 'PHP manages its own php.ini'
        return
    }

    Copy-Item (Join-Path $phpDir 'php.ini-development') $ini
    $extDir = Join-Path $phpDir 'ext'

    $lines = Get-Content $ini | ForEach-Object {
        if ($_ -match '^;extension_dir = "ext"') { "extension_dir = `"$extDir`"" } else { $_ }
    }

    $lines += @('', '; --- enabled for ChainMate / Snipe-IT ---')
    $lines += $PhpExtensions | ForEach-Object { "extension=$_" }
    $lines += 'memory_limit = 512M'
    $lines += 'date.timezone = UTC'

    Set-Content -Path $ini -Value $lines -Encoding ascii
    Write-Host "    Wrote $ini"
}

function Install-Composer {
    if (Test-Command 'composer') {
        Write-Skip 'Composer is already installed'
        return
    }

    New-Item -ItemType Directory -Force -Path $ComposerHome | Out-Null

    Push-Location $env:TEMP
    try {
        php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
        $expected = php -r "echo trim(file_get_contents('https://composer.github.io/installer.sig'));"
        $actual = php -r "echo hash_file('sha384', 'composer-setup.php');"

        if ($expected -ne $actual) {
            throw "Composer installer checksum mismatch (expected $expected, got $actual)."
        }

        php composer-setup.php --install-dir="$ComposerHome" --filename=composer.phar --quiet
    } finally {
        Remove-Item 'composer-setup.php' -ErrorAction SilentlyContinue
        Pop-Location
    }

    Set-Content -Path (Join-Path $ComposerHome 'composer.bat') -Value "@php `"$ComposerHome\composer.phar`" %*" -Encoding ascii
    Add-ToUserPath $ComposerHome
    Write-Host "    Installed Composer to $ComposerHome"
}

function Initialize-HerdPhp {
    # Herd's bundled PHP ships a 128M memory limit (the full test suite needs
    # more than 512M), leaves ext-ldap off, and has no OPENSSL_CONF - without
    # which openssl_pkey_new() fails, breaking the OIDC tests.
    $ini = Join-Path (Split-Path (Get-Command php).Source) 'php.ini'
    if (-not (Test-Path $ini)) { return }

    $lines = Get-Content $ini
    $changed = $false

    $currentLimit = php -r "echo ini_get('memory_limit');"
    if ($currentLimit -ne '2G' -and $currentLimit -ne '-1') {
        $lines = $lines | ForEach-Object {
            if ($_ -match '^\s*memory_limit\s*=') { 'memory_limit = 2G' } else { $_ }
        }
        $changed = $true
    }

    if (-not ($lines -match '^\s*extension\s*=\s*ldap\s*$')) {
        $extDir = Join-Path (Split-Path (Get-Command php).Source) 'ext'
        if (Test-Path (Join-Path $extDir 'php_ldap.dll')) {
            $lines += 'extension=ldap'
            $changed = $true
        }
    }

    if ($changed) {
        Copy-Item $ini "$ini.bak-chainmate" -Force
        Set-Content -Path $ini -Value $lines -Encoding ascii
        Write-Host "    Updated $ini (memory_limit / ldap)"
    }

    $opensslConf = Join-Path (Split-Path (Get-Command php).Source) 'extras\ssl\openssl.cnf'
    if ((Test-Path $opensslConf) -and -not [Environment]::GetEnvironmentVariable('OPENSSL_CONF', 'User')) {
        [Environment]::SetEnvironmentVariable('OPENSSL_CONF', $opensslConf, 'User')
        $env:OPENSSL_CONF = $opensslConf
        Write-Host "    Set OPENSSL_CONF to $opensslConf"
    }

    if ($changed -and (Test-Command 'herd')) {
        herd restart
    }
}

function Set-EnvValue([string] $Path, [string] $Key, [string] $Value) {
    $lines = Get-Content $Path
    $pattern = "^\s*#?\s*$([regex]::Escape($Key))\s*="
    $line = "$Key=$Value"

    if ($lines -match $pattern) {
        $lines = $lines | ForEach-Object { if ($_ -match $pattern) { $line } else { $_ } }
    } else {
        $lines += $line
    }

    Set-Content -Path $Path -Value $lines -Encoding utf8
}

function Invoke-Artisan([string[]] $ArtisanArgs) {
    & php artisan @ArtisanArgs
    if ($LASTEXITCODE -ne 0) {
        throw "php artisan $($ArtisanArgs -join ' ') failed (exit code $LASTEXITCODE)."
    }
}

# ---------------------------------------------------------------------------

Set-Location $ProjectRoot

$existingEnv = Join-Path $ProjectRoot '.env'

if (-not $DbPassword -and (Test-Path $existingEnv)) {
    # Re-run: keep the password this install already uses.
    $match = Select-String -Path $existingEnv -Pattern '^DB_PASSWORD=["'']?(.+?)["'']?$' | Select-Object -First 1
    if ($match) { $DbPassword = $match.Matches[0].Groups[1].Value }
}

if (-not $DbPassword) {
    $DbPassword = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char] $_ })
    $generatedPassword = $true
}
if (-not $DbRootPassword) { $DbRootPassword = $DbPassword }

Write-Host 'ChainMate development setup' -ForegroundColor Yellow
Write-Host "Project: $ProjectRoot"

# 1. Package installs -------------------------------------------------------

if ($SkipInstall) {
    Write-Step 'Package installs skipped (-SkipInstall)'
} else {
    Write-Step 'Installing PHP, Composer and MariaDB'

    if (-not (Test-Command 'winget')) {
        throw 'winget was not found. Install "App Installer" from the Microsoft Store, then re-run.'
    }

    Install-WingetPackage -Id $PhpPackage -Label 'PHP 8.3'
    Install-WingetPackage -Id 'MariaDB.Server' -Label 'MariaDB' -ExtraArgs @(
        '--custom', "SERVICENAME=MariaDB PASSWORD=$DbRootPassword PORT=3306 UTF8=1"
    )

    Update-PathFromEnvironment

    if (-not (Test-Command 'php')) {
        throw 'PHP installed but is not on PATH yet. Open a NEW terminal and re-run with -SkipInstall.'
    }

    Initialize-PhpIni
    Install-Composer
}

# 2. Toolchain check --------------------------------------------------------

Write-Step 'Checking the toolchain'

Update-PathFromEnvironment
if (Test-Path $ComposerHome) { Add-ToUserPath $ComposerHome }

foreach ($tool in 'php', 'composer', 'node', 'npm') {
    if (-not (Test-Command $tool)) {
        throw "$tool is not on PATH. Open a NEW terminal and re-run with -SkipInstall."
    }
    Write-Host "    $tool -> $((Get-Command $tool).Source)"
}

$missingExtensions = @('curl', 'exif', 'fileinfo', 'gd', 'mbstring', 'pdo_mysql', 'pdo_sqlite', 'zip') |
    Where-Object { (php -r "echo extension_loaded('$_') ? 1 : 0;") -ne '1' }

if ($missingExtensions) {
    Write-Warning "PHP extensions missing: $($missingExtensions -join ', '). Enable them in php.ini."
}

# 3. Herd site --------------------------------------------------------------

$usingHerd = (Test-Command 'herd') -and ((Get-Command php).Source -like '*\.config\herd\*')

if ($usingHerd) {
    Write-Step 'Tuning Herd PHP'
    Initialize-HerdPhp

    Write-Step 'Linking the Herd site'

    herd link snipe-it
    if ($LASTEXITCODE -eq 0) {
        if (-not $PSBoundParameters.ContainsKey('AppUrl')) { $AppUrl = 'http://snipe-it.test' }
        Write-Host "    Site: $AppUrl"
    } else {
        Write-Warning 'herd link failed - falling back to php artisan serve.'
        $usingHerd = $false
    }
}

# 4. Database ---------------------------------------------------------------

Write-Step 'Creating the database and user'

$mysql = Find-MysqlClient

if (-not $mysql) {
    Write-Skip 'no mysql client found - create the database manually'
} else {
    $sql = "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
           "CREATE USER IF NOT EXISTS '$DbUser'@'localhost' IDENTIFIED BY '$DbPassword'; " +
           "GRANT ALL PRIVILEGES ON ``$DbName``.* TO '$DbUser'@'localhost'; FLUSH PRIVILEGES;"

    & $mysql --user=root "--password=$DbRootPassword" --execute=$sql

    if ($LASTEXITCODE -ne 0) {
        Write-Warning 'Could not connect to MariaDB as root. Create the database yourself, then re-run:'
        Write-Host "    $sql"
    } else {
        Write-Host "    Database '$DbName' and user '$DbUser' are ready"
    }
}

# 5. .env -------------------------------------------------------------------

Write-Step 'Writing .env'

$envPath = Join-Path $ProjectRoot '.env'

if (-not (Test-Path $envPath)) {
    Copy-Item (Join-Path $ProjectRoot '.env.example') $envPath
    Write-Host '    Created .env from .env.example'
} else {
    Write-Skip '.env already exists - updating the ChainMate and database keys only'
}

Set-EnvValue $envPath 'APP_ENV' 'local'
Set-EnvValue $envPath 'APP_DEBUG' 'true'
Set-EnvValue $envPath 'APP_URL' $AppUrl
Set-EnvValue $envPath 'SITE_NAME' "'ChainMate'"
Set-EnvValue $envPath 'DB_CONNECTION' 'mariadb'
Set-EnvValue $envPath 'DB_HOST' '127.0.0.1'
Set-EnvValue $envPath 'DB_PORT' '3306'
Set-EnvValue $envPath 'DB_DATABASE' $DbName
Set-EnvValue $envPath 'DB_USERNAME' $DbUser
Set-EnvValue $envPath 'DB_PASSWORD' "'$DbPassword'"

$testEnvPath = Join-Path $ProjectRoot '.env.testing'
if (-not (Test-Path $testEnvPath)) {
    Copy-Item (Join-Path $ProjectRoot '.env.testing-ci') $testEnvPath

    # The importer lowers the process memory limit to 500M at runtime
    # (config/importer.php), which is not enough to render some views under
    # test on Windows - php.ini's own limit cannot override an ini_set().
    Add-Content -Path $testEnvPath -Value @('', 'IMPORT_MEMORY_LIMIT=2G', 'LDAP_MEM_LIM=2G')

    Write-Host '    Created .env.testing (SQLite) so the test suite needs no MariaDB'
}

# 6. PHP dependencies -------------------------------------------------------

Write-Step 'Installing PHP dependencies'

composer install --no-interaction
if ($LASTEXITCODE -ne 0) { throw "composer install failed (exit code $LASTEXITCODE)." }

if (-not (Select-String -Path $envPath -Pattern '^APP_KEY=base64:' -Quiet)) {
    Invoke-Artisan @('key:generate', '--no-interaction')
} else {
    Write-Skip 'APP_KEY is already set'
}

# 7. Migrations -------------------------------------------------------------

Write-Step 'Running migrations'

Invoke-Artisan @('migrate', '--force', '--no-interaction')

$userCount = php -r "require 'vendor/autoload.php'; `$app = require 'bootstrap/app.php'; `$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\User::withTrashed()->count();"
$setupComplete = [int] $userCount -gt 0

# 8. Front-end --------------------------------------------------------------

Write-Step 'Building the front-end'

if (-not (Test-Path (Join-Path $ProjectRoot 'node_modules'))) {
    # npm ci is not usable here: upstream's package-lock.json is out of sync with package.json.
    npm install --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw "npm install failed (exit code $LASTEXITCODE)." }
} else {
    Write-Skip 'node_modules already present'
}

npm run prod
if ($LASTEXITCODE -ne 0) { throw "npm run prod failed (exit code $LASTEXITCODE)." }

# 9. Branding ---------------------------------------------------------------

Write-Step 'Applying ChainMate branding'

if ($SkipBranding) {
    Write-Skip '-SkipBranding was passed'
} elseif (-not $setupComplete) {
    Write-Skip "no users yet - finish the setup wizard first, then run: php artisan chainmate:branding"
} else {
    Invoke-Artisan @('chainmate:branding')
}

Invoke-Artisan @('optimize:clear')

# ---------------------------------------------------------------------------

Write-Host ''
Write-Host 'Done.' -ForegroundColor Green
Write-Host ''
if ($usingHerd) {
    Write-Host "  Site:       $AppUrl (served by Herd)"
} else {
    Write-Host "  Serve:      php artisan serve   ->  $AppUrl"
}
Write-Host "  Database:   $DbName (user $DbUser)"
if ($generatedPassword) {
    Write-Host "  DB password: $DbPassword" -ForegroundColor Yellow
    Write-Host '  (also written to .env - save it somewhere if you want it)'
}
Write-Host ''
if (-not $setupComplete) {
    $serveHint = if ($usingHerd) { "open $AppUrl" } else { "run 'php artisan serve' and open $AppUrl" }
    Write-Host "  Next: $serveHint, complete the setup wizard, then:"
    Write-Host '        php artisan chainmate:branding'
}
Write-Host '  Tests:      php artisan test --compact'
Write-Host '  Formatting: vendor/bin/pint --dirty --format agent'
Write-Host '  CSS watch:  npm run watch'
