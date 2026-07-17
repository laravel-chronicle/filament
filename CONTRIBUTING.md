# Contributing to filament

This package is a **read-only** Filament panel for the [Chronicle audit ledger](https://github.com/laravel-chronicle/core) - it lets operators browse, verify, export, and (where explicitly enabled) crypto-shred subjects, without ever mutating a ledger entry.

Everything in the [organization-wide contributing guide](https://github.com/laravel-chronicle/.github/blob/main/CONTRIBUTING.md) applies here. This page covers what's specific to this package.

## The invariant, restated

**The panel never mutates a ledger entry.** Entries are appended and hash-chained by core; nothing in this UI may create, update, delete, restore, force-delete, or replicate one. This is enforced in three layers, and a contribution must not weaken any of them:

1. **The model** - core's Entry is immutable.
2. **The policy** - `EntryPolicy` denies every mutation ability unconditionally, for every caller including guests, as defence in depth.
3. **The resource** - `ChronicleEntryResource` overrides Filament's `can*()` methods and never registers create/edit pages.

If a change adds a write to an entry table, it will not be merged. The one legitimate destructive operation - GDPR erasure - does **not** violate this: it destroys a subject's encryption key and *appends* a `subject.erased` proof entry. Existing entries are untouched and still verify. That is the only shape a "deletion" may take here.

## The action guard pattern

Every action beyond plain reading (verify, export, compliance report, erase) must follow the pattern the erase action models. Read `eraseSubjectAction()` in `ChronicleEntryResource` before adding or changing an action - it is the reference implementation. The rules:

- **Visibility is not authorization.** `->visible()` controls whether a button renders. It does **not** stop a crafted request. Every gated action must **re-check its authorization inside `->action()`**, at execution time, and bail with a notification if it fails. The erase action re-checks `canEraseSubject()` even though the button was only visible when permitted - do the same.
- **Read fresh state at execution, not a render snapshot.** The erase action re-reads the legal hold inside `->action()` rather than trusting the value from when the form was built, because state can change between render and submit.
- **Destructive actions require friction.** Type-to-confirm the exact target, a mandatory reason, and - for overriding a legal hold - a *distinct* required confirmation separate from the main one.
- **Gate features behind the plugin flags.** Anchoring, signing-key, and crypto-shredding surfaces are each gated on `ChronicleFilamentPlugin`'s `is*Enabled()` methods, so a deployment that doesn't use a feature never sees its UI. New feature surfaces follow suit.

When you add a test for a new action, test the guard, not just the happy path: assert that the action is denied at execution when the gate is off or the caller isn't authorized, not merely that the button is hidden.

## Setup

```bash
git clone git@github.com:laravel-chronicle/filament.git
cd filament
composer install
composer test
```

`composer test` runs Pint, PHPStan (level 10), then the Pest suite. It uses `orchestra/testbench` to boot a Filament panel in-memory - no external services, no database beyond SQLite. `composer install && composer test` should be green with no further setup.

### Required PHP extensions

This package needs a few extensions that a minimal PHP build may lack:

- **`ext-intl`** - Filament's tables use it for number/date formatting. Without it, table and pagination views throw at render time.
- **`ext-zip`** - export and compliance-report bundles are zip archives.
- **`ext-openssl`** / **`ext-sodium`** - pulled in via core for verification.

If `composer test` fails with `Class "ZipArchive" not found` or `The "intl" PHP extension is required`, install the matching extension - that's an environment gap, not a code failure. These are declared in `composer.json`'s `require` block; keep them in sync if you add a dependency on another extension.

## Standards

Same as the rest of the org, all enforced by `composer test` and in CI:

- **Pest** - the suite must pass.
- **PHPStan level 10** - no new baseline entries.
- **Pint** - style is automated; run `composer lint`, don't hand-tune.

## What good tests look like here

This package's suite is large and organized by surface - table, filters, detail, widgets, actions, jobs - with a spine of invariant tests (`ReadOnlyInvariantTest`, `BrowseSurfaceReadOnlyTest`, `SubjectErasureImmutabilityTest`, `PolicyViewVsVerifyTest`). Match that structure.

Prioritize, in order:

- **The invariant.** Any change near a resource, page, or action should be accompanied by (or covered by) a test asserting no entry was mutated. If you touch the resource, run the read-only invariant tests first.
- **The guard, not the button.** For gated actions, assert denial at execution time when the gate is off or the caller is unauthorized - a hidden button is necessary but not sufficient.
- **Erasure appends, never rewrites.** Any change near erasure must keep `SubjectErasureImmutabilityTest`'s guarantee: existing entries unchanged, a proof entry appended, the chain still verifying.
- **Read-only rendering of decrypted data.** Detail views render through core's decrypted accessors and show an erased-subject indicator; they never expose raw casts or mutate on render.

A test proving a button appears is worth little; a test proving a crafted request can't perform the action behind it is worth a lot.

## Pull requests

Branch from `main`, one concern per branch, and describe the *why*. Note breaking changes explicitly - this plugin tracks Filament's major versions, so a change in supported Filament range is a breaking change and must be called out.

If your change alters what the panel shows or how an action behaves, update the docs at [laravel-chronicle.dev](https://laravel-chronicle.dev) too - for a compliance UI, the docs are what an auditor reads to understand what the panel can and cannot do.

## Questions

[Discussions](https://github.com/orgs/laravel-chronicle/discussions), especially compliance-workflow questions - "how would I show an auditor the verification state of a segment?" is exactly the kind of thread that turns into a better panel.
