# Windows/DEV HTTP smoke test. Requires PHP 8.5 and curl.exe.
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$php = 'D:\php8.5\php.exe'
$url = 'http://127.0.0.1:18922/'
$secret = 'nzb-automated-local-smoke-secret'
$folder = Join-Path $env:TEMP ('nzb-http-test-' + [Guid]::NewGuid().ToString('N'))
$cookie = Join-Path $folder 'cookie.txt'
$fixture = Join-Path $folder 'sample.nzb'
$export = Join-Path $folder 'export.nzb'
New-Item -ItemType Directory -Path $folder -Force | Out-Null
$env:NZB_ADMIN_PASSWORD = $secret
$env:NZB_STORAGE_DIR = (Join-Path $folder 'private')
@'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://example.invalid/nzb.dtd">
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
<file poster="u@example.com" subject="&quot;one.rar&quot;"><groups><group>alt.test</group></groups><segments><segment number="1" bytes="100">message-one</segment></segments></file>
<file poster="u@example.com" subject="&quot;two.par2&quot;"><groups><group>alt.test</group></groups><segments><segment number="1" bytes="20">message-two</segment></segments></file>
</nzb>
'@ | Set-Content -Path $fixture -Encoding UTF8
$server = Start-Process -FilePath $php -ArgumentList @('-S', '127.0.0.1:18922', '-t', (Join-Path $repo 'public')) -WindowStyle Hidden -PassThru
try
{
    Start-Sleep -Milliseconds 1300
    $anonymous = & curl.exe -s -o NUL -w '%{http_code}' $url
    if ($anonymous -ne '401') { throw "Unauthenticated request returned $anonymous" }
    Write-Output 'PASS Unauthenticated request returns 401'
    $password = "dev:$secret"
    $page = (& curl.exe -s -c $cookie -b $cookie -u $password $url) -join "`n"
    if (!($page -match 'NZB Library')) { throw 'Authenticated library failed' }
    Write-Output 'PASS Authenticated dashboard'
    $indexerPage = (& curl.exe -s -u $password -c $cookie -b $cookie ($url + '?module=indexer')) -join "`n"
    if ($indexerPage -notmatch 'Connect an indexer' -or $indexerPage -notmatch 'NZB_INDEXER_API_KEY') { throw 'Indexer configuration view missing' }
    Write-Output 'PASS Unconfigured indexer displays safe setup guidance'
    $binsearchPage = (& curl.exe -s -u $password -c $cookie -b $cookie ($url + '?module=binsearch')) -join [Environment]::NewLine
    if ($binsearchPage -notmatch 'Search Binsearch.info' -or $binsearchPage -notmatch 'binsearch_search') { throw 'Binsearch module not accessible' }
    Write-Output 'PASS Binsearch module renders without credentials'
    $binsearchCsrf = & curl.exe -s -o NUL -w '%{http_code}' -u $password -c $cookie -b $cookie -d 'action=binsearch_search' -d 'query=linux' ($url + '?module=binsearch')
    if ($binsearchCsrf -ne '403') { throw "Binsearch POST without CSRF returned $binsearchCsrf" }
    Write-Output 'PASS Binsearch search requires CSRF'

    $forbidden = & curl.exe -s -o NUL -w '%{http_code}' -u $password -c $cookie -b $cookie -d 'action=delete' $url
    if ($forbidden -ne '403') { throw "Missing-CSRF request returned $forbidden" }
    Write-Output 'PASS CSRF rejects unsigned modification'
    if (!($page -match 'name="_csrf" value="([a-f0-9]+)"')) { throw 'Missing CSRF token' }
    $token = $Matches[1]
    & curl.exe -s -o NUL -u $password -c $cookie -b $cookie -F "action=upload" -F "_csrf=$token" -F "files[]=@$fixture;filename=sample.nzb" $url
    if ($LASTEXITCODE -ne 0) { throw 'Upload request failed' }
    $listing = (& curl.exe -s -u $password -c $cookie -b $cookie $url) -join "`n"
    if (!($listing -match 'sample.nzb')) { throw 'Upload did not appear in library' }
    if (!($listing -match 'name="id" value="([a-f0-9]{32})"')) { throw 'Cannot discover saved identifier' }
    $id = $Matches[1]
    Write-Output 'PASS HTTP multipart NZB import'
    $inspect = & curl.exe -s -u $password -c $cookie -b $cookie ($url + '?view=' + $id)
    if (!($inspect -match 'two.par2')) { throw 'Inspector did not display file entries' }
    Write-Output 'PASS NZB inspector renders'
    & curl.exe -s -o $export -u $password -c $cookie -b $cookie -d "action=export" -d "_csrf=$token" -d "id=$id" -d 'entries[]=0' ($url + '?view=' + $id)
    if ($LASTEXITCODE -ne 0) { throw 'Selective NZB export failed' }
    $result = Get-Content $export -Raw
    if (!$result.Contains('message-one') -or $result.Contains('message-two')) { throw 'Filtered export content incorrect' }
    Write-Output 'PASS Selective NZB export preserves correct segment'
    & curl.exe -s -o NUL -u $password -c $cookie -b $cookie -d 'action=rename' -d "_csrf=$token" -d "id=$id" -d 'name=updated.nzb' ($url + '?view=' + $id)
    $renamedListing = (& curl.exe -s -u $password -c $cookie -b $cookie $url) -join "`n"
    if ($renamedListing -notmatch 'updated.nzb') { throw 'Rename did not persist through HTTP' }
    Write-Output 'PASS Rename document via authenticated POST'
    $original = Join-Path $folder 'original.nzb'
    & curl.exe -s -o $original -u $password -c $cookie -b $cookie ($url + '?download=' + $id)
    $originalText = Get-Content $original -Raw
    if (!$originalText.Contains('<!DOCTYPE nzb') -or !$originalText.Contains('message-two')) { throw 'Original NZB download lost legacy DTD or content' }
    Write-Output 'PASS Original download preserves historic DTD and segments'
    & curl.exe -s -o NUL -u $password -c $cookie -b $cookie -d "action=delete" -d "_csrf=$token" -d "id=$id" $url
    $after = & curl.exe -s -u $password -c $cookie -b $cookie $url
    if ($after -match 'updated.nzb') { throw 'Delete did not remove document' }
    Write-Output 'PASS Delete removes imported document'
}
finally
{
    Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
    Remove-Item $folder -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item Env:NZB_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:NZB_STORAGE_DIR -ErrorAction SilentlyContinue
}
