<?php

declare(strict_types=1);

namespace Chronicle\Filament\Widgets;

use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Support\ExportArtifactStore;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Stats widget summarising the export bundles on the exports disk: how many exist
 * and the latest bundle's name, size, and generated-at. Reads disk metadata only -
 * never opens a bundle or touches the ledger. Hidden when exports are disabled.
 */
class ExportArtifactsWidget extends StatsOverviewWidget
{
    /**
     * Mount only when the export surfaces are enabled.
     */
    public static function canView(): bool
    {
        return ChronicleFilamentPlugin::get()->isExportsEnabled();
    }

    /**
     * Build the last-export stats from cheap disk metadata (file list, size,
     * mtime) - never a bundle read or a verification.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $store = app(ExportArtifactStore::class);
        $all = $store->all();
        $latest = $all->first();

        return [
            Stat::make('Export bundles', (string) $all->count())
                ->description($latest !== null
                    ? 'Latest '.$latest->lastModified->diffForHumans()
                    : 'No exports yet')
                ->color($latest !== null ? 'success' : 'gray')
                ->icon('heroicon-o-archive-box'),
            Stat::make('Latest bundle', $latest->name ?? '-')
                ->description($latest !== null
                    ? number_format($latest->sizeBytes / 1024, 1).' KB'
                    : 'Run an export to create one')
                ->icon('heroicon-o-document-arrow-down'),
        ];
    }
}
