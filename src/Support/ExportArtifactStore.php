<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Carbon\CarbonImmutable;
use Chronicle\Exports\ExportFormat;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Disk-facing helper for verifiable export bundles. Zips core's three export
 * files (entries.ndjson / manifest.json / signature.json) from a local export
 * directory onto the exports disk, lists prior bundles, and extracts a bundle
 * back to a local temp directory for core's ExportVerifier. Writes only artifact
 * files to the disk - never the ledger.
 */
final class ExportArtifactStore
{
    protected const array FILES = [
        ExportFormat::ENTRIES,
        ExportFormat::MANIFEST,
        ExportFormat::SIGNATURE,
    ];

    /**
     * Zip the three export files from a local export directory into a uniquely
     * named bundle under the configured path on the exports' disk.
     */
    public function store(string $sourceDir): ExportArtifact
    {
        $name = 'chronicle-export-'.CarbonImmutable::now()->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.zip';
        $relativePath = $this->prefix().$name;

        $tmpZip = tempnam(sys_get_temp_dir(), 'chronicle-zip-');

        if ($tmpZip === false) {
            // OS-level tempnam() failure; not reproducible in tests.
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Unable to allocate a temporary file for the export bundle.');
            // @codeCoverageIgnoreEnd
        }

        $zip = new ZipArchive;

        if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
            // ZipArchive::open() failure on a freshly allocated temp file; not reproducible in tests.
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Unable to open the export bundle for writing.');
            // @codeCoverageIgnoreEnd
        }

        foreach (self::FILES as $file) {
            $zip->addFile($sourceDir.'/'.$file, $file);
        }

        $zip->close();

        $this->disk()->put($relativePath, (string) file_get_contents($tmpZip));

        @unlink($tmpZip);

        return $this->artifact($relativePath);
    }

    /**
     * Every export bundle under the configured path, newest first.
     *
     * @return Collection<int, ExportArtifact>
     */
    public function all(): Collection
    {
        $prefix = rtrim($this->prefix(), '/');

        return collect($this->disk()->files($prefix))
            ->filter(fn (string $path): bool => str_ends_with($path, '.zip'))
            ->map(fn (string $path): ExportArtifact => $this->artifact($path))
            ->sortByDesc(fn (ExportArtifact $artifact): int => $artifact->lastModified->getTimestamp())
            ->values();
    }

    /**
     * The most recent export bundle, or null when none exist.
     */
    public function latest(): ?ExportArtifact
    {
        return $this->all()->first();
    }

    /**
     * Write a bundle's bytes into a fresh local temp directory and unzip it,
     * returning the directory (holding the three export files) for verification.
     */
    public function extractToLocalDir(string $zipContents): string
    {
        $dir = sys_get_temp_dir().'/chronicle-verify-'.Str::uuid();

        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            // OS-level mkdir() failure under a random temp path; not reproducible in tests.
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Unable to create a temporary directory for verification.');
            // @codeCoverageIgnoreEnd
        }

        $tmpZip = $dir.'/bundle.zip';
        file_put_contents($tmpZip, $zipContents);

        $zip = new ZipArchive;

        if ($zip->open($tmpZip) !== true) {
            throw new RuntimeException('The bundle is not a readable zip archive.');
        }

        $zip->extractTo($dir);
        $zip->close();

        @unlink($tmpZip);

        return $dir;
    }

    /**
     * Remove a local temp directory created by extractToLocalDir() (or a working
     * export dir), including its files. No-op when the directory is absent.
     */
    public function deleteLocalDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir.'/*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    /**
     * The storage disk export bundles are written to and read from.
     */
    public function disk(): Filesystem
    {
        return Storage::disk(ChronicleFilamentPlugin::get()->getExportsDisk());
    }

    /**
     * The configured directory prefix (always trailing-slashed, never leading).
     */
    private function prefix(): string
    {
        return trim(ChronicleFilamentPlugin::get()->getExportsPath(), '/').'/';
    }

    /**
     * Build an ExportArtifact from a disk-relative bundle path.
     */
    private function artifact(string $relativePath): ExportArtifact
    {
        return new ExportArtifact(
            name: basename($relativePath),
            path: $relativePath,
            sizeBytes: $this->disk()->size($relativePath),
            lastModified: CarbonImmutable::createFromTimestamp($this->disk()->lastModified($relativePath)),
        );
    }
}
