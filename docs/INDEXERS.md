# Indexer Search: Newznab-compatible API

## Status

The module is implemented for PHP 8.5 and available in the application's
sidebar. Search and import are covered by fixture-based client tests and
HTTP configuration/security checks. It has NOT yet been exercised against a
live NZBPlanet account; live-provider certification remains pending.

The historical Synology NZB application searched NZBPlanet and imported NZBs.
The modern code uses a provider-neutral Newznab client plus a separate UI.

## Configure the server

Set both PHP-FPM environment variables:

    NZB_INDEXER_URL=https://your-indexer.example/api
    NZB_INDEXER_API_KEY=your-indexer-secret

For NZBPlanet, verify the endpoint in your account settings. Common Newznab
endpoints include https://api.nzbplanet.net/api, but endpoints may change.

Do not put credentials in public PHP, web server document roots, Git, HTML or
JavaScript. PHP-FPM commonly clears inherited environment variables unless
they are explicitly allowed in the pool/service configuration.

The Indexer Search page displays setup guidance until server configuration
is complete. No browser-side API key form is used.

## Search and import workflow

1. Authenticated user submits a search using POST with a CSRF token.
2. NewznabClient requests t=search, q=..., extended=1, limit=50 and apikey.
3. RSS XML is parsed with DTD/entity declarations prohibited.
4. Normalized metadata is held in a short-lived session.
5. Import NZB submits an opaque reference, not an external URL.
6. The server checks the reference against recent search results (10 minutes).
7. Newznab t=get&id=... retrieves the chosen file from the configured API.
8. The NZB is validated, stored in the private library and opened in Inspector.

RSS enclosure, link and GUID URLs are NEVER opened by the server. A URL-form
GUID may be reduced to its opaque release ID; the configured API origin is
always used for the t=get request.

## Network and data safety

- HTTPS and a public DNS hostname required; no embedded credentials, query
  string or fragment allowed in the configured endpoint.
- cURL resolves and pins an allowed public IP, blocks private/reserved IPs,
  disables proxies and redirects, and verifies certificates and hostnames.
- Strict bounds: 2 MiB RSS response, 32 MiB NZB, max 50 search results.
- Network timeouts, safe generic errors (without API key or requested URL).
- Server-side release references; no arbitrary ID or URL imports.
- Search state expires after ten minutes.
- HTML output escaped; POST actions require authentication and CSRF.
- Invalid RSS/API responses, bad NZB XML and unsafe entities are rejected.
- Original NZB bytes are kept after validation.

## Limitations and follow-up

- Only one indexer configured at a time; future settings may support several.
- Standard Newznab t=get&id=... is implemented. Providers with custom GUID
  schemes or redirect-based download endpoints may require an adapter.
- Configured local/intranet indexer URLs are rejected by the public indexer
  client. A separate deliberately isolated local adapter could be added later.
- No anonymous (no-key) indexer mode, category presets, pagination, scheduled
  searches, automatic retries or per-provider rate-limit handling yet.
- A real NZBPlanet search and import must still be verified with an API key.

## Tests on DEV

    D:\php8.5\php.exe tests/run.php
    powershell -File tests/smoke.ps1
    powershell -File tests/indexer-http.ps1

Unit tests use an injectable HTTP fixture. HTTP checks cover both configured
and unconfigured UI states without calling a live indexer.

## Reference

- https://newznab.readthedocs.io/en/latest/misc/api.html
- https://github.com/nZEDb/nZEDb/blob/0.x/docs/newznab_api_specification.txt
