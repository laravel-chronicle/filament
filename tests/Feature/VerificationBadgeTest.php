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

    $this->mock(IntegrityVerifier::class)->shouldNotReceive('verify')->shouldNotReceive('verifyEntryRange');
    $this->mock(EntryVerifier::class)->shouldNotReceive('verify');

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
