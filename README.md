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

| Key                              | Default                      | Purpose                                                                                                                                                                                                           |
|----------------------------------|------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `entry_model`                    | `\Chronicle\Entry\Entry`     | The Eloquent model the resource reads. Point at a subclass to add accessors/relations. With core >= 1.13 the override is honored end-to-end by core's reader and verifiers when `chronicle.models.entry` matches. |
| `navigation.group`               | `'Chronicle'`                | Navigation group label.                                                                                                                                                                                           |
| `navigation.sort`                | `null`                       | Navigation sort order.                                                                                                                                                                                            |
| `slug`                           | `'chronicle-entries'`        | Resource route slug.                                                                                                                                                                                              |
| `verification.enabled`           | `true`                       | Master toggle for badges, verify actions, and the health widget.                                                                                                                                                  |
| `verification.queue_threshold`   | `1000`                       | Chain/segment verifies covering more than this many entries are dispatched to the queue instead of running synchronously.                                                                                         |
| `verification.store.connection`  | `null`                       | Database connection for the plugin-owned verification result store. `null` = the app's default connection.                                                                                                        |

The fluent plugin methods (`navigationGroup`, `navigationSort`, `slug`, `cluster`,
`verification`, `authorize`, `labelResolver`) override the matching config values per panel.

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

## Theming

The panel uses Filament's native CSS variables and utility classes only - no npm, no asset
compilation, and no required custom theme. It adopts your panel's primary color and dark-mode
settings automatically.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
