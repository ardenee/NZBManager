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
| src/Modules/DownloadStationActions.php | Native DSM task submission, task controls and API discovery |
| src/DownloadClients/DownloadStationSettings.php | Private DSM account storage; no credentials rendered to browser |
| src/DownloadClients/DownloadStationClient.php | DSM task API discovery, scoped authentication, NZB upload and logout |
| src/AppConfig.php | DSM package detection and private storage setup |
| src/AppSettings.php | Validated private page sizes and folder paths |
| src/AppPlatform.php | Runtime PHP/DSM platform information |
| src/Indexers/IndexerProfileStore.php | Persisted Newznab profiles and private API keys |
| src/Modules/SettingsActions.php | Saved settings and Newznab connection management |
| src/Modules/PushToActions.php | Restricted HTTPS NZB upload and monitored-folder copy |
| src/Modules/FolderImportActions.php | Bounded, duplicate-aware NZB folder scan |
| src/Modules/SegmentActions.php | Safe add/edit/delete into a revised NZB |
| src/Modules/FolderBrowser.php | Folder-only DSM volume/share navigation and PHP access checks |
| src/Modules/ManagedFolders.php | Locked private registry, filesystem identity checks and managed-folder rename/empty-delete |
| src/Modules/Par2Reader.php | Native PAR2 2.0 packet, file hash and slice checksum verifier |
| src/Modules/Par2Jobs.php | PAR2 header inspection and native job compatibility facade |
| src/Modules/Par2Galois.php | Pure PHP GF16 Reed–Solomon field/matrix arithmetic |
| src/Modules/Par2Recovery.php | Bounded native PHP PAR2 recovery and checksum-verified non-overwriting outputs |
| src/Modules/BackgroundJobs.php | Private async PHP job queue, cancel controls and progress state |
| bin/background-worker.php | Detached PHP CLI worker for PAR2 verify/repair and ZIP extraction |
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
- Binsearch: HTML-based search, zero-based provider pagination and selected
  NZB generation are implemented and have passed live testing; parser
  maintenance remains necessary when Binsearch changes its website.
- Download clients: Download Station discovery, scoped sessions, task upload,
  list, pause/resume and remove implemented. Module appears only if DSM
  advertises the legacy task API; private connection settings support a
  restricted account. Tests mock the API and verify operations. The test NAS
  confirms discovery only; live authenticated tasks await credentials.
- File organization: a bounded workspace browser, file rename/move preview,
  explicit confirmation and no-overwrite hard-link moves are implemented;
  batch operations, payload merges and persistent audit records are pending.
- PAR2: pure PHP packet verifier and pure PHP GF(2^16) Reed–Solomon
  reconstruction; no third-party PAR2 executable, FFI or system binary.
  Repair publishes verified files under their original filenames and preserves
  existing corrupted sources under the `.damaged` suffix; backup collisions
  abort the operation without overwriting the prior backup.
  The streaming recovery path scans packet headers and hashes, retains only
  volume offsets for recovery data, validates input slices in chunks, solves
  GF16 equations over fixed 64 KiB windows and patches private staged files.
  No fixed total-data, 1 MiB slice or 256 MiB PAR-volume limit remains.
  PAR2 2.0 provides at most 32,768 input slices; the current inverse matrix
  supports 32 missing/damaged slices. Live web-to-independent PHP CLI repair
  passed on DSM for 5 slices and 2 files; DEV stress tests repaired a 300 MiB file.
  Real multi-gigabyte recovery workloads remain uncertified.
- Archives: streamed ZIP extraction in independent PHP CLI workers, including
  AES/ZipCrypto password decryption when supported by libzip and raw split
  `.zip.001` volume assembly, with polling progress, cancellation and path
  traversal/symlink checks. Passwords are one-use private worker sidecars,
  never job JSON. True multi-disk `.z01` ZIP, solid and encrypted RAR, 7z and
  TAR adapters remain pending.
- Settings: validated deployment configuration and integration settings,
  secret handling, storage migrations.
- Legacy migration: read-only import of historic MySQL schema and NZB documents.
  MySQL is not needed by the modern runtime; SQL storage remains optional future work.

Do not implement long-running PAR2 repairs, unlimited archive extraction,
or unbounded bulk filesystem operations as synchronous PHP web requests.
Bounded ZIP streaming extraction and a single confirmed atomic file move
are explicitly limited to the configured workspace and do not invoke a shell.

## Data and security

Private storage defaults to var/nzb and should be relocated outside the code
directory in production via NZB_STORAGE_DIR. Linux installations require
NZB_ADMIN_PASSWORD_HASH or NZB_ADMIN_PASSWORD unless explicitly configured
otherwise. The DSM alpha SPK deliberately bypasses the app login using its
package-private marker; it must be restricted to a trusted network. No
MySQL database is required by the modern application.

The storage index is JSON sidecars. SHA-256 hashes and a lock around imports
prevent duplicate NZBs even from different import sources. Bounded native PHP
background job execution is implemented; broader transactional guarantees,
audit logs, migrations and multiworker recovery are still outstanding.

See FEATURE-MATRIX.md for exact implementation status.
