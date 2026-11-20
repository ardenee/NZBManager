# Download-processing modules (DSM 7 and Linux)

The NZB Manager **PAR2 Repair**, **Archive Extraction** and **File Organizer**
modules are separate from the NZB Library, Binsearch, Indexer Search and
Push To. They operate on actual downloaded files in a single administrator-
configured workspace, not on the contents of the NZB XML files.

## Configure the workspace

1. Open Settings > Display and folders.
2. Set **Downloaded-files workspace** to an existing shared folder such as
   /volume1/downloads. Avoid system, user-home and web application folders.
3. Save. The modules now display the contents of the chosen folder.
4. Ensure the NZB Manager PHP-FPM execution user has the appropriate filesystem
   read and/or write permissions from DSM Shared Folder permissions/ACLs.
5. The PHP Web Station open_basedir profile must also allow the volume. The
   DSM 0.4.0-0001 SPK declares volumes 1–4; installing it does not grant ACL
   access to arbitrary DSM shares.

The configured path is stored outside the public web root. User requests
cannot select arbitrary absolute paths, traverse outside the workspace or
follow symbolic links. At most 300 entries are listed per folder request, and
folder traversal is limited to eight directory levels.

**No application login:** Any person who can open NZB Manager over the network
could submit folder changes or ZIP extractions. Keep this experimental package
strictly inside a trusted LAN with NAS firewall or an external access-control
layer. Do not expose the site to the internet.

## File Organizer

- Browse workspace folders, including nested directories.
- Select one regular file for a same-workspace rename or move.
- Optionally choose a new filename and an existing destination subfolder.
- First POST creates a **preview** showing old/new paths and bytes. The
  second, CSRF-protected confirmation must be completed within 120 seconds.
- The original must be unchanged since preview. An atomic hard-link
  plus unlink moves the regular file, and never overwrites existing files.
  This requires compatible source/destination filesystem permissions.
- Create a subfolder in the current approved workspace folder.

**Limitations:** Batch operations, moving entire folders, merging payload
fragments, recursive full-disk scans and background file processing are
not yet implemented. The app does not modify arbitrary files outside the
configured workspace.

## Archive Extraction

- ZIP and folders are listed and ZIP contents can be previewed, with a
  limit of **250 members, 32 MiB ZIP size, and 64 MiB expanded bytes**.
- ZIP path traversal, absolute paths, symlinks, special files, encrypted
  members and case-colliding/duplicate entries are rejected.
- The extractor streams members to a newly created, private staging folder,
  then publishes one new extracted folder alongside the original archive.
  Original archives are never deleted or overwritten.
- PHP's **zip** extension is required. The DSM SPK enables it in the
  package's PHP 8.2 profile.

**Limitations:** RAR, 7z and TAR/TGZ are recognized for future adapters but
are not extracted in this release. Large/solid or encrypted archives require
a separate bounded background extraction service; none is claimed here.
No archive content is executed.

## PAR2 Repair

- Browse workspace folders for .par2 recovery index files.
- Read the standard PAR2 packet header and show recovery-set identifiers.
- Queue a verification request only when the **par2cmdline** executable
  is detected. PAR2 jobs are saved in package-private JSON files.
- A **separate CLI worker** runs up to five queued jobs per invocation. It
  invokes a fixed, administrator-selected executable with an argument array,
  without building a shell command from web request text.
- Each job has a 90-second worker limit and bounded captured log output.
- PAR2 repair is **disabled by default**. An administrator who accepts
  the risk of modifying downloaded payloads must explicitly set
  NZB_PAR2_REPAIR_ENABLED=1 for both the web service and worker environments.

The CLI worker script is included inside the package at:

    /var/packages/nzbmanager/target/webapp/bin/par2-worker.php

It must be run periodically with the PHP CLI using **DSM Task Scheduler** or
cron. Locate the installed PHP 8.2 CLI binary on the NAS; do not assume the
Windows DEV PHP path exists on DSM. For illustration, a scheduled command
may have this shape (replace <php82-cli> with the actual executable):

    <php82-cli> /var/packages/nzbmanager/target/webapp/bin/par2-worker.php

The default PAR2 executable search covers /opt/bin/par2, /usr/bin/par2,
and /usr/local/bin/par2. Another executable location can be supplied through
the trusted NZB_PAR2_BINARY server environment. Do not configure
user-supplied executables in the web interface.

**Limitations:** No PAR2 executable is bundled with the SPK. Verification and
repair cannot run until par2cmdline and the CLI worker are installed/scheduled.
The worker and a real PAR2 repair were not exercised on an actual NAS during
the first module implementation.

## Storage and MySQL

The **modern application does not use MySQL**. NZB files, JSON metadata,
settings, Newznab profiles and queued PAR2 jobs are stored in package-private
directories. No database user or password is required to install DSM 7 NZB
Manager. The recovered 2013 application did use MySQL; importing historic
MySQL records is a future optional migration feature, not a runtime
requirement. If a SQL backend is later provided, credentials should be kept
in administrator-controlled private configuration, not in public PHP files
or unprotected browser forms.
