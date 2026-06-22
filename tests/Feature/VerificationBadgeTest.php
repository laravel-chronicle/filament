<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Verification\EntryVerifier;
use Chronicle\Verification\IntegrityVerifier;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('shows a verified badge for an entry recorded as verified', function () {
    $this->seedLedger(count: 3, checkpointEvery: 3);
    $entry = Entry::query()->where('sequence', 1)->firstOrFail();
    app(VerificationResultStore::class)->recordEntry($entry->id, app(EntryVerifier::class)->verify($entry->id));

    Livewire::test(ListEntries::class)
        ->assertSee('Verified');
});

it('does not invoke any verifier while rendering the table', function () {
    $this->seedLedger(checkpointEvery: 5);

    // IntegrityVerifier and EntryVerifier are final and cannot be Mockery-mocked,
    // so bind container spies that blow up if rendering ever resolves and calls
    // them. The table must read verification state from the store, never verify.
    $this->app->bind(IntegrityVerifier::class, fn () => new class
    {
        public function verify(): never
        {
            throw new RuntimeException('IntegrityVerifier::verify() must not run during table render');
        }
    });

    $this->app->bind(EntryVerifier::class, fn () => new class
    {
        public function verify(): never
        {
            throw new RuntimeException('EntryVerifier::verify() must not run during table render');
        }
    });

    Livewire::test(ListEntries::class)->assertOk();
});

it('renders badges for a full page without per-row store queries', function () {
    $this->seedLedger(count: 25, checkpointEvery: 25);

    DB::enableQueryLog();
    Livewire::test(ListEntries::class)->assertOk();
    $queries = DB::getQueryLog();

    // entries page + checkpoint eager-load + one store prime + one head-sequence
    // max(): a small constant, independent of row count. Guard against N+1.
    expect(count($queries))->toBeLessThan(12);
});

it('shows a failed badge for an entry recorded as failed', function () {
    $this->seedLedger(count: 4, checkpointEvery: 4);
    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    // Tamper the stored payload, then verify so the store records a real failure.
    DB::table($entry->getTable())->where('id', $entry->id)->update(['payload' => json_encode(['tampered' => true])]);
    app(VerificationResultStore::class)->recordEntry($entry->id, app(EntryVerifier::class)->verify($entry->id));

    // Render the row (not just the filter labels) so the badge state and the
    // failure tooltip are both evaluated.
    Livewire::test(ListEntries::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$entry])
        ->assertSee('Failed');
});

it('shows an unverified badge for an entry with no stored record', function () {
    $this->seedLedger(count: 3, checkpointEvery: 3);
    // No record is written: every row is unverified.

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertSee('Unverified');
});

it('shows a stale badge once newer entries are appended after verification', function () {
    $this->seedLedger(count: 3, checkpointEvery: 3);
    $entry = Entry::query()->where('sequence', 1)->firstOrFail();
    app(VerificationResultStore::class)->recordEntry($entry->id, app(EntryVerifier::class)->verify($entry->id));

    // Appending moves the chain head past verified_through -> verified becomes stale.
    $this->seedLedger(count: 2);

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertSee('Stale');
});
