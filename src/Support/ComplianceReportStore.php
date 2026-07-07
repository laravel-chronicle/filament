<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Carbon\CarbonImmutable;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Reports\ComplianceReportResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use ZipArchive;

/**
 * Disk-facing helper for signed compliance-report bundles. Zips a report's HTML
 * and a machine-verifiable signature.json (reportHash / signature / algorithm /
 * keyId, so core's ComplianceReport::verify() can re-check it) onto the exports
 * disk under its own chronicle-reports/ prefix - kept separate from export
 * bundles so the export-artifacts listing never mixes the two. Writes only
 * artifact files to the disk - never the ledger.
 */
final class ComplianceReportStore
{
    public const REPORT_HTML = 'report.html';

    public const SIGNATURE = 'signature.json';

    /**
     * Top-level prefix on the exports disk for report bundles. Deliberately
     * distinct from exports.path so report zips never appear in the export
     * artifacts listing.
     */
    protected const PREFIX = 'chronicle-reports';

    /**
     * Zip a report's HTML and signature into a uniquely named bundle under the
     * reports prefix on the exports disk.
     *
     * @throws JsonException
     */
    public function store(ComplianceReportResult $result): ComplianceReportArtifact
    {
        $name = 'chronicle-report-'.CarbonImmutable::now()->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.zip';
        $relativePath = $this->prefix().$name;

        $tmpZip = tempnam(sys_get_temp_dir(), 'chronicle-report-zip-');

        if ($tmpZip === false) {
            // OS-level tempnam() failure; not reproducible in tests.
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Unable to allocate a temporary file for the report bundle.');
            // @codeCoverageIgnoreEnd
        }

        $zip = new ZipArchive;

        if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
            // ZipArchive::open() failure on a freshly allocated temp file; not reproducible in tests.
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Unable to open the report bundle for writing.');
            // @codeCoverageIgnoreEnd
        }

        $zip->addFromString(self::REPORT_HTML, $result->html);
        $zip->addFromString(self::SIGNATURE, $this->signatureJson($result));
        $zip->close();

        $this->disk()->put($relativePath, (string) file_get_contents($tmpZip));

        @unlink($tmpZip);

        return $this->artifact($relativePath);
    }

    /**
     * Every report bundle under the reports prefix, newest first.
     *
     * @return Collection<int, ComplianceReportArtifact>
     */
    public function all(): Collection
    {
        $prefix = rtrim($this->prefix(), '/');

        return collect($this->disk()->files($prefix))
            ->filter(fn (string $path): bool => str_ends_with($path, '.zip'))
            ->map(fn (string $path): ComplianceReportArtifact => $this->artifact($path))
            ->sortByDesc(fn (ComplianceReportArtifact $artifact): int => $artifact->lastModified->getTimestamp())
            ->values();
    }

    /**
     * The most recent report bundle, or null when none exist.
     */
    public function latest(): ?ComplianceReportArtifact
    {
        return $this->all()->first();
    }

    /**
     * The storage disk report bundles are written to and read from (the exports disk).
     */
    public function disk(): Filesystem
    {
        return Storage::disk(ChronicleFilamentPlugin::get()->getExportsDisk());
    }

    /**
     * The machine-verifiable signature payload for a report result.
     *
     * @throws JsonException
     */
    protected function signatureJson(ComplianceReportResult $result): string
    {
        return json_encode([
            'reportHash' => $result->reportHash,
            'signature' => $result->signature,
            'algorithm' => $result->algorithm,
            'keyId' => $result->keyId,
            'entryCount' => $result->entryCount,
            'chainHead' => $result->chainHead,
            'generatedAt' => $result->generatedAt->toIso8601String(),
            'from' => $result->from?->toIso8601String(),
            'to' => $result->to?->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The reports prefix (always trailing-slashed, never leading).
     */
    protected function prefix(): string
    {
        return self::PREFIX.'/';
    }

    /**
     * Build a ComplianceReportArtifact from a disk-relative bundle path.
     */
    protected function artifact(string $relativePath): ComplianceReportArtifact
    {
        return new ComplianceReportArtifact(
            name: basename($relativePath),
            path: $relativePath,
            sizeBytes: $this->disk()->size($relativePath),
            lastModified: CarbonImmutable::createFromTimestamp($this->disk()->lastModified($relativePath)),
        );
    }
}
