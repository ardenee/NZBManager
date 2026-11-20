# Download-processing modules (DSM 7 and Linux)

NZB Manager's PAR2 Repair, Archive Extraction and File Organizer modules
operate on downloaded payloads inside the approved **downloads workspace**,
not on NZB document XML or arbitrary NAS paths.

## Workspace setup and permissions

Open Settings > Display and folders. Browse to an existing approved DSM share
such as /volume1/data/Downloads and save the downloads workspace. Directory
browsing excludes home/system/web shares and symlinks. Read/traverse
permission is required to list contents, and write permission is needed to
extract, repair or organize files. The Web Station PHP profile must include the
volume in open_basedir, and the actual PHP-FPM user must have the DSM share ACLs.
Settings reports read/write access and rejects inaccessible configured paths.

**Security:** The current DSM alpha has no application login. Anyone able to
access NZB Manager could submit jobs or organize files. Restrict the website
to a trusted LAN or an authenticated reverse proxy; never expose this
password-free test site directly to the Internet.

## File Organizer and folder creation

- Browse approved directories; preview/confirm a same-filesystem file rename
  or move without overwriting other files.
- Create a folder through File Organizer. Its identity (filesystem device/inode)
  is recorded in the package-private managed-folder registry.
- Rename tracked folders within their existing parent, preserving contents.
  The operation uses a short-lived preview, CSRF and collision checks.
- Delete **individual regular workspace files** with preview, confirmation and
  a second device/inode/size/mtime/ctime check before unlinking. Hidden files,
  symlinks and Synology `@eaDir` paths are protected. This lets users empty
  managed folders without recursive deletion.
- Delete **empty tracked folders only**; never recursively delete the contents.
  Pre-existing, externally moved/replaced or untracked folders are protected.
- Rename/Delete controls appear for tracked folders; earlier folders show
  disabled controls and an explanation. A folder created before tracking
  existed cannot safely be claimed as managed without an explicit adoption
  process, which is not implemented.
- Batch file moves and recursive folder-content merges support previewed
  skip, numbered rename or confirmed overwrite. Moving a whole folder between
  parents remains outstanding.

## Archive Extraction

- ZIP inspection and extraction use PHP's ZipArchive and zlib capabilities.
  Standard ZIP and raw byte-split ZIP (`archive.zip.001`, `.002`, etc.) are
  supported. Numbered raw volumes are joined in a temporary hidden ZIP file
  during the worker job and removed after extraction. The web preview checks
  the split volume sequence but member inspection runs in the background.
  No 7-Zip, unrar or shell-based extractor is invoked.
- Submitting a ZIP queues a job; **bin/background-worker.php** launches in its
  own PHP CLI process (on DSM using the PHP 8.2 CLI) and reports progress.
  The webpage polls a read-only same-origin JSON status endpoint, displaying
  progress bars, job messages and terminal errors. Cancellation is supported.
- ZIP safety limits are now **64 GiB compressed**, **128 GiB expanded** and
  **10,000 entries**. Absolute/traversal paths, duplicates, case collisions,
  symlinks and special members remain forbidden. Entries stream with length
  and CRC32 verification; extraction publishes a **new folder** only after
  complete success, preserving the original archive.
- Encrypted ZIPs use ZipArchive's native password decryption (including AES
  where libzip supports it). Password fields appear only when encryption is
  known; raw split ZIPs may request a password on retry after worker inspection.
  Secrets are private one-use `0600` worker files, deleted after reading.
  Extracted folders use the archive base name with numbered collision suffixes,
  never `-extracted`.
- True multi-disk ZIP (`.z01`, `.z02`, `.zip`), RAR and 7z are
  recognized but **not** yet supported by extraction adapters; raw split ZIP
  is not the same as multi-disk ZIP. Multipart RAR and encrypted RAR/7z remain
  explicit outstanding work.

## Fully native PHP PAR2 verification and repair

The implementation uses the official Parchive 2.0 specification:
https://parchive.github.io/doc/Parity%20Volume%20Set%20Specification%20v2.0.html

- **Par2Reader.php:** Non-destructive whole-set validation shares the Par2Recovery packet catalog, discovering all matching PAR2 index/recovery volumes no matter which volume was selected. Every packet (including recovery slices) receives a streaming MD5 check, then every protected file is checked against its full-file, first-16 KiB and per-slice MD5/CRC32 checksums. Corrupt matching volumes fail verification instead of being silently ignored. The web monitor shows expandable per-file and per-volume details, distinguishing one protected ZIP from its archive contents.
- **Par2Galois.php:** PHP Reed-Solomon arithmetic in GF(2^16), primitive
  polynomial 0x1100B, input coefficients, matrix inversion and binary
  16-bit slice operations. No FFI or non-PHP repair executable.
- **Par2Recovery.php:** Reads matching PAR2 volume packets, solves
  linearly-independent recovery equations, reconstructs bad/missing slices,
  validates per-slice and whole-file hashes and publishes verified files under
  their **exact original names**, allowing downstream archive extraction.
  An existing corrupted source is retained as **filename.ext.damaged**.
  A missing source is created with its original name and no `.damaged` backup.
  An existing `.damaged` backup causes repair to stop without overwriting it.
  Publication includes best-effort multi-file rollback on any failure.
- **Verification outcomes:** Finishing a whole-set scan does not imply that the protected files passed. If any file is damaged or missing, the finished PAR2 Verify job receives status `failed`, its message says **FAILED - Repair required**, and detailed verified/missing/bad-slice counts remain available. The 100% progress bar means scanning completed, not data integrity passed. A successful verify displays **Verified — No repair needed**; a successful repair displays **Repaired**. Pre-existing jobs without whole-set results display **Old scan — Rerun to verify**, instead of claiming a clean check. PAR2/Archive job action forms, dynamically generated rerun/remove controls, and Refresh links preserve the currently browsed folder.
- **BackgroundJobs.php:** Private, bounded JSON queue, separate PHP CLI
  worker, cancellation flag, percent+step callbacks and automatic polling
  from the browser. Finished jobs (completed, failed, cancelled) can be rerun
  as independent new jobs or removed from the private JSON/log history.
  Removal never deletes source or output files. Active jobs cannot be removed
  or rerun; use Cancel and wait for the job to stop first. No cron or DSM
  Task Scheduler is required when Web Station can launch the PHP CLI.
- **Worker:** bin/background-worker.php uses CLI pcntl_fork to detach, not
  a background PHP-FPM web request. The DSM test NAS has php82, proc_open,
  pcntl and posix features available. No par2cmdline or other parity-tool
  executable is used.
- **Streamed native repair:** There is no fixed total-file-size cap, 1 MiB
  slice-size cap or 256 MiB parity-volume cap. Recovery packet bodies are
  indexed by offsets rather than read into memory. Known slices are hashed
  in 64 KiB chunks; independent GF16 equations are solved over 64 KiB windows
  and patched into private staged files. Staged file MD5s are checked before
  atomic no-overwrite publication. Output requires free disk space.
- **Adaptive RAM/disk repair:** no fixed 128-erasure, 1024-file, 3000-volume or
  256 MiB PHP worker cap. Packed GF16 matrices run in memory when their estimated
  usage fits within 90% of available system RAM; otherwise private disk-backed
  matrix rows preserve memory. Preflight checks free disk capacity for the
  complete replacement files and private scratch. PAR2 2.0 still limits input
  slices to 32,768; available recovery slices, CPU, disk and actual RAM also
  constrain feasible work. Synthetic 130-erasure recovery passes; production
  multi-gigabyte sets still require certification.
- A cancelled job stops at the next callback. Published verified filenames and
  `.damaged` backups are not automatically removed. Rerunning a successful PAR2
  repair finds the originals verified and produces no duplicate outputs.
  Rerunning ZIP creates another numbered extraction directory, preserving
  prior extracted data. An encrypted ZIP rerun may use a new password.
- When a PAR2 Verify job finishes with confirmed missing or damaged protected
  data, the monitor displays an immediate **Repair** button. This references
  the failed verification job ID (not a client-supplied filename), validates
  that it is a failed integrity check, then queues a separate PHP-only repair
  worker. The repair independently checks current source slices; if they now
  verify, it creates no .damaged copy or replacement. Generic worker failures
  and successful verifications cannot trigger this shortcut. Available
  *recovery* slices must be at least as numerous and independent as the
  missing/damaged *original* slices; no separate 128-slice application cap
  remains in the PHP solver.

### Verification performed

1. On DEV, specification-conformant synthetic PAR2 volume fixtures recover
   five missing/damaged input slices spanning multiple source files. A 68 MiB
   protected file with one damaged 4 MiB slice was independently reconstructed,
   verified by full-file MD5, and its original was preserved. The same fixture
   also passed a 300 MiB DEV stress run. A 5 GiB FileDesc
   with 512 MiB slice definitions was parsed without size rejection (not a
   completed 5 GiB repair).
2. On the actual DSM website, a POST to the PAR2 Repair module started a
   separate PHP CLI process. The monitor reported **100%** and **two verified
   `.repaired` outputs** after reconstructing five damaged/missing slices
   under the prior publication policy. The new `.damaged` backup plus original
   filename policy is DEV-tested, including a PAR2-to-multipart-ZIP pipeline;
   DSM full repair publication under the new policy is not yet certified.
   All prior temporary DSM test files were removed.
3. A live ZIP background extraction also completed at 100%, with the
   extracted contents validated and temporary data removed. On DEV, raw split
   `.zip.001`/`.002` extraction, encrypted AES ZIP extraction, wrong password
   rejection and password-enabled background reruns all pass. DSM PHP-FPM
   8.2.35 independently decrypted a protected AES ZIP fixture successfully;
   the short-lived probe files were removed.
4. A real NAS PAR2 set was checked via the live DSM HTTP Verify action and
   its independent PHP CLI worker (20 November 2026). The selected index
   belongs to one 143.6 MiB protected ZIP. Verification discovered six
   recovery volumes, validated 103 total PAR2 packets including 39 recovery
   slices, and checked all 393 protected-data slices. The NAS copy was
   identified as having one damaged input slice (index 0); an earlier local
   copy had passed. Hash comparison confirmed the two ZIP copies differ.
   A separate DEV test corrupted one recovery-volume byte and confirmed
   verification rejects that recovery set. No live repair or source-file
   modification was performed during this diagnostic.

**Remaining certification:** Real third-party recovery sets, unusual PAR2
packets, multi-gigabyte repair performance, complex rename mappings and
larger real-world recovery sets still need broader validation. There is no arbitrary 64 MiB
size ceiling, but completion time, disk space and supported PAR2 features
still matter.

## PHP extensions and installer policy

The DSM PHP-FPM 8.2.35 profile was probed directly. It loads DOM, libxml,
XML, XMLReader, XMLWriter, SimpleXML, hash, JSON, fileinfo, cURL, mbstring,
OpenSSL, ZIP and zlib. Neither iconv nor phar is needed for these modules.

The **next** SPK configuration under packaging/dsm7/conf/resource declares
the selectable extensions: curl, dom, fileinfo, mbstring, openssl, simplexml,
xml, xmlreader, xmlwriter, zip and zlib. Core/runtime components libxml,
hash and JSON are verified on the Settings page and are not falsely treated
as optional install-time checkboxes.

**No SPK is built for these source changes.** Build one only when installer
behavior needs testing or the user requests a release.

## Automation, batch merging and extraction destinations (20 November 2026)

- **AutomationScheduler.php** uses a private state file and lock to process scheduled scans. Both schedules start **off**; Settings supports 5, 15, 30 and 60 minutes through 24 hours. Stable NZB files are submitted to Download Station with duplicate suppression. If submission outcome is ambiguous, the record remains pending for manual review to avoid duplicate submission.
- Download completion triggers a conservative global gate: the scheduler skips PAR2 runs while any Download Station task remains active, and requires a finished task and stable input files. Matching individual DSM tasks to individual PAR2 folders is not yet fully certified.
- **bin/automation-worker.php** is a detached native-PHP CLI polling daemon. It starts when Settings enables a schedule and via web access after a restart; the updated package startup script takes effect with the next SPK. The live DSM application source does not yet have a refreshed SPK installed.
- **WorkspaceBatch.php** supports preview/confirm moves of archive/PAR2 files and nested folder-content merging, with skip, numbered-rename or explicitly confirmed overwrite policies and best-effort transactional rollback. Only tracked folders can be renamed/deleted using the existing managed-folder controls; batch merges leave the source directory intact.
- Archive Extraction can target a configured writable default folder. Default behavior creates a numbered directory; Replace preserves the entire prior output directory as a `.previous-*` backup before publishing new, fully checked contents. The user must explicitly authorize replacement.
