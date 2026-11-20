# NZB Manager feature matrix

Updated 20 November 2026. **Working** means covered by automated tests.
DSM HTTP/CLI results are specifically marked. The running DSM web application
has newer source than the last published SPK; no package is being built now.

| Feature | Area | Status | Limitations / notes |
| --- | --- | --- | --- |
| Multi-file NZB XML upload | Library | **Working** | Validates XML; 32 MiB per input |
| Import NZBs from configured folder | Library | **Working, DEV-tested** | Manual scan, 200-file limit, DSM ACL/open_basedir required |
| Duplicate NZB suppression | Library | **Working** | SHA-256 across all import methods |
| Rename/delete/export/merge NZBs | Library | **Working** | Private managed documents only |
| Global library sorting/search/paging | Library | **Working** | Sort before pagination |
| Inspect metadata and segments | Inspector | **Working** | Raw Article bytes, gap/duplicate detection |
| Add/edit/delete NZB segment references | Inspector | **Working, DSM-tested** | Edits create new NZBs; sequential-number allowlist |
| Inspector file/segment table sorting | Inspector | **Working** | Full dataset before page slicing |
| Binsearch lookup and NZB generation | Binsearch | **Working, live tested** | HTML parser, max 25 selected posts per page |
| Browse Binsearch pages | Binsearch | **Working** | 25/50/100 page size |
| Newznab profiles/queries/import | Indexer | **Implemented** | Account keys required; live third-party requests unverified |
| Private Newznab connection settings | Settings | **Working, DSM-tested** | Secrets not displayed; password-free test site |
| Push To HTTPS endpoint | Push To | **Unit tested** | Bounded public HTTPS; real endpoint delivery unverified |
| Push To monitored folder | Push To | **Working on DEV** | Atomic copy, avoids overwrite |
| Folder picker and PHP access feedback | Settings | **Working, Chrome-tested on DSM** | Browses eligible DSM shares; checks read/write/traverse |
| Required PHP extension status | Settings | **Working, DSM-probed** | DOM, libxml, XML, XMLReader/Writer, SimpleXML, hash, JSON, fileinfo, cURL, mbstring, OpenSSL, zip, zlib |
| Native DSM Download Station API discovery | Download Station | **Working on DSM** | Legacy and DownloadStation2 API advertised; legacy Task API used |
| Configure Download Station credentials | Download Station | **Implemented** | Private DSM connection file; use a restricted account and HTTPS front end |
| Submit NZB / list/pause/resume/remove DSM tasks | Download Station | **Mock-tested** | Authenticated tasks on user's NAS not yet tested |
| Native PHP PAR2 packet & slice verification | PAR2 Repair | **DEV-tested** | MD5/CRC, checks protected files; independent PHP worker |
| Pure PHP Reed-Solomon repair | PAR2 Repair | **DSM small-case / DEV 300 MiB tested** | Streaming 64 KiB windows, no total-data/slice-size/volume-byte cap; GF16 repair of up to 128 erasures, 32,768 input slices; originals preserved |
| PAR2 background progress, cancellation, rerun and removal | PAR2 Repair | **DEV-tested; DSM UI updated** | Verified data takes original filename, damaged source becomes `.damaged`; rerun verifies existing repair, removal leaves files |
| Archive inspection | Archive Extraction | **DEV-tested** | ZIP and raw-split `.zip.001`, TAR/TGZ/SPK; 64 GiB ZIP compressed / 128 GiB expanded, 10,000 entries |
| ZIP extraction in independent PHP worker | Archive Extraction | **DEV encrypted/split tested; DSM AES verified on PHP 8.2.35** | AES password prompt and one-time private secret, raw multipart joining, CRC check, output folder preserved on rerun |
| True multi-disk ZIP / RAR / 7z extraction | Archive Extraction | **Not yet implemented** | `.z01` and RAR/7z require independent pure-PHP decoders; TAR/TGZ/SPK are implemented |
| Preview file rename/move, create folders | File Organizer | **DSM-tested** | Workspace-only, no overwrite, preview & confirmation |
| Rename/delete created folders | File Organizer | **DSM HTTP/Chrome tested** | Tracked folders only; delete empty only; older folders protected |
| Batch file moves / folder content merging | File Organizer | **DEV-tested; DSM UI updated** | Up to 50 selected rows / 500 files, preview, skip/rename/overwrite collision rules, rollback hard links; untracked directory itself retained |
| Torrent uploads | Import | **Out of scope** | Deliberately excluded: this application handles NZB, not torrents |
| Historical MySQL data migration | Migration | Not ported | MySQL not needed at modern runtime |
| DSM 7 test SPK with original icons | Packaging | **Earlier revision built and tested** | Original 256px SPK icon now reused as web logo and favicon; no new SPK installation for this source update |

## Tests and live checks

- **378 PHP assertions on DEV**, covering metadata, parity, workspace
  protection, 68 MiB standard / 300 MiB stress PAR2 reconstruction, 5 GiB metadata, managed
  folders, asynchronous ZIP jobs, Download Station mock API contracts, etc.
- DEV HTTP smoke tests for indexer/security/asset-cache routes.
- DSM PHP-FPM 8.2.35 ZIP AES256 decryption and encryption-method metadata verified live using a temporary fixture (removed after test).
- DSM HTTP: native-PHP background PAR2 reconstruction of **5 damaged/missing
  slices across 2 recovered files**, checksums validated; job reached 100%.
- DSM HTTP: ZIP background worker reached 100%, output validated.
- DSM HTTP/Chrome: folder picker and managed folder create/rename/delete.
- DSM application files are synced to DEV and GitHub after validation.
  No new SPK is built for each source change.

## Outstanding certification

Real-world multi-gigabyte PAR2 repair, more than 128 missing slices, complex
PAR2 metadata, external Newznab search, authenticated Download Station tasks,
RAR/7z extraction, full PAR2 coverage beyond 128 missing slices and further filesystem operations require work.
The DSM alpha intentionally has no application login: keep it off the
public Internet and protect any submitted DSM credentials.

## Scheduling and output destinations (20 November 2026)

| New feature | Area | Verification | Notes |
| --- | --- | --- | --- |
| Automatic monitored NZB folder → Download Station | Settings / Automation | **DEV mock-tested** | Off by default; intervals 5–1440 minutes; stable NZB and duplicate guards; real authenticated DSM submission not certified |
| Automatic PAR2 repair after downloads complete | Settings / Automation | **DEV tested for active-task deferral** | Off by default; scans stable sets and defers while DSM tasks active; per-task destination mapping needs further testing |
| Native PHP TAR, TGZ and SPK extraction | Archive Extraction | **DEV-tested** | Streamed with header checks; rejects traversal, symlinks and special members |
| Default extraction folder and replacement | Settings / Archive Extraction | **DEV-tested** | Fresh numbered folder by default; explicit replacement saves previous output as `.previous-*` |
