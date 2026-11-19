# Application architecture

## Goals

- Standard Linux + PHP 8.5 is the primary runtime.
- Synology DSM is an optional deployment target, never a required runtime.
- Preserve all supported actions of the historical NZB application, with
  behavior covered by tests before announcing parity.
- Make features independently implementable, replaceable and testable.
- Keep untrusted NZB XML, indexer data and filesystem paths out of shell
  commands and web-rendered HTML until validated/escaped.

## Code layout

| Path | Responsibility |
| --- | --- |
| public/ | Only web root. Minimal index.php front controller, static CSS/JS |
| src/Http/bootstrap.php | Runtime initialization, access policy and current GET routes |
| src/Http/ActionRouter.php | Explicit allowlisted POST action registration / dispatch |
| src/Modules/ModuleInterface.php | Contract for independently rendered modules |
| src/Modules/ModuleRegistry.php | Explicit registration and feature availability |
| src/Modules/ViewModule.php | Template-backed implementations of the interface |
| src/Modules/LibraryActions.php | Library and NZB file POST actions |
| src/Modules/IndexerActions.php | Search and import requests, short-lived result references |
| src/Modules/BinsearchActions.php | Binsearch search and secure selected-NZB generation |
| src/Modules/DownloadStationActions.php | Optional task submission and detected-service visibility |
| src/DownloadClients/DownloadStationClient.php | DSM task API discovery, scoped authentication, NZB upload and logout |
| src/AppConfig.php | Runtime settings and protected DSM auth hash loading |
| src/Indexers/NewznabClient.php | HTTPS-only Newznab search/get, XML parsing and network limits |
| src/Indexers/ProviderCatalog.php | Shared Newznab provider presets, including special API paths |
| src/Indexers/IndexerConnections.php | Multi-indexer server settings and public name-only listing |
| src/Indexers/BinsearchClient.php | Provider-specific HTML search parsing and selected NZB requests |
| src/Indexers/FixedOriginHttp.php | Restricted public HTTPS requests with bounded responses and TLS verification |
| src/NzbDocument.php | NZB XML parsing, safe legacy DTD handling, metadata/segments, subset export and merge |
| src/NzbStorage.php | Private library, opaque identifiers, metadata rename/delete |
| src/WebSecurity.php | Authentication, headers, session and CSRF checks |
| views/layout.php | Shared responsive shell and navigation |
| views/library.php | NZB library module |
| views/inspector.php | NZB inspector module |
| views/indexer.php | Search results and importer for configured Newznab endpoint |
| views/binsearch.php | Public Binsearch search and NZB generation interface |
| tests/ | Domain regression and HTTP smoke tests |
| docs/ | Compatibility matrix and deployment design |
| current/ | Historical source, never served/executed |

The front controller is intentionally small. The current core is split by
responsibility, but the HTTP controller and action registration are still being
evolved; this is the first modularization pass, not the final platform.

## Adding a module

1. Implement ModuleInterface or register an explicitly approved template-backed
   module in ModuleRegistry::standard().
2. Register a unique lowercase ID and a real route ONLY when the module is
   implemented. Planned features intentionally have no route and cannot render.
3. Give the module its own view and action handler, rather than appending another
   feature to public/index.php. Register approved action names with ActionRouter
   in the bootstrap wiring; they must not conflict with existing actions.
4. Enforce authentication and CSRF centrally; validate every input in the
   module service and never accept arbitrary filesystem paths.
5. Add domain tests and real HTTP integration tests before marking it enabled.
6. Update docs/FEATURE-MATRIX.md to track original versus modern behavior.

The registry is not an auto-loading third-party PHP plugin directory. Arbitrary
execution of uploaded modules is deliberately prohibited.

## Planned services

- Indexers: Multiple Newznab provider profiles and search selection are
  implemented, with t=search/t=get fixture tests. Live-provider certification,
  provider-specific redirects and automatic capability discovery remain open.
- Binsearch: HTML-based search and selected NZB generation have separate,
  fixed-origin code and have passed live testing; parser maintenance and
  pagination remain outstanding.
- Download clients: a separate Download Station API adapter is implemented with
  mocked discovery, login, upload and logout tests. It remains unavailable
  unless DSM discovery advertises the required API; an actual NAS must still
  certify task submission and lifecycle. This is independent of the SPK build.
- File organization: allowlisted source/destination roots, preview/dry-run,
  collision policy, audit records, symlink boundaries, explicit confirmations.
- PAR2 and archive tools: background job queue, fixed executable allowlist,
  argv-based launch (not concatenated shell commands), time/resource limits,
  archive traversal and symlink safety, captured logs.
- Settings: validated deployment configuration and integration settings,
  secret handling, storage migrations.
- Legacy migration: read-only import of historic MySQL schema and NZB documents.

Do not implement repair/unpack/move as synchronous PHP web requests with
user-controlled command strings.

## Data and security

Private storage defaults to var/nzb and should be relocated outside the code
directory in production via NZB_STORAGE_DIR. The application rejects requests
when NZB_ADMIN_PASSWORD_HASH or NZB_ADMIN_PASSWORD is missing. Use HTTPS for
password authentication. No database or service manager is required yet.

The storage index is JSON sidecars and will need transactional improvements for
concurrent background work. Content checksums, deduplication, audit logs,
migrations and non-blocking tasks remain outstanding.

See FEATURE-MATRIX.md for exact implementation status.
