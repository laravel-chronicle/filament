<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Carbon\CarbonImmutable;

/**
 * A signed export bundle on the exports disk: its display name, disk-relative
 * path, size in bytes, and last-modified time. Read-only metadata for the
 * verify-export action, the artifacts widget, and the download action.
 */
final readonly class ExportArtifact
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
