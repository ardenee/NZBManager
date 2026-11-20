# NZB Manager DSM 7.2+ password-free test SPK

Build from the repository root:

    python packaging/dsm7/build.py

The SPK is written to dist/ for manual installation using DSM Package Center.
There is NO application login, NO installation wizard, NO password prompt, and
NO hash file. The package creates a package-private no-auth marker and private
folders for NZBs and session data.

IMPORTANT: Web Station does not automatically authenticate DSM users. Anyone
who can reach this portal can use NZB Manager. Keep it within your trusted
LAN and restrict network access through the DSM firewall or another external
access-control layer.

The original 2013 icon files (72x72 and 120x120) are retained in
package.tgz under original-icons/. DSM 7 icons and Web Station icons are
resized from that exact original artwork. The new package also simplifies
PHP-FPM profile configuration after a first-install 502/missing PHP profile.

For an existing 0.2.1-0001 installation without the PHP profile, back up
any private library data and uninstall the previous test SPK before installing
0.2.1-0003. The Web Station service registration is not directly updatable.

The package includes INFO, package.tgz, scripts/, conf/resource,
conf/privilege and the required 64/256px PNG icons. The application payload
contains the public, src and views trees, never the old 2013 PHP source.

Web Station + PHP 8.2 and DSM 7.2+ are the target. The SPK format and file
structure are validated, but real DSM installation and upgrades remain
uncertified. Do not treat this as a full-featured production release.

See docs/DSM7-PACKAGING.md for detailed installation and troubleshooting.
