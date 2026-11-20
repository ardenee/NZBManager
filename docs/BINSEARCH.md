# Binsearch module

Binsearch.info is not a Newznab API provider. Its current public interface
returns server-rendered HTML from /search?q=... and creates NZB files at
/nzb?q=... with selected result checkbox IDs. The Binsearch module is
completely separate from the Newznab provider catalogue and needs no API key.

## Working functions

- Search Binsearch.info by text from the NZB Manager sidebar
- Parse server-rendered results, including subjects, sizes, and ages
- Select 1–25 results, then create one NZB at Binsearch's fixed origin
- Download the NZB directly or import it into the private NZB library
- Require authentication and CSRF for both search and NZB generation
- Reject user-supplied arbitrary result IDs or URLs: selections are checked
  against server session search results, expiring after 10 minutes
- Validate the generated NZB XML before passing it to storage or download

## Verification

On 19 November 2026, a live query for 'linux' returned 25 Binsearch
results. Creating an NZB from the first result returned a valid 43,910-byte
NZB document which passed the PHP NZB parser. This verifies the current
search and generation interface, but the HTML may change without notice.

DEV PHP 8.5.12 uses an OpenSSL-based cURL backend and initially lacked a
trusted CA bundle, preventing TLS verification. A live test completed with
a trusted CA bundle from the existing Git installation, passed to PHP as
the curl.cainfo setting. No TLS verification was disabled.

Windows DEV command (adjust certificate path if Git is installed elsewhere):

    D:\php8.5\php.exe -d "curl.cainfo=C:\Program Files\Git\usr\ssl\cert.pem" tests/binsearch-live.php

The normal automated test suite uses a fixture transport and does not
require Binsearch to be online:

    D:\php8.5\php.exe tests/run.php
    powershell -File tests/smoke.ps1

## Limitations

- Binsearch uses a zero-based `page` parameter. Previous, Next and numbered
  navigation are available with a configurable page size of 25, 50 or 100.
  Selected NZB IDs are validated against the current page's server session.
- HTML scraping depends on the structure of Binsearch's forms and checkboxes;
  this is inherently less stable than the Newznab protocol.
- Selected NZB generation is based on Binsearch collection IDs. It does not
  download article payloads from a Usenet server.
- No background auto-search, result deduplication across providers, or
  destination download-client integration yet.
- Provider service availability, copyright permissions and source retention
  are external to NZB Manager.
