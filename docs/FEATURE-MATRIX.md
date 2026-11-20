# NZB Manager feature matrix

Status at 20 November 2026. A feature marked **Working** has been exercised
by tests; DSM tests were run on the active Web Station site at 192.168.0.67,
with temporary records removed afterward. Other NAS devices remain untested.

| Feature | Area | Current state | Limitations / notes |
| --- | --- | --- | --- |
| Multi-file NZB import | Library | **Working** | Validates NZB XML and max 32 MiB per file |
| Import NZBs from a configured folder | Library | **Implemented, DEV-tested** | Manual scans up to 200; DSM shares need PHP permission and open_basedir access |
| Prevent duplicate NZB imports | Library | **Working** | SHA-256 across new and historical library contents |
| Rename/delete/download and merge | Library | **Working** | Managed NZB documents only; merge header behavior limited |
| Global sorting/search/pagination | Library | **Working** | Sorts whole library before page slicing |
| Inspect file metadata and segments | Inspector | **Working** | External DTD references disabled |
| Missing/repeated/invalid segment warnings | Inspector | **Working** | Cannot always know absent final segments |
| Segment viewer with add/edit/delete | Inspector | **Working** | Saves revised NZB; Add offers gaps or next sequential number |
| Segment and file entry sorting/pagination | Inspector | **Working** | Sorts full list before page slicing |
| Selective NZB entry export | Inspector | **Working** | Never fetches Usenet article payloads |
| Search Binsearch.info and create NZBs | Binsearch | **Working, live tested** | Scrapes public HTML; max 25 selected results per page |
| Navigate Binsearch pages | Binsearch | **Working, live tested** | Provider zero-based pagination, 25/50/100 page size |
| Sort Binsearch results | Binsearch | **Implemented** | Sorts current provider page in browser |
| Preset Newznab searches and importing | Indexer | **Implemented, live provider unverified** | Presets require real credentials |
| Manage Newznab profiles in Settings | Settings | **Working, DSM HTTP tested** | Keys private, never redisplayed; trusted LAN only |
| Sort Newznab results | Indexer | **Implemented** | Sorts retrieved results in browser |
| Push To public HTTPS upload | Push To | **Implemented, unit-tested** | Custom parameters, 1–25 NZBs; public TLS destinations only |
| Push To monitored NZB folder | Push To | **Working on DEV** | Atomic copy, no overwrite; NAS share permission tests pending |
| Settings page size/folder controls | Settings | **Implemented** | Private on-disk settings |
| Optional DSM Download Station API | Download Station | **Mock-tested, optional** | Disabled without API; live task submission pending |
| DSM 7 package and original icons | Packaging | **Built; earlier revision tested on NAS** | 0.4.0-0001 includes CLI worker and PHP ZIP profile; new SPK install still pending |
| Torrent uploads | Import | Not ported | Historical feature |
| PAR2 set browser and packet inspection | PAR2 Repair | **Working, DEV-tested** | Recognizes PAR2 packet headers in approved workspace |
| PAR2 verification job queue/CLI worker | PAR2 Repair | **Worker lifecycle DEV-tested** | Requires compatible par2cmdline and scheduled worker; live NAS parity not verified |
| PAR2 repair of downloaded payloads | PAR2 Repair | **Opt-in CLI worker, not live-certified** | Off by default; par2cmdline and worker are external prerequisites |
| ZIP preview and bounded extraction | Archive Extraction | **Working, DEV-tested** | Max 250 entries, 32 MiB archive/64 MiB output; safe new folder only |
| RAR/7z/TAR archive extraction | Archive Extraction | **Recognized; not extracted** | Separate safe adapters needed |
| Workspace file preview, rename/move and create subfolder | File Organizer | **Working, DEV-tested** | Approved workspace only, preview/confirm, no overwrite |
| Batch move/folder merge/payload joining | File Organizer | **Not ported** | Requires deeper operation queue and collision safeguards |
| Historical MySQL catalogue import | Migration | Not ported | Read-only migration tool required |

## Test coverage

- 196 PHP assertions on DEV
- HTTP smoke tests on DEV
- DSM HTTP: upload, duplicate suppression, view segments, add missing segment,
  produce revised NZB and remove both temporary records
- DSM HTTP: add a temporary Newznab provider, confirm search form is usable,
  remove the provider
- Live Binsearch page-2 navigation
- Working DSM PHP 8.2 profile confirmed on the test NAS

## Outstanding release gates

Real third-party Newznab requests and live Download Station submissions
remain unverified. Actual PAR2 verification/repair on a NAS, RAR/7z/TAR extraction, and
advanced batch/folder file organization remain incomplete. Do not expose the password-free DSM site to the public internet.
