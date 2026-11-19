# NZB for Synology - historical source

Recovered from the original NZB 0.1 Synology SPK published on arden.ie (2013).

The PHP application supports inspecting NZB files, choosing individual entries for Synology Download Station, saving NZB data, and running PAR2 repair and RAR unpacking if compatible tools are installed.

## Layout

- current/source/ : extracted PHP, JS, CSS and images from package.tgz
- current/spk/ : Synology INFO, icons and package lifecycle scripts
- ARCHIVE-MANIFEST.json : source package SHA-256 and extraction counts
- original-spk/nzb.spk : ignored, local-only copy of the original historical SPK

## Important compatibility and security notes

This historical code is NOT certified for use with current DSM/PHP. It uses removed mysql_* APIs and obsolete Synology/Web Station integration.

The published historical SPK included a non-default, hard-coded SQL password in db.php. That value has been REMOVED from the Git version of source: the code now uses getenv('NZB_DB_PASSWORD') with a blank fallback. The original package is retained only in the ignored local source folder. Rotate the historical password anywhere it could have been reused.

A future release needs PHP database migration, modern DSM package integration, authentication and input safety checks, download/extraction safety review, and current NZB indexer support.

## Historical site

- https://www.arden.ie/2013/01/04/nzb/
- https://www.arden.ie/2013/03/28/nzb-released/
- https://www.arden.ie/downloads/

## Licence

Historical source is preserved as-found except for the credential sanitisation; no new blanket licence is asserted until source and third-party ownership is reviewed.
