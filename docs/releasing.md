# Releasing and rolling back

## Before shipping

1. Bump `BYNLI_CONNECT_VERSION` and the `Version:` header in `bynli-connect.php`, the
   `Stable tag:` in `readme.txt`, and add a changelog entry.
2. Cite the pull request number at the end of each changelog bullet, for example
   `(#256)`. Commits that need no user-facing note carry `[no-changelog]` in the
   subject.
3. Run the release check from the repository root:

   ```
   php tools/check-release.php
   php tools/check-release.php --since <previous-release-commit> --strict
   ```

   It fails when the version header, constant, `Stable tag` and changelog heading
   disagree. It reports every commit since the previous release that touches
   `includes/`, `blocks/`, `assets/`, `bynli-connect.php` or `uninstall.php` and has
   no changelog note, so a feature cannot ride out undisclosed. `--strict` turns
   those reports into a failure. Without `--since` the previous release commit is
   found from the version history.
4. Run the tests: `php tests/run.php` (add `-d extension=sodium` so the signature
   tests run).
5. Build the zip from the exact commit being released and compute its digest.
   When a release key is pinned, sign it with `tools/release-sign.php` (see
   `update-integrity.md`).
6. The server manifest must advertise the same version, the zip URL, its
   `download_sha256` and, when keys are pinned, `download_signature`.

`tests`, `tools` and `docs` are `export-ignore`d, so they are not part of the
distributed archive.

## Rolling back

The plugin only offers an update when the manifest version is higher than the
installed one, and WordPress never downgrades a plugin. Withdrawing a release
therefore works only for sites that have not yet updated.

| Site state | What happens when the manifest is reverted to the previous version |
| --- | --- |
| Not yet updated | Never sees the bad release. The cached manifest can lag up to 12 hours on the site and a few minutes at any public cache in front of the server. |
| Already updated | Stays on the bad release. WordPress files the older version under "no update". |

Procedure:

1. Stop the spread: revert the manifest to the last good version (or set it to
   the next patch version, step 3, as soon as that is ready).
2. Fix forward: revert the offending change on `main`, bump to the next patch
   version (never reuse a version number), update the changelog with a plain
   statement of what was reverted and why, run the release check, build, publish.
   Already-updated sites receive it as a normal update, within 12 hours or
   immediately via Settings, Bynefit Connect, Check for updates now.
3. For an individual site that cannot wait, reinstall the previous zip by hand:
   deactivate the plugin, replace the `bynli-connect` directory with the contents
   of the previous versioned zip, reactivate. Every earlier versioned zip stays
   published for this.

Do not delete or overwrite a published versioned zip: a site may be part-way
through downloading it, and the manifest digest and signature are bound to those
exact bytes.
