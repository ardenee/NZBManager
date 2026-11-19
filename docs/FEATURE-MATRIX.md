# Historical functionality parity matrix

This is a release gate, not marketing copy. A feature counts as **implemented**
only after its new PHP 8.5 implementation is verified with appropriate tests.
The 2013 code remains archived under current/, but is not a runnable fallback
on PHP 8.5. A password-free DSM test SPK is available before full parity; it is
not a production release.

| Original workflow | Modern module | State on 19 Nov 2026 | Notes |
| --- | --- | --- | --- |
| Upload NZB files | Library | **Working** | Multiple files, private storage and validation |
| Upload torrent files | Library / Import adapters | Not ported | Historical upload accepted torrent files |
| Scan an NZB folder | Import / Watches | Not ported | File watching/scanning needs constrained root |
| View NZB files, segments, groups, sizes, metadata | Inspector | **Working** | Includes historical NZBs with safe external DTD declarations |
| Select entries, omit PAR2/NFO/etc and save a new NZB | Inspector | **Working** | Metadata/segments preserved in subset |
| Merge NZB documents | Library | **Working, limited** | Keeps first document header metadata |
| Save/download NZB documents | Library | **Working** | Original XML bytes preserved in direct downloads |
| Rename NZB document | Library | **Working** | Renames library label; XML unchanged |
| Delete NZB documents | Library | **Working** | Only managed private library files |
| Saved file catalog / hashes / MySQL | Library migration | Not ported | New core stores NZB XML with JSON sidecars |
| Search multiple Newznab indexers | Indexer | **Implemented; live unverified** | Multi-provider profiles and selections, t=search; credentials stay on server |
| Download NZB from indexer | Indexer | **Implemented; live unverified** | Newznab t=get by trusted ID, imported into managed library |
| Binsearch.info search and NZB creation | Binsearch | **Working; live verified** | Search and selected NZB generation verified with public site, no key |
| Download Station submission | Download clients | **Implemented, mocked tests only** | Automatically disabled unless DSM API is detected; real DSM task creation not certified |
| Filename filters / auto naming options | Settings / Import | Not ported | Modern validated rule definitions |
| PAR2 scan, verify and repair | Repair | Not ported | Safe background jobs required |
| RAR, ZIP, tar/tgz unpack | Extract | Not ported | Explicit safe destinations, async jobs |
| Rename downloaded files using NZB names | File manager | Not ported | Needs preview and collision handling |
| Rename downloaded directories | File manager | Not ported | Allowlisted root, safe path canonicalization |
| Move files/folders to configured destinations | File manager | Not ported | Must never accept arbitrary absolute paths |
| Merge contents of downloaded directories | File manager | Not ported | Collision strategy required |
| Delete downloaded files/folders | File manager | Not ported | Confirmations and restricted roots required |
| Original settings UI (paths, filters, options) | Settings | Not ported | All settings validated, secrets protected |
| Legacy Synology Web Station UI integration | Platform adapter | Deferred | Not part of Linux runtime |
| Synology DSM7 SPK build | CI/release workflow | **Test SPK built, uninstalled** | Password-free DSM 7.2+ Web Station package; real install/upgrade still needs NAS testing |

## Release requirements

- All required old workflows have parity coverage (or explicit documented
  replacement with equivalent behavior).
- Linux PHP 8.5, nginx/Apache and secure configured storage pass integration.
- Threat-model and test file operations, unpacking, repair jobs and network
  indexer clients.
- README accurately reflects only tested features.
- DSM 7 package automation is introduced **after** feature-complete Linux
  release, using one common application source.

Completed domain test cases: 120. Existing HTTP smoke checks pass. Binsearch live verification passed; live Newznab/Download Station and DSM install/upgrade checks remain pending.
