<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Jobs\ComplianceReportJob;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Support\ComplianceReportStore;
use Chronicle\Reports\ComplianceReport;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    config()->set('chronicle-filament.exports.disk', 'local');
});

it('generates and stores a signed report for a period, below the queue threshold', function () {
    $this->seedLedger(count: 3, checkpointEvery: 2);

    Livewire::test(ListEntries::class)
        ->callAction('complianceReport', data: ['from' => null, 'to' => null]);

    $store = app(ComplianceReportStore::class);
    $latest = $store->latest();

    expect($latest)->not->toBeNull();

    // The stored signature re-verifies under core.
    $tmp = tempnam(sys_get_temp_dir(), 'chronicle-report-read-');
    file_put_contents($tmp, (string) $store->disk()->get($latest->path));
    $zip = new ZipArchive;
    $zip->open($tmp);
    $signature = json_decode((string) $zip->getFromName(ComplianceReportStore::SIGNATURE), true);
    $zip->close();
    @unlink($tmp);

    expect(app(ComplianceReport::class)->verify(
        $signature['reportHash'], $signature['signature'], $signature['algorithm'], $signature['keyId'],
    ))->toBeTrue();
});

it('warns and stores nothing for an empty period', function () {
    $this->seedLedger(count: 3);

    // A future-only window covers no entries -> isEmpty().
    Livewire::test(ListEntries::class)
        ->callAction('complianceReport', data: ['from' => '2099-01-01', 'to' => '2099-12-31'])
        ->assertNotified('Report covers no entries');

    expect(app(ComplianceReportStore::class)->latest())->toBeNull();
});

it('queues the report above the queue threshold', function () {
    $this->seedLedger(count: 3, checkpointEvery: 2);
    config()->set('chronicle-filament.exports.queue_threshold', 1);

    Queue::fake();

    Livewire::test(ListEntries::class)
        ->callAction('complianceReport', data: ['from' => null, 'to' => null])
        ->assertNotified('Report queued');

    Queue::assertPushed(ComplianceReportJob::class);
});

it('hides the report action when reporting is disabled', function () {
    $this->seedLedger(count: 2);
    ChronicleFilamentPlugin::get()->reporting(false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('complianceReport');
});

it('hides the report action when the user cannot export', function () {
    $this->seedLedger(count: 2);
    // canExport() defaults to the verify gate; deny verify -> report hidden.
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('complianceReport');
});

it('downloads the latest report bundle', function () {
    $this->seedLedger(count: 3, checkpointEvery: 2);

    Livewire::test(ListEntries::class)
        ->callAction('complianceReport', data: ['from' => null, 'to' => null]);

    Livewire::test(ListEntries::class)
        ->callAction('downloadLatestReport')
        ->assertFileDownloaded(app(ComplianceReportStore::class)->latest()->name);
});

it('hides the download-latest-report action when no report exists', function () {
    $this->seedLedger(count: 2);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('downloadLatestReport');
});
