<?php

declare(strict_types=1);

use Chronicle\Anchoring\AnchorManager;
use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Chronicle\Filament\Support\AnchorState;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Tests\Fixtures\ThrowingAnchorVerifier;
use Chronicle\Verification\AnchorVerifier;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('verifies a valid anchor and records it as Anchored', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('verifyAnchor')->table($entry));

    expect(app(VerificationResultStore::class)->anchorState($ledger->lastCheckpointId))->toBe(AnchorState::Anchored);
});

it('records a tampered anchor as Invalid', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId, valid: false);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('verifyAnchor')->table($entry));

    expect(app(VerificationResultStore::class)->anchorState($ledger->lastCheckpointId))->toBe(AnchorState::Invalid);
});

it('hides the verify-anchor action when the entry is unanchored', function () {
    $this->enableAnchoring();
    $this->seedLedger(count: 1); // checkpoint_id null

    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('verifyAnchor')->table($entry));
});

it('hides the verify-anchor action when anchoring is disabled', function () {
    // No enableAnchoring(): core anchoring stays off.
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('verifyAnchor')->table($entry));
});

it('hides the verify-anchor action when authorization denies it', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('verifyAnchor')->table($entry));
});

it('warns without recording when the checkpoint row is missing', function () {
    $this->enableAnchoring();
    $this->seedLedger(count: 2, checkpointEvery: 2);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    // Orphan the entry: point checkpoint_id at a non-existent checkpoint while
    // keeping it non-null, so visible() still passes but checkpoint() resolves
    // null. defer_foreign_keys defers the FK check to commit, which the
    // RefreshDatabase transaction never reaches (it rolls back).
    $bogusId = (string) Str::ulid();
    DB::statement('PRAGMA defer_foreign_keys = ON');
    DB::table('chronicle_entries')->where('id', $entry->id)->update(['checkpoint_id' => $bogusId]);
    $entry->refresh();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('verifyAnchor')->table($entry))
        ->assertNotified('Entry is not anchored');

    expect(app(VerificationResultStore::class)->anchorState($bogusId))
        ->toBe(AnchorState::Unanchored);
});

it('surfaces a provider error non-destructively without recording', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    // Swap in a verifier whose anchor check throws, as a flaky provider would.
    app()->bind(AnchorVerifier::class, fn (): AnchorVerifier => new ThrowingAnchorVerifier(
        app(AnchorManager::class),
        'TSA timeout',
    ));

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('verifyAnchor')->table($entry))
        ->assertNotified('Anchor verification could not run');

    // The outcome was not recorded: state stays Unanchored.
    expect(app(VerificationResultStore::class)->anchorState($ledger->lastCheckpointId))
        ->toBe(AnchorState::Unanchored);
});

it('exposes a verify-anchor header action on the detail view', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertActionVisible('verifyAnchor');
});
