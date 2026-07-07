<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Jobs\ComplianceReportJob;
use Chronicle\Filament\Jobs\ExportLedgerJob;
use Chronicle\Verification\IntegrityVerifier;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Config::set('chronicle-filament.exports.disk', 'local');
});

it('appends nothing to the ledger after an export and a report, and still verifies', function () {
    $this->seedLedger(checkpointEvery: 2);

    $countBefore = Entry::query()->count();
    $headBefore = Entry::query()->orderByDesc('sequence')->value('hash');

    // Run the full-dataset export and a period-less compliance report back to back.
    (new ExportLedgerJob(null))->handle();
    (new ComplianceReportJob(null, null, null))->handle();

    expect(Entry::query()->count())->toBe($countBefore)
        ->and(Entry::query()->orderByDesc('sequence')->value('hash'))->toBe($headBefore)
        // Core chain verification still passes - the ledger is byte-for-byte unchanged.
        ->and(app(IntegrityVerifier::class)->verify()->isValid())->toBeTrue();
});

it('exposes no mutating resource routes alongside the export/report surface', function () {
    foreach (['create', 'edit', 'delete'] as $page) {
        expect(Route::has("filament.admin.resources.chronicle-entries.$page"))
            ->toBeFalse("a mutating route '$page' exists - the read-only invariant is broken");
    }
});
