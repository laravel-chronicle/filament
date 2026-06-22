# Changelog

All notable changes to `laravel-chronicle/filament` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
