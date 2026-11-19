# Synology DSM 7 package staging

The primary deployment target is **Linux**. This directory contains a
secondary Synology SPK builder, not a fork of the PHP application.

On Linux or Git Bash:

    bash packaging/synology/build.sh

Output: dist/NZB-0.2.0-0001-noarch.spk.
The archive contains INFO, package.tgz (public/ and src/), scripts/,
conf/privilege and a package icon.

**Important:** this is an untested package staging prototype, not a
validated DSM installer. It does not register a Web Station PHP service,
configure a PHP profile, set NZB_ADMIN_PASSWORD_HASH, grant a PHP-FPM
identity write access to NZB_STORAGE_DIR, or create a Web Station portal.
Configure these for DSM testing before treating the package as deployable.
Keep public/ as the only document root. Check the PHP version available on
the target DSM; it may differ from PHP 8.5.

References:
- https://help.synology.com/developer-guide/synology_package/introduction.html
- https://help.synology.com/developer-guide/privilege/privilege_config.html
- https://help.synology.com/developer-guide/resource_acquisition/web_service.html

Historical packaging files remain in current/spk/.
