# Download-processing modules (DSM 7 and Linux)

The NZB Manager **PAR2 Repair**, **Archive Extraction** and **File Organizer**
modules are separate from the NZB Library, Binsearch, Indexer Search and
Push To. They operate on actual downloaded files in a single administrator-
configured workspace, not on the contents of the NZB XML files.

## Configure the workspace

1. Open Settings > Display and folders.
2. Click **Browse folders** next to the downloaded-files workspace, folder
   import or monitored Push To destination. The directory-only picker lists
   eligible DSM volumes and shares, excluding system, home and web shares.
3. Navigate the shared folders. Read/traverse and read/write availability
   are checked using the PHP-FPM execution account. Inaccessible directories
   show a permission warning; write access is mandatory for monitored Push To.
4. Select an accessible existing folder, for example /volume1/downloads.
   Save Settings. Every configured path is rechecked on page load and before
   saving; invalid paths are not silently accepted.
5. If access is denied, update the NZB Manager PHP service account's DSM
   Shared Folder permissions/ACLs. PHP open_basedir must also include the
   volume. The 0.4.1-0001 SPK declares volumes 1–4; installing it does not
   grant filesystem privileges or expand the currently registered PHP profile
   until the service is re-registered.

The configured path is stored outside the public web root. User requests
cannot select arbitrary absolute paths, traverse outside the workspace or
follow symbolic links. Web Station web and web_packages shares and DSM home
shares are prohibited as downloaded-files workspaces, to prevent extraction
of executable files under public web roots. At most 300 entries are listed per folder request, and
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

## PAR2 verification and repair

PAR2 2.0 **verification is implemented entirely in native PHP**. It is
implemented in src/Modules/Par2Reader.php using the published Parchive 2.0
specification:
https://parchive.github.io/doc/Parity%20Volume%20Set%20Specification%20v2.0.html

- Parse the 64-byte packet header and validate the MD5 of each packet.
- Decode Main, FileDesc and IFSC metadata from bounded little-endian fields.
- Check matching recovery-set identifiers, protected filenames and file sizes.
- Compare the protected file's full and first-16-KiB MD5 hashes and, when
  IFSC packets are available, each slice's MD5/CRC-32 (with final zero padding).
- Report verified, damaged or missing protected files. Verification NEVER
  overwrites or repairs files.
- The current native verifier limits individual PAR2 files to 256 MiB,
  protected data to 256 MiB, one protected file to 128 MiB, and a set to
  128 files and 5,000 PAR2 packets. Unsupported large/packed/partial sets
  must be handled by a more complete verifier.

**No par2cmdline executable is needed for native PHP verification.**
However, verification is still a potentially slow workload, so the web
interface queues it for a separate **PHP CLI worker**, not an HTTP request.
Both valid and damaged checksums were tested with synthetic specification-
conformant packet fixtures; real-world PAR2 sets still need certification.

**Repair is not yet native PHP.** Reconstructing damaged slices requires
Reed–Solomon calculations over GF(2^16), recovery-block selection, matrix
inversion and writeback verification. Until that separate implementation is
tested, recovery remains an optional, external par2cmdline operation. It is
**disabled by default**. Enabling repair requires a trusted par2cmdline
executable and NZB_PAR2_REPAIR_ENABLED=1 in the web and worker environments.
No executable may be selected by ordinary website visitors.

PAR2 job records live in package-private JSON storage. The worker is included
inside the SPK at:

    /var/packages/nzbmanager/target/webapp/bin/par2-worker.php

Run the worker periodically using DSM Task Scheduler or Linux cron with
the installed PHP CLI, for example (replace the placeholder):

    <php82-cli> /var/packages/nzbmanager/target/webapp/bin/par2-worker.php

Only for **repair**, a compatible executable is discovered at /opt/bin/par2,
/usr/bin/par2 or /usr/local/bin/par2, or via NZB_PAR2_BINARY in the trusted
worker environment. The external command is executed with a fixed argument
array and a 90-second job limit; user input is never concatenated into a shell
command. A full native PHP Reed–Solomon repairer remains future work.

## Storage and MySQL

The **modern application does not use MySQL**. NZB files, JSON metadata,
settings, Newznab profiles and queued PAR2 jobs are stored in package-private
directories. No database user or password is required to install DSM 7 NZB
Manager. The recovered 2013 application did use MySQL; importing historic
MySQL records is a future optional migration feature, not a runtime
requirement. If a SQL backend is later provided, credentials should be kept
in administrator-controlled private configuration, not in public PHP files
or unprotected browser forms.
