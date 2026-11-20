# DSM 7 PHP profile regression: comparison against working DUP SPK

## Reference

A previously working installer supplied for comparison was
`dup-0.6.3-0009-dsm7-php8-fixes.spk`. It is a conventional tar SPK containing
`INFO`, `conf/resource`, `conf/privilege`, `scripts/`, and `package.tgz`.

Its `conf/resource` explicitly registers a Web Station PHP service:

- `type: nginx_php`
- `php.profile_name: dup PHP 8.2 Profile`
- **`php.backend: 11`**
- `php.user: dup`, `php.group: http`
- `pkg_dir_prepare.user: dup`, `pkg_dir_prepare.group: http`
- `support_alias: true`, `support_server: false`
- `php.open_basedir` includes its own `/var/services/web_packages/dup` root
- `INFO` has `startable=yes`, `reloadui=yes`, `adminurl=dup/`

Its `scripts/postinst` only writes an install log line; it does not manually
register the PHP profile, modify PHP-FPM, call a Web Station API, or install
a language backend. The PHP profile is registered through the package's
Web Station resource configuration, as in Synology's developer documentation.

## Error in previous NZB test packages

NZB Manager 0.2.1-0001 and 0.2.1-0002 specified backend **10** while
labelling their profile PHP 8.2. On the NAS hosting working DUP, backend 11
is the known-working PHP 8.2 value. The NZB alias served static HTML but
returned HTTP 502 for both a trivial PHP script and the real index page.
The package-based PHP profile was absent from Script Language Settings.

## Corrected 0.2.1-0003 package

- Uses **PHP backend 11** and a dedicated `NZB Manager PHP 8.2` profile.
- Sets alias-only mode and startable application metadata like DUP.
- Uses the package-specific `nzbmanager:http` PHP user/group consistently
  with `pkg_dir_prepare`.
- Retains the NZB-specific restricted document root
  `/var/services/web_packages/nzbmanager/public`.
- Keeps installation password-free, with package-private NZB/session data.
- Includes both original 2013 NZB icon files byte-for-byte and derived
  DSM 7/Web Station icon resolutions.
- The SPK builder and verifier **assert backend 11**.

The earlier incorrect backend mapping is a strong explanation for the
missing PHP profile, not a confirmed root cause until DSM installation is
repeated. If 0.2.1-0003 still returns HTTP 502, inspect the Web Station
resource creation and PHP-FPM service logs on the NAS.

## Installation caution

Synology documents Web Station resource acquisition as *not updatable*
via ordinary package upgrade. After backing up private NZBs, remove
the broken test package and install 0.2.1-0003 fresh rather than
assuming an upgrade will re-register the missing profile.

Reference: https://help.synology.com/developer-guide/resource_acquisition/web_service.html
