# DSM 7.2+ test package

Status: TEST PRERELEASE. Built from the Linux-first application source with
packaging/dsm7/build.py. Its archive, lifecycle password-hash script and
application tests passed on DEV. Installation on actual DSM is **unverified**.

## Requirements

- DSM 7.2 or later, with Web Station and PHP 8.2 installed and updated.
- HTTPS access with a trusted browser certificate (avoid internet exposure).
- A separate NZB Manager password, not your DSM login password.

## Build

    python packaging/dsm7/build.py
    python tests/validate-spk.py

Output: dist/NZBManager-0.2.1-0001-noarch-test.spk

## Manual install

1. Update Web Station and PHP 8.2 in DSM Package Center.
2. Package Center > Manual Install > select the test SPK.
3. Enter an NZB Manager password of at least 12 characters in the install
   wizard. Login username is admin.
4. Verify that Web Station registered an NZB Manager service and PHP profile.
5. Visit https://YOUR_NAS/nzbmanager/ or the portal shown by Web Station.
6. Sign in as admin with the password from installation.
7. Test uploading NZBs, inspector, exports, indexer search and Binsearch.
8. Download Station integration appears only if a separately configured
   DSM Task API is reachable. Test its credentials on the NAS separately.

## Design and limits

- INFO declares a noarch package for DSM 7.2+ and Web Station/PHP 8.2.
- conf/privilege runs as the confined nzbmanager user.
- conf/resource registers a PHP 8.2 backend (10), alias portal and app folder.
- Package source is webapp/public, webapp/src and webapp/views; the public/
  folder is the intended web document root.
- Installation wizard sends wizard_nzb_password to postinst. postinst writes
  only a password hash to the private package var/auth.hash, mode 0600.
- Application refuses all requests if the authentication hash is missing.
- Private NZB files and PHP sessions live under the package var directory.
- No old PHP, article payloads or user secrets are included in the SPK.
- Full feature parity and DSM lifecycle/upgrade testing remain outstanding.
- The test package is unsigned. DSM may display a third-party warning.
- The JSON install wizard format needs real DSM testing; DSM 7.2.2 introduced
  an alternative Vue-based wizard format. Missing wizard password causes the
  installer to fail closed.
- Installation requires a DSM OpenSSL CLI supporting the passwd -6 option.
- Before uninstalling, back up NZB library data.
- Do not expose this alpha application to the public internet.

References:
- https://help.synology.com/developer-guide/synology_package/introduction.html
- https://help.synology.com/developer-guide/resource_acquisition/web_service.html
- https://help.synology.com/developer-guide/synology_package/wizard/WIZARD_UIFILES_v2.html
