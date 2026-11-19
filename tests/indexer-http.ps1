# HTTP checks for a configured indexer, without contacting a real provider.
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$php = 'D:\php8.5\php.exe'
$url = 'http://127.0.0.1:18923/'
$secret = 'local-indexer-http-test-password'
$folder = Join-Path $env:TEMP ('nzb-indexer-http-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $folder | Out-Null
$cookie = Join-Path $folder 'cookie.txt'
$env:NZB_ADMIN_PASSWORD = $secret
$env:NZB_STORAGE_DIR = Join-Path $folder 'private'
$env:NZB_INDEXER_URL = 'https://index.example.net/api'
$env:NZB_INDEXER_API_KEY = 'fixture-key-not-real'
$server = Start-Process -FilePath $php -ArgumentList @('-S', '127.0.0.1:18923', '-t', (Join-Path $repo 'public')) -WindowStyle Hidden -PassThru
try
{
    Start-Sleep -Milliseconds 1400
    $auth = "dev:$secret"
    $page = (& curl.exe -s -c $cookie -b $cookie -u $auth ($url + '?module=indexer')) -join [Environment]::NewLine
    if ($page -notmatch 'Search releases' -or $page -notmatch 'indexer_search') { throw 'Configured indexer UI missing' }
    Write-Output 'PASS Configured indexer search form renders'
    if ($page -match 'fixture-key-not-real') { throw 'API key leaked into rendered page' }
    Write-Output 'PASS Indexer API key not exposed in HTML'
    if ($page -notmatch 'name="_csrf" value="([a-f0-9]+)"') { throw 'Missing CSRF token' }
    $token = $Matches[1]
    $forbidden = & curl.exe -s -o NUL -w '%{http_code}' -u $auth -c $cookie -b $cookie -d 'action=indexer_search' -d 'query=sample' ($url + '?module=indexer')
    if ($forbidden -ne '403') { throw "Indexer POST without CSRF returned $forbidden" }
    Write-Output 'PASS Indexer search POST enforces CSRF'
    & curl.exe -s -o NUL -u $auth -c $cookie -b $cookie -d 'action=indexer_search' -d "_csrf=$token" -d 'query=x' ($url + '?module=indexer')
    $invalid = (& curl.exe -s -u $auth -c $cookie -b $cookie ($url + '?module=indexer')) -join [Environment]::NewLine
    if ($invalid -notmatch 'Search query must contain') { throw 'Invalid query not rejected' }
    Write-Output 'PASS Indexer query validated before network request'
    & curl.exe -s -o NUL -u $auth -c $cookie -b $cookie -d 'action=indexer_import' -d "_csrf=$token" -d ('ref=' + ('a' * 24)) ($url + '?module=indexer')
    $expired = (& curl.exe -s -u $auth -c $cookie -b $cookie ($url + '?module=indexer')) -join [Environment]::NewLine
    if ($expired -notmatch 'Search results expired') { throw 'Indexer import accepted without recent search' }
    Write-Output 'PASS Import requires a recent search result'
}
finally
{
    Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
    Remove-Item $folder -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item Env:NZB_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:NZB_STORAGE_DIR -ErrorAction SilentlyContinue
    Remove-Item Env:NZB_INDEXER_URL -ErrorAction SilentlyContinue
    Remove-Item Env:NZB_INDEXER_API_KEY -ErrorAction SilentlyContinue
}
