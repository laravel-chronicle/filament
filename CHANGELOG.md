# Changelog

All notable changes to `laravel-chronicle/filament` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

`laravel-chronicle/filament` v1.3 - crypto-shredding / GDPR erasure. Building on the
read-only v1.2 panel, v1.3 surfaces core's crypto-shredding state (encryption / erasure /
legal hold / KEK) **read-only** and adds one opt-in, off-by-default, separately-authorized
**Erase subject (GDPR)** action. The ledger stays immutable: erasure destroys the subject's
DEK in the key store and **appends** a hash-chained, signed `subject.erased` proof - existing
entries, their hashes, and their signatures are never altered and still verify.

### Added

- Confirmed core 1.13's crypto-shredding surface - `Chronicle::eraseSubject()` (DEK destroy + appended `subject.erased` proof, idempotent), the `SubjectKey` model (`status`/`erased_at`/`kek_id`, readable without unwrapping a DEK), `SubjectKeyManager::stateFor`, `LegalHold::{isHeld,scopeActiveFor}`, `KeyEncryptionManager::provider()->kekId()`, and `Entry::erased()` - recorded in `docs/chronicle-filament-v1.3-S1-core-confirmation.md`.
- `Support\ErasureState` - string-backed enum (`encrypted`/`erased`/`not_encrypted`) that is the single source of truth for the erasure badge color, icon, and label, mirroring `VerificationState`/`AnchorState`/`SigningKeyState`. Derived from a subject's `SubjectKey` status only; legal hold is carried separately. Never unwraps a DEK, decrypts, or erases.
- `Support\SubjectErasureStore` - a per-page priming store that batch-reads core's `SubjectKey` (status/erased_at/kek_id) and active `LegalHold` rows for a page's distinct `(subject_type, subject_id)` pairs in two queries total, memoised; per-entry `stateFor()`/`isHeld()`/`erasedAtFor()`/`kekIdFor()` are then query-free. Reads status only - no DEK unwrap, no decrypt, no erase; degrades to `NotEncrypted`/unheld when no rows exist. Adds the `TestCase::enableEncryption()` fixture.
- crypto-shredding / erasure config + gates. New `crypto_shredding.enabled` (null follows `chronicle.encryption.enabled`), `erasure.enabled` (default **false**), and `erasure.allow_hold_override` (default **false**) config blocks. New `ChronicleFilamentPlugin` fluent toggles + getters: `->cryptoShredding()`/`isCryptoShreddingEnabled()`, `->erasure()`/`isErasureEnabled()`, `->eraseAllowHoldOverride()`/`isEraseHoldOverrideAllowed()`, and `->eraseAuthorize()`/`canErase()` - the erase gate is **off by default and denies by default**, separate from the verify gate, and unreachable unless both the flag and an authorize closure are set.
- `ChronicleEntryResource` "Erasure" table column + filters: a `SubjectErasureState`-colored badge (Encrypted / Erased / Not encrypted) with an "On hold" indicator and a KEK/erased-at/hold tooltip, plus filters by erasure state and by legal hold (correlated `whereExists`/`whereNotExists` over core's `SubjectKey`/`LegalHold`). State comes from the `SubjectErasureStore`, now primed once per render as a container singleton - the column renders in a flat two queries, with no per-row lookup and no DEK unwrap. Gated on `->cryptoShredding()`; hidden when core encryption is off.

---
## [1.2.0] - 2026-06-25

`laravel-chronicle/filament` v1.2 - key-rotation visibility. Building on the read-only
v1.1 panel, v1.2 surfaces which signing key signed each entry (from its checkpoint): an
Active-vs-Retired state, a per-key view, and a key-ring summary. It is **display-only** -
signature verification already happens inside core's chain/entry verifiers, so v1.2 adds
**no** new verify action and the read-only invariant is unchanged.

### Added

- `Support\SigningKeyState`: string-backed enum (`active`/`retired`/`unsigned`) - the single source of truth for signing-key badge color, icon, and label, mirroring `VerificationState`/`AnchorState`. Derived from a checkpoint's stored `(algorithm, key_id)` versus core's active signing key; never runs a provider sign/verify.
- `Support\KeyRingSnapshot`: a read-only snapshot of core's signing key ring built from `KeyRing::all()` + `active()`. Lists configured keys (algorithm, keyId, active flag) keyed `"{algorithm}:{keyId}"`, derives a checkpoint's / entry's `SigningKeyState` by comparing its stored `(algorithm, key_id)` to the active key (non-active -> `Retired`, no checkpoint -> `Unsigned`), and reports cheap per-key checkpoint counts from one grouped aggregate. Reads provider metadata only - no `sign()`/`verify()`, no per-row query.
- `signing_keys` config block in `config/chronicle-filament.php`: `enabled` (default `true`) - the master toggle for the v1.2 signing-key surfaces (column/filter, detail badge, key-ring widget), wired in K2/K3.
- `ChronicleFilamentPlugin::signingKeys(bool)` fluent toggle plus `isSigningKeysEnabled()` getter (override -> `signing_keys.enabled` config, default true), mirroring the verification and anchoring gates.
- `ChronicleEntryResource` "Signing key" table column: shows the entry's `checkpoint.key_id` as a `SigningKeyState`-colored badge (Active/Retired) with the algorithm and a retired-key reassurance in the tooltip; degrades to an `Unsigned` placeholder when the entry has no checkpoint. Derives state via `KeyRingSnapshot::forEntry()` from the already eager-loaded `checkpoint` - no `sign()`/`verify()`, no per-row query. Toggleable and gated on `->signingKeys()`.
- `ChronicleEntryResource` "Signing key" table filter: options come from `KeyRingSnapshot::keys()` (core's `KeyRing::all()`), labelled `"{algorithm}:{keyId}"` with the active key marked `(active)`; selecting one narrows the table via `whereHas('checkpoint', algorithm = ..., key_id = ...)`. Gated on `->signingKeys()`. Adds the `TestCase::registerRetiredKey()` and `TestCase::retireCheckpoint()` fixtures backing the K2 signing-key tests.
- `ChronicleEntryResource` ViewEntry Signature section signing-key badge: a `SigningKeyState` Active/Retired badge beside the key id, plus a retired-key hint ("Retired key - still verifies historical entries.") shown only for retired keys. Derived from the checkpoint via `KeyRingSnapshot::forEntry()`; hidden when the entry is unsigned or `->signingKeys()` is off. Read-only - no new affordance, no provider verify.
- `Widgets\SigningKeyRingWidget`: a `StatsOverviewWidget` on the list-page header summarising core's signing key ring from `KeyRingSnapshot` - the active key (`algorithm:keyId`), the number of keys in the ring, how many are retired, and the active key's checkpoint coverage (checkpoints signed by the active key vs total). Built from provider metadata plus one grouped checkpoint aggregate; no `sign()`/`verify()` on load, no per-row query. Gated on `->signingKeys()`.
- Rotated-ledger signing-key sweep: a `TestCase::seedRotatedLedger()` fixture that seeds a 9-entry ledger across a real `chronicle:key:rotate` (key A -> key B), giving Active-signed, Retired-signed, and Unsigned entries, plus a `SigningKeyRotationSweepTest` covering snapshot/state derivation, the column + filter, the detail badge, the key-ring widget, the no-per-row-query / no-verification-on-render guard, the `->signingKeys(false)` gate hiding every surface, and an explicit re-assertion of the read-only invariant.

---

## [1.1.0] - 2026-06-24

`laravel-chronicle/filament` v1.1 - external anchoring. Building on the read-only v1.0
panel, v1.1 surfaces core's external checkpoint anchoring: a read-only anchor detail
section, a deliberate Verify-anchor action, an anchor status column + filter, an
anchor-coverage widget, and a deliberate "Verify all anchors" action (sync or queued).
Anchor verification is always deliberate and read-only - the panel still can never rewrite
history, and nothing verifies on a render path.

### Added

- `Support\AnchorState`: string-backed enum (`anchored`/`pending`/`failed`/`unanchored`/`invalid`) - the single source of truth for external-anchor badge color, icon, and label. Static helpers `fromStatuses()`, `forCheckpoint()`, and `forEntry()` derive state from core's stored `CheckpointAnchor.status` only (precedence anchored > failed > pending > unanchored), never running a provider verification; an entry with no checkpoint and the anchoring-disabled / no-rows case both map to `Unanchored`, never an error.
- `anchoring` config block in `config/chronicle-filament.php`: `enabled` (null = follow core's `chronicle.anchoring.enabled`, or force `true`/`false`) and `verify_all_queue_threshold` (default 1000) - gating the v1.1 anchor surfaces wired in A2/A3. Default everything hidden when core anchoring is off.
- `ChronicleFilamentPlugin::anchoring(bool)` fluent toggle plus `isAnchoringEnabled()` (override -> plugin config -> core `chronicle.anchoring.enabled`) and `getVerifyAllQueueThreshold()` getters, mirroring the verification gate.
- Test fixtures for external anchors: `TestCase::enableAnchoring()` (turns on core anchoring with the in-DB `NullAnchor` provider so `isAnchoringEnabled()` follows core) and `TestCase::seedAnchor()` (attaches valid or tampered `CheckpointAnchor` rows to a seeded checkpoint), backing the A2 anchor-surface tests.
- `ChronicleEntryResource` ViewEntry "External anchoring" section: renders the entry's checkpoint's anchors read-only - per anchor the provider, an `AnchorState` status badge, `anchored_at`, `reference`, and a copyable/truncated `proof` - from stored `CheckpointAnchor.status` only (no provider verification on render). Degrades to "Unanchored" (no checkpoint), "No anchors" (checkpoint with no rows), or "Anchoring not configured" (anchoring disabled). The detail eager-load widens from `checkpoint` to `checkpoint.anchors`, keeping the section N+1-free.
- `Support\VerificationResultStore` gains an `anchor` scope: `recordAnchor()` persists a deliberate anchor-verification outcome keyed per checkpoint in the existing `chronicle_filament_verification_records` store (no new migration), and `anchorState()`/`anchorRecord()` read it back as `AnchorState::Anchored` (verified), `Invalid` (failed), or `Unanchored` (never verified) - bypassing chain-head staleness, which does not apply to anchors.
- Deliberate Verify-anchor action: a `ChronicleEntryResource::verifyAnchorAction()` shared by the table row and the ViewEntry header, calling core's `AnchorVerifier::checkpointHasValidAnchor()` (never on render), recording the outcome to the store, and notifying success or a non-destructive failure (decoding `AnchorInvalid`; provider errors are caught and surfaced, never thrown). Hidden when the entry is unanchored, anchoring is disabled, or the plugin `->authorize` closure denies it. The read-only invariant is unchanged - it reads and records only.
- Anchor table column + filter: an "Anchor" badge column derived from the checkpoint's stored anchor `status` via `AnchorState::forEntry()` - reading the `checkpoint.anchors` eager-load, so it issues no provider verify and no per-row query - plus a `SelectFilter` by anchor state (anchored/pending/failed/unanchored, respecting the anchored > failed > pending precedence). Both are hidden when anchoring is disabled or `->anchoring(false)`.
- `Widgets\AnchorCoverageWidget`: a `StatsOverviewWidget` on the list page summarising external-anchor coverage from cheap checkpoint table aggregates - checkpoints with an `anchored` anchor vs total, plus checkpoint-level `pending` and `failed` counts (respecting the anchored > failed > pending precedence) and the latest `anchored_at` - reading stored anchor `status` only, never running a provider verification on load. Mounted beside the verification health widget and hidden via `canView()` when anchoring is disabled.
- Deliberate "Verify all anchors" header action: runs core's `AnchorVerifier::verify()` over the in-scope checkpoints (those carrying anchor rows) - synchronously below `anchoring.verify_all_queue_threshold` and via a queued `Jobs\VerifyAnchorsJob` above it, reusing the v1.0 queued-verify pattern and notifying the initiating user on completion (decoding `AnchorInvalid`). Gated behind the plugin `->authorize` closure and hidden when anchoring is disabled. Read-only - it reads and notifies, never mutating.
- Hardened the v1.1 anchor test sweep: coverage-widget counts and the no-provider-verify-on-load guard, the queued "Verify all anchors" path (faking the queue) and its job notification, and an explicit re-assertion that the read-only invariant still holds - the list page exposes only the read-only `verifyChain`/`verifyAllAnchors` header actions, and no mutating route or action was added.
- README anchoring documentation for v1.1: the read-only detail anchor view, the deliberate Verify-anchor and "Verify all anchors" actions, the anchor column/filter, the coverage widget, the `->anchoring()` toggle and hidden-when-disabled behaviour, the new `anchoring.enabled` / `anchoring.verify_all_queue_threshold` config rows, the note that anchoring requires core anchoring configured (RFC 3161 TSA or the `anchor-s3` adapter), and anchor screenshot placeholders. Compatibility unchanged: core 1.13+.

---

## [1.0.0] - 2026-06-22

`laravel-chronicle/filament` v1.0 - a read-only Filament v4/v5 panel for
`laravel-chronicle/core` 1.13+: browse the tamper-evident audit ledger and deliberately
verify it across the whole chain, a single entry, or a selected segment, with results
surfaced as status badges and a health widget. The panel can never rewrite history.

### Added

- Package manifest (`composer.json`) for `laravel-chronicle/filament`: PHP 8.2+, Filament 4/5, Laravel 12/13, `laravel-chronicle/core` ^1.13, with Pest/PHPStan/Pint tooling and `composer test` scripts.
- `ChronicleFilamentServiceProvider` (Spatie package-tools) publishing `config/chronicle-filament.php` - navigation group/sort, slug, verification toggle + queue threshold + result-store connection, and `entry_model` (default `\Chronicle\Entry\Entry::class`) - plus a `chronicle-filament` views namespace.
- Orchestra Testbench + Pest test harness (`tests/TestCase.php`, `tests/Pest.php`, `phpunit.xml.dist`) covering provider auto-discovery, config defaults, and config publishing.
- Static-analysis and code-style gate: PHPStan via Larastan at level 10 with no baseline (`phpstan.neon.dist`) and Laravel Pint on the default preset.
- GitHub Actions CI: test matrix of PHP 8.2/8.4/8.5 × Filament 4/5 × Laravel 12/13 (sodium + openssl on every leg, Livewire v4 on Filament 5), plus PHPStan and Pint (`--test`) checks and a Pint auto-fix workflow.
- MIT `LICENSE` and a placeholder `README.md` describing the read-only positioning and the requirements matrix.
- `ChronicleFilamentPlugin` (`Filament\Contracts\Plugin`): fluent panel configuration - `navigationGroup()`, `navigationSort()`, `slug()`, `cluster()`, `verification()`, `authorize()` (gates the verify actions), and `labelResolver()` - each defaulting from `config/chronicle-filament.php`.
- Read-only `ChronicleEntryResource`: resolves its model from `chronicle-filament.entry_model`, appears in panel navigation (group/sort/slug/cluster from the plugin), and exposes only `ListEntries` + `ViewEntry` pages - no Create/Edit pages, so no mutating routes exist. Every mutation ability (`canCreate`/`canEdit`/`canDelete`/`canDeleteAny`/`canReplicate`) is hard-denied.
- `EntryPolicy` registered for the resolved entry model: allows `viewAny`/`view`, and denies `create`/`update`/`delete`/`deleteAny`/`restore`/`forceDelete`/`replicate` unconditionally for any caller - Gate-layer defence in depth behind the resource overrides and the model's immutability. The plugin's `authorize()` closure gates the verify actions independently of read access.
- A configured `Entry` subclass (`chronicle-filament.entry_model`) is used consistently by the resource's `getModel()`/`getEloquentQuery()`, so hosts that extend `Chronicle\Entry\Entry` browse and (Session 4) verify their own model.
- Read-only invariant guard test: asserts the resource exposes no `create`/`edit`/`delete` routes and that mutation is denied at both the resource and Gate layers - designed to fail loudly if a mutating route or action is ever added.
- Test harness now loads `laravel-chronicle/core` migrations and refreshes the database per test, with a `seedLedger()` helper built on core's `LedgerSeeder` (eloquent driver) so read-path tests run against a genuine, verifiable hash chain.
- `Support\ReferenceLabel`: query-free actor/subject display labels - applies the plugin's `->labelResolver()` override, then delegates to core's `Chronicle::resolveReference()` (honours `Relation::morphMap()`, no forced query), preserving the no-N+1 guarantee.
- `Support\PreviousHash`: resolves an entry's previous chain hash (the prior entry's `chain_hash` by `sequence - 1`, genesis `'0'`) in a single indexed query - there is no `previous_hash` column.
- Entry browse table on `ChronicleEntryResource`: columns for sequence #, recorded time, action (badge), resolved actor and subject labels, and a verification-status slot; filters for action, actor type, subject type, recorded date range, and verification status (the last two backed in Session 4). Defaults to `sequence desc`, defers loading, persists filters in session, and eager-loads the checkpoint so rendering stays N+1-free at volume.
- Entry detail view (`ViewEntry` infolist): collapsible Identity, Integrity, Signature, Payload, and Decrypted sections. Current/previous/payload hashes (previous = prior entry's `chain_hash`, genesis `'0'`); signature, algorithm, and key id read from the entry's checkpoint (placeholder when unanchored); `metadata`/`context`/`diff` rendered through the model's `decrypted*()` accessors with an erased-subject indicator. Read-only - no edit or delete affordances.
- Browse-surface read-only guard test (list + view render against seeded data with no mutating actions) and confirmation that the panel requires no asset build - native Filament CSS variables and classes only, adopting the host panel's primary color and dark mode.
- `Support\VerificationState`: string-backed enum (`verified`/`failed`/`unverified`/`stale`) - the single source of truth for verification badge color, icon, and label across the table, filter, and health widget.
- Plugin-owned, DB-backed verification result store: a `chronicle_filament_verification_records` migration (on the configurable `verification.store.connection`) keyed per entry and per chain, plus the `Support\VerificationRecord` model recording state, failure code, first-failed entry id, checked count, chain head sequence at verification, and last-verified timestamp - so badge state never depends on core's resume-only `VerificationRun`.
- `Support\VerificationResultStore` (singleton): records chain and single-entry verification outcomes to the store and reads back the effective state - `verified` / `failed` / `unverified` (no record) / `stale` (verified before later entries were appended). Batch-primes a page of entries in one query so badge rendering issues no per-row queries.
- Verification badge column now reads the result store: green `Verified`, red `Failed`, gray `Unverified`, amber `Stale`, with a tooltip showing the last-verified time and the decoded failure case. The list page primes the store once per render, so badges add no per-row queries and **no verifier runs on table render**.
- Verification-status table filter (`verified` / `failed` / `unverified` / `stale`) now queries the result store - completing the Session 3 stub - narrowing the audit log by stored verification state without a cross-connection join.
- Row "Verify" action: runs core's `EntryVerifier` for a single entry, records the result to the store (refreshing its badge), and surfaces a success or non-destructive failure notification with the decoded failure case. Gated behind the plugin `->authorize` closure, independently of view access.
- Header "Verify chain" action: runs core's `IntegrityVerifier` over the full ledger, recording the result to the store. Runs synchronously below `verification.queue_threshold` and dispatches a queued `VerifyLedgerJob` above it, notifying the initiating user on completion. Gated behind the plugin `->authorize` closure.
- Bulk "Verify segment" action: reduces the selection to `[minSequence, maxSequence]` and calls core's `IntegrityVerifier::verifyEntryRange()` (CORE-B) - which anchors on the enclosing signed checkpoints, never a selected row's stored hash. Detects a tampered row inside the span (records `failed` with the first-failing entry id), runs sync below the queue threshold and queues above it. Gated behind the plugin `->authorize` closure.
- `VerificationHealthWidget`: a stats widget on the list page showing the chain's stored status and last-verified time plus a cheap `CheckpointChainVerifier` spine check (O(#checkpoints), no full re-hash on load). Surfaces the first detected gap rather than re-walking every entry.
- README rewritten for v1.0: positioning lead (read-only, cannot rewrite history, the only Filament audit plugin with chain/entry/segment cryptographic verification), install + panel-registration snippet, full config reference (`entry_model`, navigation, slug, `verification.enabled`/`queue_threshold`/`store.connection`), compatibility matrix (PHP 8.2/8.4/8.5, Filament 4 & 5, Laravel 12 & 13, core 1.13+, `ext-sodium`/`ext-openssl` required), and screenshot placeholders.
- Hardened the v1.0 test sweep: rendered-badge coverage for every stored status, a read-vs-verify separation guard, a `ViewEntry` no-header-action guard, and a confirmed-green gate (full Pest suite + PHPStan level 10 + Pint) across the CI matrix.

[Unreleased]: https://github.com/laravel-chronicle/filament/compare/1.2.0...HEAD
[1.2.0]: https://github.com/laravel-chronicle/filament/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/laravel-chronicle/filament/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/laravel-chronicle/filament/releases/tag/1.0.0
