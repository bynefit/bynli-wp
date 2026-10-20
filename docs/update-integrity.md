# Update integrity

How the plugin decides an update package is genuine, what each layer does and
does not defend against, and what the release server has to publish.

## Layers

1. **Transport.** The release manifest (`/api/site-host/version`) and the package
   are fetched over HTTPS with certificate verification on. The API base must be
   `https://`; plain `http://` is accepted only from the `BYNLI_CONNECT_API_BASE`
   constant and only for `localhost`, `127.0.0.1`, `[::1]`, `*.test` and
   `*.localhost`.
2. **Host check.** The package URL must be on the host the plugin talks to
   (`BYNLI_CONNECT_RELEASE_HOST` overrides it).
3. **Checksum.** The manifest carries `download_sha256`. A present value that
   does not match the downloaded file refuses the update before anything is
   unpacked. An absent, null or malformed value is treated as unverifiable and the
   update proceeds, with a log line saying so.
4. **Signature.** The manifest may carry `download_signature`, a base64 Ed25519
   detached signature. Once any public key is pinned in the plugin, a valid
   signature is mandatory: an update is neither offered nor installed unless the
   signature verifies against a pinned key.

## What each layer defends against

The checksum and the package URL come from the same unauthenticated manifest.
Alone, the checksum covers a corrupted or truncated download, a package host that
differs from the manifest host, and a release built from the wrong bytes. It does
not cover anyone who can alter the manifest response, because they supply the URL
and the hash together.

The signature closes that gap. The private key is never on the web server that
serves the manifest, so a party who controls the manifest and the package still
cannot produce a signature the plugin will accept.

## Signed message

```
bynli-connect-release/v1
<version>
<download_sha256 lowercase>

```

The signed message does not include `download_url`. The URL is covered indirectly:
it must be on the pinned release host, and the downloaded bytes must match the
signed `download_sha256`. Pointing the URL elsewhere can only make the download
fail; it cannot substitute different bytes.

## Replay protection

The version is inside the signed message, so a signature cannot be moved onto a
different version or hash. The plugin also only offers a version higher than the
installed one. What is not prevented: replaying an older, validly signed manifest
to a site that is behind it. `Bynli_Connect_Release_Signature::MIN_VERSION` is the
mitigation. A signed manifest for a version below that floor is refused, so raise
the floor in any release that fixes a vulnerability. There is no issued-at or
expiry field, so a replay above the floor is not detected.

## Keys and rotation

Pinned keys live in `Bynli_Connect_Release_Signature::PINNED_KEYS` (base64 public
keys). Any pinned key may verify a signature, which allows rotation: ship a plugin
release that pins both the old and the new key, sign with the old key until that
release has propagated, then switch signing to the new key and drop the old one
in a later release. A site-specific key can be added through the
`BYNLI_CONNECT_RELEASE_KEYS` array constant in `wp-config.php`.

A configured key that is not a base64 32-byte Ed25519 public key is ignored and
logged once; it does not count towards enforcement.

When enforcement is on, every path that would let WordPress install our package
without verification is refused: a package supplied by another filter, a missing
package URL, a package on our host that the manifest does not describe, and a
failed download.

Sites that have not yet received a plugin release that pins a key behave as
before (checksum only). Nothing is enforced while `PINNED_KEYS` is empty.

## Tooling

```
php tools/release-sign.php keygen
php tools/release-sign.php sign 0.26.0 bynli-connect-0.26.0.zip secret.key
php tools/release-sign.php verify 0.26.0 bynli-connect-0.26.0.zip <signature> <public-key>
```

Requires the PHP `sodium` extension. Keep the secret key offline.

## What the server must publish

- `download_signature` in the version manifest, produced at release time from the
  built zip with the offline key. Existing manifest keys are unchanged.
- `download_sha256` for every release (the signature covers it).
