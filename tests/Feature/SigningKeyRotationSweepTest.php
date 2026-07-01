<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Chronicle\Filament\Support\KeyRingSnapshot;
use Chronicle\Filament\Support\SigningKeyState;
use Chronicle\Filament\Widgets\SigningKeyRingWidget;
use Chronicle\Verification\EntryVerifier;
use Chronicle\Verification\IntegrityVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

it('derives active, retired, and unsigned state across a rotation', function () {
    $this->seedRotatedLedger();
    $snapshot = KeyRingSnapshot::make();

    $retired = Entry::query()->where('sequence', 2)->firstOrFail();  // key A
    $active = Entry::query()->where('sequence', 6)->firstOrFail();   // key B
    $unsigned = Entry::query()->where('sequence', 9)->firstOrFail(); // no checkpoint

    expect($snapshot->forEntry($active))->toBe(SigningKeyState::Active)
        ->and($snapshot->forEntry($retired))->toBe(SigningKeyState::Retired)
        ->and($snapshot->forEntry($unsigned))->toBe(SigningKeyState::Unsigned);
});

it('shows both the active and retired key ids in the column', function () {
    $this->seedRotatedLedger();

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->assertOk()
        ->assertSee('rotated-key')
        ->assertSee('chronicle-dev-key');
});

it('filters entries by the active rotated key', function () {
    $this->seedRotatedLedger();
    $active = Entry::query()->where('sequence', 6)->firstOrFail();
    $retired = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('signing_key', 'ed25519:rotated-key')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$retired]);
});

it('filters entries by the retired key', function () {
    $this->seedRotatedLedger();
    $active = Entry::query()->where('sequence', 6)->firstOrFail();
    $retired = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('signing_key', 'ed25519:chronicle-dev-key')
        ->assertCanSeeTableRecords([$retired])
        ->assertCanNotSeeTableRecords([$active]);
});

it('shows Active vs Retired badges in the detail view across the rotation', function () {
    $this->seedRotatedLedger();
    $active = Entry::query()->where('sequence', 6)->firstOrFail();
    $retired = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $active->getKey()])
        ->assertOk()
        ->assertSee('Active');

    Livewire::test(ViewEntry::class, ['record' => $retired->getKey()])
        ->assertOk()
        ->assertSee('Retired')
        ->assertSee('still verifies historical entries');
});

it('summarises the rotated ring in the widget', function () {
    $this->seedRotatedLedger();

    Livewire::test(SigningKeyRingWidget::class)
        ->assertOk()
        ->assertSee('ed25519:rotated-key') // active key after rotation
        ->assertSee('Retired keys')
        ->assertSee('2 keys in the ring'); // dev key (retired) + rotated key (active)
});

it('renders the signing-key surfaces without per-row queries or any verification', function () {
    $this->seedRotatedLedger();

    // Binding throwing verifiers proves the signing-key surfaces never trigger
    // chain/entry verification on render (mirrors the verification-badge guard).
    $this->app->bind(IntegrityVerifier::class, fn () => new class
    {
        public function verify(): never
        {
            throw new RuntimeException('IntegrityVerifier must not run during signing-key render');
        }
    });
    $this->app->bind(EntryVerifier::class, fn () => new class
    {
        public function verify(): never
        {
            throw new RuntimeException('EntryVerifier must not run during signing-key render');
        }
    });

    DB::enableQueryLog();

    Livewire::test(ListEntries::class)->loadTable()->assertOk();

    $checkpointQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains((string) $q['query'], 'chronicle_checkpoints'));

    // Eager-load (1) + the key-ring widget's single grouped aggregate (1).
    // Constant - never proportional to the 9 seeded entries.
    expect($checkpointQueries->count())->toBeLessThanOrEqual(2);

    DB::disableQueryLog();
});

it('hides every signing-key surface when signingKeys(false)', function () {
    ChronicleFilamentPlugin::get()->signingKeys(false);
    $this->seedRotatedLedger();

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertTableColumnHidden('signing_key')
        ->assertTableFilterHidden('signing_key');

    expect(SigningKeyRingWidget::canView())->toBeFalse();

    $retired = Entry::query()->where('sequence', 2)->firstOrFail();
    Livewire::test(ViewEntry::class, ['record' => $retired->getKey()])
        ->assertOk()
        ->assertDontSee('Key state');
});

it('keeps the read-only invariant after the rotation surfaces', function () {
    $this->seedRotatedLedger();
    $entry = Entry::query()->where('sequence', 6)->firstOrFail();

    // No mutating routes were added for the resource.
    foreach (['create', 'edit', 'delete'] as $page) {
        expect(Route::has("filament.admin.resources.chronicle-entries.$page"))
            ->toBeFalse("a mutating route '$page' was added - the read-only invariant is broken");
    }

    // The detail page still exposes only deliberate, non-entry-mutating header
    // actions: Verify-anchor and the off-by-default Erase-subject action (which
    // appends a proof via core and never updates or deletes an entry).
    $instance = Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->instance();
    $names = array_map(
        fn (object $action): string => $action->getName(),
        (new ReflectionMethod($instance, 'getHeaderActions'))->invoke($instance),
    );

    expect($names)->toBe(['verifyAnchor', 'eraseSubject'])
        ->not->toContain('edit')
        ->not->toContain('delete');
});
