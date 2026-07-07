# Chronicle for Filament

A **read-only** Filament panel plugin for [`laravel-chronicle/core`](https://github.com/laravel-chronicle/core):
browse your tamper-evident audit ledger and **cryptographically verify it - across the
whole chain, a single entry, or a selected segment - without ever being able to rewrite
history**. It is the only Filament audit plugin with cryptographic verification at chain,
entry, and segment granularity.

The panel cannot create, edit, or delete entries: every mutation ability is denied at the
resource and the Gate, there are no create/edit/delete routes, and Chronicle's `Entry`
model is immutable at the data layer. The UI is defence in depth on top of that.

## Screenshots

<!-- TODO: add before tagging 1.0.0 -->
- ![Entry list with verification badges](docs/screenshots/list.png) <!-- placeholder -->
- ![Entry detail (infolist)](docs/screenshots/view.png) <!-- placeholder -->
- ![Verify actions: chain, entry, segment](docs/screenshots/verify.png) <!-- placeholder -->
- ![Verification health widget](docs/screenshots/widget.png) <!-- placeholder -->
- ![Anchor detail + coverage widget](docs/screenshots/anchoring.png) <!-- placeholder -->
- ![Anchor column and Verify-anchor action](docs/screenshots/anchor-verify.png) <!-- placeholder -->
- ![Signing-key column and Active/Retired badge](docs/screenshots/signing-key.png) <!-- placeholder -->
- ![Key-ring summary widget](docs/screenshots/key-ring-widget.png) <!-- placeholder -->
- ![Erasure column with On-hold indicator](docs/screenshots/erasure-column.png) <!-- placeholder -->
- ![Subject erasure detail (state, KEK, erased_at, hold)](docs/screenshots/erasure-detail.png) <!-- placeholder -->
- ![Erase subject (GDPR) confirmation modal](docs/screenshots/erase-modal.png) <!-- placeholder -->
- ![Crypto-shredding stats widget](docs/screenshots/crypto-shredding-widget.png) <!-- placeholder -->

## Requirements

| Requirement              | Supported                              |
|--------------------------|----------------------------------------|
| PHP                      | 8.2, 8.3, 8.4, 8.5                     |
| Laravel                  | 12, 13                                 |
| Filament                 | 4, 5                                   |
| `laravel-chronicle/core` | 1.13+                                  |
| PHP extensions           | `ext-sodium`, `ext-openssl` (required) |

## Installation

```bash
composer require laravel-chronicle/filament
php artisan vendor:publish --tag=chronicle-filament-migrations # publish migrations
php artisan migrate   # run migrations
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=chronicle-filament-config
```

## Panel registration

Register the plugin on your Filament panel provider:

```php
use Chronicle\Filament\ChronicleFilamentPlugin;
use Filament\Panel;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(
            ChronicleFilamentPlugin::make()
                ->navigationGroup('Audit')
                ->navigationSort(99)
                ->slug('chronicle')
                ->verification(true)
                ->anchoring(true) // enable the external-anchor surfaces (defaults to following core)
                ->signingKeys(true) // enable the signing-key surfaces (column/filter, detail badge, key-ring widget)
                // Gate the verify actions independently of read access:
                ->authorize(fn (): bool => auth()->user()?->can('verify-chronicle') ?? false)
                // Optional: override actor/subject display labels (falls back to core's resolver):
                ->labelResolver(fn (string $type, string $id): ?string => null),
        );
}
```

Read (view) access is governed by your panel's normal authorization; the `->authorize()`
closure gates only the chain/entry/segment **verify** actions.

## Configuration reference

`config/chronicle-filament.php`:

| Key                                    | Default                      | Purpose                                                                                                                                                                                                                                                                                             |
|----------------------------------------|------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `entry_model`                          | `\Chronicle\Entry\Entry`     | The Eloquent model the resource reads. Point at a subclass to add accessors/relations. With core >= 1.13 the override is honored end-to-end by core's reader and verifiers when `chronicle.models.entry` matches.                                                                                   |
| `navigation.group`                     | `'Chronicle'`                | Navigation group label.                                                                                                                                                                                                                                                                             |
| `navigation.sort`                      | `null`                       | Navigation sort order.                                                                                                                                                                                                                                                                              |
| `slug`                                 | `'chronicle-entries'`        | Resource route slug.                                                                                                                                                                                                                                                                                |
| `verification.enabled`                 | `true`                       | Master toggle for badges, verify actions, and the health widget.                                                                                                                                                                                                                                    |
| `verification.queue_threshold`         | `1000`                       | Chain/segment verifies covering more than this many entries are dispatched to the queue instead of running synchronously.                                                                                                                                                                           |
| `verification.store.connection`        | `null`                       | Database connection for the plugin-owned verification result store. `null` = the app's default connection.                                                                                                                                                                                          |
| `anchoring.enabled`                    | `null`                       | Master toggle for the anchor surfaces (detail section, Verify-anchor, anchor column/filter, coverage widget). `null` follows core's `chronicle.anchoring.enabled`; set `true`/`false` to force. Hidden everywhere when core anchoring is off.                                                       |
| `anchoring.verify_all_queue_threshold` | `1000`                       | The "Verify all anchors" action runs synchronously at or below this many in-scope checkpoints, and is dispatched to the queue above it.                                                                                                                                                             |
| `signing_keys.enabled`                 | `true`                       | Master toggle for the signing-key surfaces - the "Signing key" column + filter, the ViewEntry Active/Retired badge, and the key-ring widget. Display-only: signature verification stays inside core's chain/entry verifiers; this surface only shows which key signed each entry.                   |
| `crypto_shredding.enabled`             | `null`                       | Master toggle for the read-only crypto-shredding surfaces (erasure column/filter, ViewEntry erasure detail, `subject.erased` trail, the `CryptoShreddingWidget`). `null` follows core's `chronicle.encryption.enabled`; set `true`/`false` to force. Hidden everywhere when core encryption is off. |
| `erasure.enabled`                      | `false`                      | Master toggle for the **Erase subject (GDPR)** action - the panel's only write. **Off by default**; the action is absent and non-routable unless this is `true`, independent of the visibility toggle.                                                                                              |
| `erasure.allow_hold_override`          | `false`                      | Whether a legal hold may be overridden during an erase. **Off by default**; when off, an active `LegalHold` always blocks the erase. When on, the confirmation modal adds a distinct, required override checkbox.                                                                                   |
| `exports.enabled`                      | `true`                       | Master toggle for the verifiable-export surface (Export ledger, Verify export, Download latest export).                                                                                                                                                                                             |
| `exports.disk`                         | `null`                       | Storage disk for export **and** compliance-report artifacts. `null` follows the app's default filesystem disk (`filesystems.default`).                                                                                                                                                              |
| `exports.path`                         | `'chronicle-exports'`        | Directory prefix for export bundles on the exports disk. Compliance reports live under a separate `chronicle-reports/` prefix on the same disk, so the two never mix.                                                                                                                               |
| `exports.queue_threshold`              | `1000`                       | Compliance reports covering more than this many entries are queued instead of running synchronously. Exports are **always** queued regardless.                                                                                                                                                      |
| `reporting.enabled`                    | `true`                       | Master toggle for the signed compliance-report surface (Compliance report, Download latest report). Reports are period-filtered and separately signed by core; gated on `canExport()`.                                                                                                              |

The fluent plugin methods (`navigationGroup`, `navigationSort`, `slug`, `cluster`,
`verification`, `anchoring`, `signingKeys`, `authorize`, `labelResolver`) override the matching config values per panel.

The v1.4 export/reporting surface adds three more fluent gates: `->exports(bool)` /
`isExportsEnabled()` and `->reporting(bool)` / `isReportingEnabled()` toggle the export and
report surfaces, and `->exportAuthorize(Closure)` / `canExport(?Model)` gates them. Because an
export egresses the whole dataset, `canExport()` **defaults to the verify gate**
(`canVerify()`) and can only be **tightened** below it - a non-verifier can never export or
report.

## Verification

Verification is always **deliberate** - nothing verifies on a read or render path. From the
panel you can:

- **Verify chain** (header action) - the full ledger from genesis.
- **Verify entry** (row action) - a single entry.
- **Verify segment** (bulk action) - the selected span, anchored on the enclosing signed
  checkpoints via core's `verifyEntryRange` (never on a selected row's stored hash).

Results are written to a plugin-owned, DB-backed store and surfaced as status badges
(`Verified` / `Failed` / `Unverified` / `Stale`) and a health widget. Verifies covering more
than `verification.queue_threshold` entries run on the queue and notify you on completion.

## External anchoring

When `laravel-chronicle/core` is configured to anchor its signed checkpoints to an
external service - an RFC 3161 timestamp authority, or the
[`laravel-chronicle/anchor-s3`](https://github.com/laravel-chronicle/anchor-s3) adapter -
the panel surfaces that anchoring **read-only**:

- **Detail view** - the entry-detail "External anchoring" section lists the entry's
  checkpoint's anchors (provider, status badge, `anchored_at`, reference, and a copyable,
  truncated proof) from stored status only.
- **Verify anchor** - a deliberate row/header action that runs core's
  `AnchorVerifier::checkpointHasValidAnchor()` for the entry's checkpoint and records the
  outcome. Never runs on render.
- **Verify all anchors** - a deliberate list-page header action that runs
  `AnchorVerifier::verify()` over the in-scope checkpoints, synchronously below
  `anchoring.verify_all_queue_threshold` and on the queue above it (notifying you on
  completion).
- **Anchor column + filter** - an "Anchor" badge column and a state filter derived from
  the stored anchor status.
- **Coverage widget** - a stats widget summarising anchored-vs-total checkpoints, pending
  and failed counts, and the latest anchored time, from cheap table aggregates.

All of these are **deliberate and read-only** - no anchor is ever written, and no provider
verification runs on a read or render path.

Anchoring **requires core anchoring to be configured** and producing `anchored` rows. Enable
the surfaces with `->anchoring(true)` (or `anchoring.enabled` in config); by default they
follow core's `chronicle.anchoring.enabled` and stay hidden when it is off, showing entries
as `Unanchored`.

## Signing keys (key rotation)

Core signs each checkpoint with the active key from its signing **key ring**, and keeps
retired keys in the ring so historical artifacts still verify. When you rotate keys (core's
`chronicle:key:rotate`), the panel surfaces *which key signed each entry* - **read-only**:

- **Signing-key column** - a "Signing key" column showing the entry's `checkpoint.key_id`
  as a state-colored badge: `Active` (the current key), `Retired` (a superseded key that
  still verifies), or `Unsigned` (an entry with no checkpoint). The algorithm and a
  retired-key reassurance show in the tooltip.
- **Filter by signing key** - narrow the audit log to a specific key; options come from
  core's `KeyRing::all()`, labelled `algorithm:keyId` with the active key marked `(active)`.
- **Detail badge** - the entry-detail "Signature" section shows an `Active`/`Retired` badge
  beside the key id, with a hint that a retired key still verifies the entries it signed.
- **Key-ring widget** - a stats widget summarising the active key, the number of keys in the
  ring, how many are retired, and the active key's checkpoint coverage, from cheap aggregates.

This surface is **display-only** - signature verification is already part of chain/entry
verification (core's verifiers call `KeyRing::resolve`), so v1.2 adds **no** new verify
action and nothing signs or verifies on a read or render path. Retired keys deliberately
stay in the ring to verify historical artifacts; see core's
[Signing & Keys](https://github.com/laravel-chronicle/core/blob/main/docs/signing-and-keys.md)
guide on key rotation.

Enable the surfaces with `->signingKeys(true)` (or `signing_keys.enabled` in config); they
are on by default.

## Crypto-shredding & GDPR erasure

When `laravel-chronicle/core` encrypts entry payloads per subject (crypto-shredding), the
panel surfaces that state **read-only** - it reads key/hold *status* only and never unwraps a
DEK, decrypts, or erases on a render path:

- **Erasure column + filters** - an "Erasure" badge column (`Encrypted` / `Erased` /
  `Not encrypted`) with an "On hold" indicator and a KEK / `erased_at` / hold tooltip, plus
  filters by erasure state and by legal hold. Primed once per page in a flat two queries -
  no per-row lookup, no DEK unwrap.
- **ViewEntry erasure detail** - the subject's erasure state, wrapping `kek_id`, `erased_at`,
  and active legal-hold status (reason + placed-at). For an erased subject it states plainly
  that the personal data is permanently unreadable **while the entry stays intact and still
  verifies**.
- **`subject.erased` trail** - an "Erasure proofs only" table preset filtering to
  `action = 'subject.erased'`, surfacing the requester and reason from the proof's metadata.
- **Crypto-shredding widget** - a stats widget summarising encrypted subjects, erased
  subjects, subjects on active legal hold, and the active KEK id, from cheap aggregates.

Enable with `->cryptoShredding(true)` (or `crypto_shredding.enabled` in config); by default it
follows core's `chronicle.encryption.enabled` and stays hidden when encryption is off (every
subject reads `Not encrypted`).

### Erasing a subject (GDPR Article 17)

The panel has exactly **one** write: an opt-in, **off-by-default**, separately-authorized
**Erase subject** action for fulfilling a GDPR Article 17 erasure request.

**The reframed invariant - read this first.** Erasing a subject does **not** modify or delete
any ledger entry. It calls core's `Chronicle::eraseSubject()`, which destroys the subject's
data-encryption key (crypto-shredding) and **appends** a hash-chained, signed `subject.erased`
proof. Existing entries - and their hashes and signatures - are never touched and still verify.
The subject's personal data simply becomes permanently unreadable. The ledger stays immutable.

The action ships **disabled**, and it is impossible to enable or trigger by accident:

- **Off by default.** The action is absent and non-routable unless you turn erasure on with
  `->erasure(true)` (or `erasure.enabled` in config). This is independent of the read-only
  crypto-shredding visibility toggle.
- **Separately authorized.** Even when enabled, the action stays hidden until you grant it
  with `->eraseAuthorize(fn (Model $record) => /* your policy */)`. This gate **defaults to
  deny** and is never the verify/read gate - unset, the action can never run.
- **Confirmation + reason.** The modal requires typing the exact `subject_type:subject_id`
  and a mandatory free-text reason. It is single-subject only - there is no bulk erase.
- **Legal hold.** If the subject is under an active `LegalHold`, the erase is blocked. You can
  permit an override only by turning on `->eraseAllowHoldOverride(true)` **and** accepting a
  distinct override checkbox in the modal; the override is then recorded in the proof metadata.
- **Idempotent.** Re-erasing an already-shredded subject is a friendly no-op, not an error.

Register the gates on the plugin:

```php
use Illuminate\Database\Eloquent\Model;

ChronicleFilamentPlugin::make()
    ->erasure() // enable the action (off by default)
    ->eraseAuthorize(fn (Model $record): bool => auth()->user()?->can('erase-subjects') ?? false)
    ->eraseAllowHoldOverride(); // optional: permit a doubly-confirmed hold override
```

See core's crypto-shredding, GDPR-erasure, and legal-hold guides for the underlying mechanics:
<!-- TODO: cross-link the exact core doc URLs (Crypto-Shredding / GDPR erasure / Legal hold). -->

## Verifiable export & compliance reports

Chronicle for Filament surfaces core's **verifiable export** and **signed compliance
reports** as read-only operator actions on the entry list page. Both are **reads** - the
only things written are artifact files on a storage disk; the ledger is never touched, and
core signs every artifact so it re-verifies.

### Verifiable export

The **Export ledger** header action queues a job that runs core's `ExportManager::export()`,
packages the signed `entries.ndjson` / `manifest.json` / `signature.json` bundle into one
downloadable zip on the exports disk, and notifies you with the entry count and dataset
hash. **Verify export** re-checks any bundle (a prior one on the disk, or an uploaded zip)
under core's `ExportVerifier`, and **Download latest export** streams the newest bundle.

> **⚠ Data-egress note.** An export egresses the **whole dataset** - plaintext for
> unencrypted columns, ciphertext for encrypted fields. Treat an export bundle as sensitive:
> write it only to least-privilege storage. Because of this, exports **and** reports are
> gated on `canExport()`, which **defaults to the verify gate** (`canVerify()`) and is never
> wider - someone who cannot verify can never export. Tighten it further with
> `->exportAuthorize()`.

### Compliance reports

The **Compliance report** header action takes an optional `from`/`to` period (blank covers
the whole ledger) and calls core's `ComplianceReport::generate()`, which summarises ledger
integrity and coverage and signs the report via the key ring. Small reports render inline
immediately and are stored for later download; reports covering more than
`exports.queue_threshold` entries run in the background and notify you when ready.
**Download latest report** streams the newest signed report bundle (`report.html` +
`signature.json`, re-verifiable under core). An empty period is handled with a friendly
notice and stores nothing.

Reports are gated on `->reporting()` **and** `canExport()`, and are read-only: the ledger is
never appended to or mutated; only artifact files are written, and core signs them so they
re-verify. Report bundles live under a separate `chronicle-reports/` prefix on the exports
disk, so they never mix with export bundles.

Enable the surfaces with `->exports(true)` / `->reporting(true)` (or `exports.enabled` /
`reporting.enabled` in config); both are on by default. See core's export,
compliance-report, and export-verification docs for the underlying guarantees. The same
operations are available on the CLI: `chronicle:export`, `chronicle:report`, and
`chronicle:verify-export`.
<!-- TODO: cross-link the exact core doc URLs (Export / Compliance report / Verify export). -->

<!-- Screenshot: Export ledger + Verify export header actions -->
<!-- Screenshot: Compliance report modal with from/to period -->
<!-- Screenshot: Rendered signed compliance report HTML -->

## Theming

The panel uses Filament's native CSS variables and utility classes only - no npm, no asset
compilation, and no required custom theme. It adopts your panel's primary color and dark-mode
settings automatically.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
