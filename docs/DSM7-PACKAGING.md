# Future Synology DSM 7 packaging (deferred)

**No modern SPK builder or built SPK is kept in this repository.**
The historical 2013 SPK files under current/spk/ are archival references only.
Do not run the old lifecycle scripts or install the old SPK as a modern package.

The application is first developed and certified for Linux. Synology will be
an optional release artifact generated automatically from the SAME source
tree, without changing module behavior.

## Release-gated automation plan

1. Finish the compatibility matrix and pass Linux functional/security tests.
2. Verify support for the PHP runtime actually available on target DSM 7
   versions; PHP 8.5 support must NOT be assumed for Synology Web Station.
3. Use a GitHub Actions release workflow (or Synology toolkit build
   container) triggered by an approved release tag, never every source edit.
4. Stage a modern DSM 7 SPK following Synology's official guide: INFO,
   package.tgz, lifecycle scripts, conf/privilege, conf/resource, and correctly
   sized icons. Add optional install UI files only where required.
5. Use DSM7 Web Service resource acquisition to register the app's Web
   Station service and portal. Use the documented nginx_php or apache_php
   settings and the package's PHP profile, runtime identity and data folder.
6. Do not embed credentials, application data or secrets in the SPK.
7. Validate lifecycle and upgrade operations on test DSM 7 hardware/VM,
   including permissions, Web Station migration, backups, uninstall and
   clean reinstallation.
8. Only publish a package after real install, functional and upgrade testing.

The official guide defines the SPK structure, lifecycle files and current
required icons. The Web Service resource describes how DSM7 services and
portal registration are created. Backend PHP identifier values in examples
can be old; verify the current provider against the actual target DSM.

Official references:

- https://help.synology.com/developer-guide/synology_package/introduction.html
- https://help.synology.com/developer-guide/resource_acquisition/web_service.html
- https://help.synology.com/developer-guide/privilege/privilege_config.html

Synology Download Station support is a separate optional application adapter,
not coupled to building an SPK.
