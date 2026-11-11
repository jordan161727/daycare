# The parent-app sandbox: a throwaway AngelNest with invented families, for
# trying Messages from both ends. See docs/parent-app-api.md, section 7.
#
#   powershell -ExecutionPolicy Bypass -File scripts\parent-app-sandbox.ps1
#
# Every setting below is set for this window only and wins over .env, so the
# real database in .env is never opened: it runs on database\parent-sandbox.sqlite,
# texts and pushes go to storage\logs\laravel.log, and the sign-in code comes
# back in the API's answer. Each run starts the sandbox empty and seeds it again.
# Ctrl+C stops the server.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$db = Join-Path $root 'database\parent-sandbox.sqlite'
if (-not (Test-Path $db)) { New-Item -ItemType File $db | Out-Null }

$env:APP_ENV = 'local'
$env:APP_DEBUG = 'true'
$env:APP_URL = 'http://127.0.0.1:8124'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $db
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'log'
$env:DAYCARE_SMS_DRIVER = 'log'
$env:TWILIO_SID = ''
$env:TWILIO_TOKEN = ''
$env:PARENT_APP_PUSH_DRIVER = 'log'
$env:PARENT_APP_SHOW_CODES = 'true'

# The staff pages load their CSS from Vite's dev server while public\hot exists.
if ((Test-Path (Join-Path $root 'public\hot')) -and -not (Test-NetConnection localhost -Port 5173 -InformationLevel Quiet -WarningAction SilentlyContinue)) {
    Write-Host 'Heads up: public\hot is there but Vite is not running - run "npm run dev" in another window, or the staff pages will look unstyled.' -ForegroundColor Yellow
}

php artisan config:clear | Out-Null
php artisan migrate:fresh --force --seed --seeder=ParentAppSandboxSeeder
if ($LASTEXITCODE -ne 0) { throw 'The sandbox could not be set up.' }

Write-Host ''
Write-Host 'Parent-app sandbox is running on http://127.0.0.1:8124' -ForegroundColor Green
Write-Host '  Staff Messages : http://127.0.0.1:8124/messages   director@sandbox.test / sandbox-pass'
Write-Host '                   (Toddler teacher: teacher@sandbox.test / sandbox-pass)'
Write-Host '  Parent tester  : open docs\parent-app-tester.html in your browser'
Write-Host '  Parents        : 585-555-0134 Kate (Mia + Leo) - 585-555-0135 Dan (Mia) - 585-555-0177 Olivia (Ava)'
Write-Host '  Turned away    : 585-555-0188 (Texts not ticked) - 585-555-0199 (restricted)'
Write-Host ''

php artisan serve --host=127.0.0.1 --port=8124
