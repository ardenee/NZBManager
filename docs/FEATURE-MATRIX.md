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
| Pure PHP Reed-Solomon repair | PAR2 Repair | **DSM small-case / DEV 300 MiB tested** | Streaming 64 KiB windows, no total-data/slice-size/volume-byte cap; GF16 repair of up to 32 erasures, 32,768 input slices; originals preserved |
| PAR2 background progress, cancellation, rerun and removal | PAR2 Repair | **DEV-tested; DSM UI updated** | Completed/failed/cancelled jobs can rerun or be removed; numbered `.repaired-N` outputs protect earlier repair results |
| ZIP archive inspection | Archive Extraction | **Working** | Max 32 MiB ZIP, 250 entries, 64 MiB expanded |
| ZIP extraction in independent PHP worker | Archive Extraction | **DSM end-to-end tested; rerun/removal DEV-tested** | Finished jobs can rerun into a numbered folder or be removed from history; originals and extracted files remain |
| RAR/7z/TAR extraction | Archive Extraction | **Not yet implemented** | Recognized, not extracted |
| Preview file rename/move, create folders | File Organizer | **DSM-tested** | Workspace-only, no overwrite, preview & confirmation |
| Rename/delete created folders | File Organizer | **DSM HTTP/Chrome tested** | Tracked folders only; delete empty only; older folders protected |
| Batch file moves/payload merging | File Organizer | Not ported | Requires expanded job queue |
| Torrent uploads | Import | Not ported | Historical feature |
| Historical MySQL data migration | Migration | Not ported | MySQL not needed at modern runtime |
| DSM 7 test SPK with original icons | Packaging | **Earlier revision built and tested** | Original 256px SPK icon now reused as web logo and favicon; no new SPK installation for this source update |

## Tests and live checks

- **309 PHP assertions on DEV**, covering metadata, parity, workspace
  protection, 68 MiB standard / 300 MiB stress PAR2 reconstruction, 5 GiB metadata, managed
  folders, asynchronous ZIP jobs, Download Station mock API contracts, etc.
- DEV HTTP smoke tests for indexer/security/asset-cache routes.
- DSM real PHP-FPM 8.2.35 extensions and DSM package API discovery probed.
- DSM HTTP: native-PHP background PAR2 reconstruction of **5 damaged/missing
  slices across 2 recovered files**, checksums validated; job reached 100%.
- DSM HTTP: ZIP background worker reached 100%, output validated.
- DSM HTTP/Chrome: folder picker and managed folder create/rename/delete.
- DSM application files are synced to DEV and GitHub after validation.
  No new SPK is built for each source change.

## Outstanding certification

Real-world multi-gigabyte PAR2 repair, more than 32 missing slices, complex
PAR2 metadata, external Newznab search, authenticated Download Station tasks,
RAR/7z/TAR extraction and multi-file organization require further work.
The DSM alpha intentionally has no application login: keep it off the
public Internet and protect any submitted DSM credentials.
