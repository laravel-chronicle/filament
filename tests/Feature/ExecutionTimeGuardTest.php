<?php

declare(strict_types=1);

use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Jobs\ExportLedgerJob;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Chronicle\Filament\Support\ComplianceReportStore;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Support\VerificationState;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

/**
 * These tests enforce the package's execution-time authorization invariant:
 *
 *   Every gated action re-checks its gate AT EXECUTION TIME, inside ->action(),
 *   and refuses when denied - not merely by hiding the button with ->visible().
 *
 * Visibility is not authorization: ->visible() decides whether a button renders,
 * but it does not stop a crafted request that invokes the action directly. So for
 * each gated action we reach the REAL ->action() closure, bypassing Filament's
 * visibility guard exactly as a crafted call would, force the gate off, invoke it,
 * and assert it denies at execution - a denial notification is sent and NO work is
 * done (nothing verified/recorded, no job dispatched, no bundle egressed, no erase).
 *
 * This is the behavioural complement to the per-action "hides the button" tests:
 * "hidden button" and "denied execution" are proven as SEPARATE assertions for
 * every gated action, so a half-applied guard cannot reappear untested. The
 * closing structural test pins the exact set of registered actions, so a newly
 * added action forces a failure here until it is given a denial test too.
 */

/**
 * Reach a resource TABLE record action's ->action() closure, bypassing the
 * table's ->visible() guard the way a crafted call would.
 */
function reachTableRecordClosure(string $name): Closure
{
    $action = Livewire::test(ListEntries::class)->instance()->getTable()->getAction($name);
    expect($action)->not->toBeNull("record action '$name' is not registered on the table");

    $closure = $action->getActionFunction();
    expect($closure)->not->toBeNull("record action '$name' has no ->action() closure");

    return $closure;
}

/**
 * Reach a resource TABLE bulk action's ->action() closure, bypassing ->visible().
 */
function reachTableBulkClosure(string $name): Closure
{
    $action = Livewire::test(ListEntries::class)->instance()->getTable()->getBulkAction($name);
    expect($action)->not->toBeNull("bulk action '$name' is not registered on the table");

    $closure = $action->getActionFunction();
    expect($closure)->not->toBeNull("bulk action '$name' has no ->action() closure");

    return $closure;
}

/**
 * Reach a ListEntries HEADER action's ->action() closure, bypassing ->visible().
 * The closure stays bound to the page instance (it captures $this for the report
 * helpers), which the returned closure keeps alive.
 */
function reachListHeaderClosure(string $name): Closure
{
    $page = Livewire::test(ListEntries::class)->instance();
    $actions = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page);

    /** @var Action|null $action */
    $action = collect($actions)->first(fn (Action $a): bool => $a->getName() === $name);
    expect($action)->not->toBeNull("list header action '$name' is not registered");

    $closure = $action->getActionFunction();
    expect($closure)->not->toBeNull("list header action '$name' has no ->action() closure");

    return $closure;
}

// --- Verify family (records to the VerificationResultStore) ---

it('denies verifyEntry at execution when the caller cannot verify', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2);
    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    (reachTableRecordClosure('verifyEntry'))($entry);

    Notification::assertNotified('Verification is not permitted');
    expect(app(VerificationResultStore::class)->entryState($entry->id))
        ->toBe(VerificationState::Unverified);
});

it('denies the verifySegment bulk action at execution when the caller cannot verify', function () {
    $this->seedLedger(count: 3, checkpointEvery: 3);
    $records = Entry::query()->orderBy('sequence')->get();

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    (reachTableBulkClosure('verifySegment'))($records);

    Notification::assertNotified('Verification is not permitted');
    expect(app(VerificationResultStore::class)->chainState('segment'))
        ->toBe(VerificationState::Unverified);
});

it('denies verifyChain at execution when the caller cannot verify', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2);

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    (reachListHeaderClosure('verifyChain'))();

    Notification::assertNotified('Verification is not permitted');
    expect(app(VerificationResultStore::class)->chainState())
        ->toBe(VerificationState::Unverified);
});

it('denies verifyAnchor at execution when the caller cannot verify', function () {
    $this->enableAnchoring();
    $this->seedLedger(count: 2, checkpointEvery: 2);
    $entry = Entry::query()->where('sequence', 2)->firstOrFail();
    $checkpointId = (string) $entry->checkpoint_id;

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    (ChronicleEntryResource::verifyAnchorAction()->getActionFunction())($entry);

    Notification::assertNotified('Anchor verification is not permitted');
    expect(app(VerificationResultStore::class)->anchorRecord($checkpointId))->toBeNull();
});

it('denies verifyAllAnchors at execution when the caller cannot verify', function () {
    $this->enableAnchoring();
    $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor((string) Checkpoint::query()->firstOrFail()->id);

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    (reachListHeaderClosure('verifyAllAnchors'))();

    Notification::assertNotified('Anchor verification is not permitted');
    Notification::assertNotNotified('All anchors verified');
});

// --- Export / report family (egresses the dataset) ---

it('denies exportLedger at execution when the caller cannot export', function () {
    $this->seedLedger(count: 1);
    Bus::fake();

    // canExport defaults to the verify gate, so denying authorization denies export.
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    (reachListHeaderClosure('exportLedger'))();

    Notification::assertNotified('Export is not permitted');
    Bus::assertNotDispatched(ExportLedgerJob::class);
});

it('denies verifyExport at execution when the caller cannot export', function () {
    $this->seedLedger(count: 1);

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    (reachListHeaderClosure('verifyExport'))([]);

    Notification::assertNotified('Export verification is not permitted');
    Notification::assertNotNotified('Export verified');
});

it('denies downloadLatestExport at execution when the caller cannot export', function () {
    $this->seedLedger(count: 1);

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    $result = (reachListHeaderClosure('downloadLatestExport'))();

    expect($result)->toBeNull();
    Notification::assertNotified('Export is not permitted');
});

it('denies complianceReport at execution when the caller cannot export', function () {
    $this->seedLedger(count: 1);

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    $result = (reachListHeaderClosure('complianceReport'))([]);

    expect($result)->toBeNull();
    Notification::assertNotified('Report generation is not permitted');
    expect(app(ComplianceReportStore::class)->latest())->toBeNull();
});

it('denies downloadLatestReport at execution when the caller cannot export', function () {
    $this->seedLedger(count: 1);

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    $result = (reachListHeaderClosure('downloadLatestReport'))();

    expect($result)->toBeNull();
    Notification::assertNotified('Report generation is not permitted');
});

// --- Erase (the panel's only write) ---

it('denies eraseSubject at execution when authorization denies it', function () {
    $this->enableEncryption();
    // Enabled but NOT authorized: visible() would hide it, so a crafted call is the
    // only way to reach the closure - which must still refuse and append no proof.
    ChronicleFilamentPlugin::get()->erasure()->eraseAuthorize(fn (): bool => false);
    $this->seedLedger(count: 1);
    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    (ChronicleEntryResource::eraseSubjectAction()->getActionFunction())($entry, [
        'confirm_subject' => $entry->subject_type.':'.$entry->subject_id,
        'reason' => 'crafted call',
    ]);

    Notification::assertNotified('Erase is not permitted');
    expect(Entry::query()->where('action', 'subject.erased')->count())->toBe(0);
});

// --- Structural tripwire: pin the registered action set ---

it('registers exactly the known gated actions, so a new action cannot skip a denial test', function () {
    $this->seedLedger(count: 1);
    $entry = Entry::query()->firstOrFail();

    $table = Livewire::test(ListEntries::class)->instance()->getTable();

    // If a new record/bulk action appears here, add it above with a denial test.
    expect(array_keys($table->getFlatActions()))
        ->toBe(['verifyEntry', 'verifyAnchor', 'eraseSubject'], 'a new table record action was registered - give it an execution-time denial test in ExecutionTimeGuardTest')
        ->and(array_keys($table->getFlatBulkActions()))
        ->toBe(['verifySegment'], 'a new table bulk action was registered - give it an execution-time denial test in ExecutionTimeGuardTest');

    $listPage = Livewire::test(ListEntries::class)->instance();
    $listHeaders = array_map(
        fn (Action $a): string => $a->getName(),
        (new ReflectionMethod($listPage, 'getHeaderActions'))->invoke($listPage),
    );

    expect($listHeaders)->toBe([
        'exportLedger',
        'verifyExport',
        'downloadLatestExport',
        'complianceReport',
        'downloadLatestReport',
        'verifyChain',
        'verifyAllAnchors',
    ], 'a new ListEntries header action was registered - give it an execution-time denial test in ExecutionTimeGuardTest');

    $viewPage = Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])->instance();
    $viewHeaders = array_map(
        fn (Action $a): string => $a->getName(),
        (new ReflectionMethod($viewPage, 'getHeaderActions'))->invoke($viewPage),
    );

    expect($viewHeaders)->toBe(['verifyAnchor', 'eraseSubject'], 'a new ViewEntry header action was registered - give it an execution-time denial test in ExecutionTimeGuardTest');
});
