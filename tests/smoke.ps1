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
    $password = "admin:$secret"
    $page = (& curl.exe -s -c $cookie -b $cookie -u $password $url) -join "`n"
    if (!($page -match 'NZB Library')) { throw 'Authenticated library failed' }
    Write-Output 'PASS Authenticated dashboard'
    # The Settings folder picker must be allowed to fetch its own JSON route.
    $headers = (& curl.exe -s -u $password -c $cookie -b $cookie -D - -o NUL $url) -join [Environment]::NewLine
    if ($headers -notmatch "connect-src 'self'") { throw 'CSP blocks same-origin folder picker requests' }
    Write-Output 'PASS CSP permits same-origin browser fetch'
    # SPK files have a reproducible 1970 timestamp, so mtime alone is not a safe asset version.
    $jsVersion = (Get-FileHash (Join-Path $repo 'public/app.js') -Algorithm SHA256).Hash.Substring(0, 16).ToLowerInvariant()
    $cssVersion = (Get-FileHash (Join-Path $repo 'public/style.css') -Algorithm SHA256).Hash.Substring(0, 16).ToLowerInvariant()
    if ($page -notmatch ('app\.js\?v=' + $jsVersion) -or $page -notmatch ('style\.css\?v=' + $cssVersion)) { throw 'Browser asset URLs do not match source content hashes' }
    Write-Output 'PASS CSS and JavaScript use content-hash cache versions'
    $icon = Join-Path $repo 'public/app-icon.png'
    $officialIcon = Join-Path $repo 'packaging/dsm7/PACKAGE_ICON_256.PNG'
    if (!(Test-Path $icon) -or (Get-FileHash $icon -Algorithm SHA256).Hash -ne (Get-FileHash $officialIcon -Algorithm SHA256).Hash) { throw 'Web logo differs from the official SPK icon' }
    $iconVersion = (Get-FileHash $icon -Algorithm SHA256).Hash.Substring(0, 16).ToLowerInvariant()
    if ($page -notmatch ('<img class="brand-icon" src="app-icon\.png\?v=' + $iconVersion) -or
        $page -notmatch ('rel="icon" type="image/png" href="app-icon\.png\?v=' + $iconVersion)) { throw 'SPK icon or favicon was not rendered with content-hash cache busting' }
    $iconStatus = & curl.exe -s -o NUL -w '%{http_code}' ($url + 'app-icon.png')
    if ($iconStatus -ne '200') { throw 'Official application icon was not served' }
    Write-Output 'PASS Official SPK icon is the app logo and favicon'
    $mainNav = [regex]::Match($page, '(?s)<nav aria-label="Main">(.*?)</nav>').Groups[1].Value
    $moduleNav = [regex]::Match($page, '(?s)<nav aria-label="Modules" class="module-nav">(.*?)</nav>').Groups[1].Value
    if ($mainNav -match 'Push To|Download Station' -or $moduleNav -notmatch 'Push To' -or
        $page -notmatch 'MODULES' -or $page -notmatch 'Download Station') { throw 'Sidebar modules are not grouped correctly' }
    Write-Output 'PASS Push To and Download Station are grouped under Modules'
    $repairPage = (& curl.exe -s -u $password -c $cookie -b $cookie ($url + '?module=repair')) -join "`n"
    if ($repairPage -notmatch 'filename.ext.damaged' -or $repairPage -match 'Recovered outputs will use new .repaired filenames') {
        throw 'PAR2 page still advertises old repaired-file publication behavior'
    }
    Write-Output 'PASS PAR2 page describes original-name repair and .damaged backup'
    $unpackPage = (& curl.exe -s -u $password -c $cookie -b $cookie ($url + '?module=unpack')) -join "`n"
    if ($unpackPage -notmatch 'zip.001' -or $unpackPage -notmatch 'password' -or $unpackPage -notmatch '128 GiB') {
        throw 'Archive Extraction page is missing new split/password/archive-size guidance'
    }
    Write-Output 'PASS Archive Extraction page describes split ZIP, password and size safeguards'
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
    # A harmless invalid job ID exercises the actual POST/303 redirects without removing real jobs.
    $repairRedirect = (& curl.exe -s -D - -o NUL -u $password -c $cookie -b $cookie -d "action=par2_forget" -d "_csrf=$token" -d "job_id=invalid" ($url + '?module=repair&folder=par2s')) -join "`n"
    if ($repairRedirect -notmatch '(?im)^Location: index\.php\?module=repair&folder=par2s\s*$') {
        throw 'PAR2 job action discarded the current workspace folder during redirect'
    }
    $archiveRedirect = (& curl.exe -s -D - -o NUL -u $password -c $cookie -b $cookie -d "action=archive_forget" -d "_csrf=$token" -d "job_id=invalid" ($url + '?module=unpack&folder=par2s')) -join "`n"
    if ($archiveRedirect -notmatch '(?im)^Location: index\.php\?module=unpack&folder=par2s\s*$') {
        throw 'Archive job action discarded the current workspace folder during redirect'
    }
    Write-Output 'PASS PAR2 and Archive job action redirects preserve nested workspace folders'
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
