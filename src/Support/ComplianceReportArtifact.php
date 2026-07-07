<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Carbon\CarbonImmutable;

/**
 * A signed compliance-report bundle on the exports disk: its display name,
 * disk-relative path, size in bytes, and last-modified time. Read-only metadata
 * for the download action and prior-report listing.
 */
final readonly class ComplianceReportArtifact
{
    public function __construct(
        public string $name,
        public string $path,
        public int $sizeBytes,
        public CarbonImmutable $lastModified,
    ) {
        //
    }
}
