<?php

declare(strict_types=1);

use Chronicle\Filament\Support\ComplianceReportArtifact;
use Chronicle\Filament\Support\ComplianceReportStore;
use Chronicle\Reports\ComplianceReport;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('chronicle-filament.exports.disk', 'local');
});

it('stores a report bundle whose signature re-verifies under core', function () {
    $this->seedLedger(count: 3, checkpointEvery: 2);

    $tmp = tempnam(sys_get_temp_dir(), 'chronicle-report-');
    $result = app(ComplianceReport::class)->generate($tmp);
    @unlink($tmp);

    $store = app(ComplianceReportStore::class);
    $artifact = $store->store($result);

    expect($artifact)->toBeInstanceOf(ComplianceReportArtifact::class)
        ->and($store->disk()->exists($artifact->path))->toBeTrue()
        ->and($artifact->name)->toEndWith('.zip')
        ->and($artifact->path)->toContain('chronicle-reports/');

    // Re-verify the stored signature.json under core's ComplianceReport::verify().
    $signature = json_decode(readReportSignature($store, $artifact), true);
    expect(app(ComplianceReport::class)->verify(
        $signature['reportHash'],
        $signature['signature'],
        $signature['algorithm'],
        $signature['keyId'],
    ))->toBeTrue();
});

it('lists prior reports newest first and exposes the latest', function () {
    $this->seedLedger(count: 2);

    $store = app(ComplianceReportStore::class);

    $tmp = tempnam(sys_get_temp_dir(), 'chronicle-report-');
    $first = $store->store(app(ComplianceReport::class)->generate($tmp));
    $second = $store->store(app(ComplianceReport::class)->generate($tmp));
    @unlink($tmp);

    expect($store->all())->toHaveCount(2)
        ->and($store->latest()->name)->toBe($store->all()->first()->name)
        ->and([$first->name, $second->name])->toContain($store->latest()->name);
});

/** Read report.html/signature.json out of a stored zip bundle for assertions. */
function readReportSignature(ComplianceReportStore $store, ComplianceReportArtifact $artifact): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'chronicle-report-read-');
    file_put_contents($tmp, (string) $store->disk()->get($artifact->path));

    $zip = new ZipArchive;
    $zip->open($tmp);
    $signature = (string) $zip->getFromName(ComplianceReportStore::SIGNATURE);
    $zip->close();
    @unlink($tmp);

    return $signature;
}
