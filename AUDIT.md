# NZB legacy review — 19 November 2026

The archived 2013 source in current/source/ cannot run unmodified with
PHP 8.5 and is unsafe to deploy directly. The new Linux-first application
is a separately isolated core; **function parity has not been achieved**.

| Severity | Historical location | Finding | Resolution / status |
| --- | --- | --- | --- |
| Critical | db.php, nzbp.php, other handlers | Removed mysql_* APIs | No DB in new core; migration pending |
| Critical | deletefile.php, deletefle.php | GET input passed to unlink as a filesystem path | No legacy endpoints exposed |
| Critical | deletefolder.php, merge.php | User-controlled paths included in recursive shell deletion | No shell commands in new core |
| High | nzbp.php, actions | SQL constructed by string concatenation | Future database code must use parameterized PDO |
| High | display.php, index.inc, nzbsearch.php | HTML/JS output not consistently escaped | New UI HTML-escapes user content |
| High | nzbp.php, nzbsearch.php | Unbounded XML and no explicit DTD policy | Bounded DOM parsing; safe external NZB DTD stripped, entity subsets rejected |
| High | upload.php, downloadfile.php | Unsafe filename/remote request handling | Only local .nzb upload to opaque storage |
| High | db.php | MySQL root login and legacy MyISAM/latin1 | No DB required for first release |
| High | SPK metadata, application.cfg | Old DSM third-party UI integration | DSM 7.2+ password-free **test SPK** assembled; real installation/upgrade validation outstanding |
| Medium | nzbp.php | Parses shell ls listing; fragile with unusual filenames | New core uses PHP filesystem APIs |
| Medium | nzbp.php | Creates data directories with mode 0777 | New private directories default to 0700 |
| Medium | legacy forms | Missing CSRF verification on state changes | New POST actions require CSRF token |
| Medium | nzbp.php | Race-prone latest inserted ID lookup | Not present in new core |

## Current core safeguards

- Public document root isolated from application code and private files
- HTTP Basic password required at runtime (server environment)
- Session-bound CSRF tokens and POST-only mutations
- Opaque hexadecimal identifiers, no filesystem paths accepted from requests
- HTML escaping, Content Security Policy and standard browser protections
- NZB XML parser removes simple historical external DTD declarations before
  parsing, rejects unsafe entity subsets, disables external entities and limits
  documents to 32 MiB and 10,000 entries
- No shell execution, arbitrary remote URL fetching, archive unpacking or repair workers
- Optional Newznab HTTPS client uses a configured endpoint, safe DNS/IP checks,
  no redirects, bounded responses, and does not follow RSS download links

## Outstanding gaps

1. PHP 8.5 regression tests pass; HTTP integration and browser tests need
   more coverage, especially authentication, CSRF and multipart uploads.
2. Linux nginx/Apache + PHP-FPM and TLS deployment must be tested.
3. MySQL import must be read-only, with migration to an explicit schema.
4. The remaining old workflows must be ported as separately testable modules:
   Download Station adapters, PAR2, archive extraction, rename/move operations
   and safe background jobs. Newznab search/import is implemented but requires
   verification with real indexer credentials.
5. The merged document currently retains header metadata from only its
   first input; define deterministic metadata merge rules.
6. Improve persistence with transaction-safe metadata, pagination, file
   limits, duplicate detection, cleanup and audit logs.
7. After application feature completion, automate DSM 7 packaging in a
   release-gated pipeline and validate Web Station setup and lifecycle.
8. Review copyright and licences of historical and third-party code before
   redistribution.

Do not expose or activate historical endpoints while completing the port.
