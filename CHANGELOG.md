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
