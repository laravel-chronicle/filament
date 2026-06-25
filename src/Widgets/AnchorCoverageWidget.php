<?php

declare(strict_types=1);

namespace Chronicle\Filament\Widgets;

use Carbon\CarbonImmutable;
use Chronicle\Anchoring\CheckpointAnchor;
use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Filament\ChronicleFilamentPlugin;
use DateTimeInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stats widget summarising external-anchor coverage from cheap checkpoint table
 * aggregates (checkpoints with an `anchored` anchor vs total, plus pending and
 * failed counts and the latest anchored_at). It reads stored anchor `status`
 * only and never runs a provider verification on load. Hidden when anchoring is
 * disabled.
 */
class AnchorCoverageWidget extends StatsOverviewWidget
{
    /**
     * Mount only when the anchor surfaces are enabled; everything stays hidden
     * when core anchoring is off.
     */
    public static function canView(): bool
    {
        return ChronicleFilamentPlugin::get()->isAnchoringEnabled();
    }

    /**
     * Build the coverage stats from cheap aggregates over the checkpoint/anchor
     * tables - never a provider verification. Pending/failed are checkpoint-level
     * and respect the anchored > failed > pending precedence used by the column
     * filter.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $total = Checkpoint::query()->count();

        $anchored = Checkpoint::query()
            ->whereHas('anchors', fn (Builder $q): Builder => $q->where('status', 'anchored'))
            ->count();

        $failed = Checkpoint::query()
            ->whereHas('anchors', fn (Builder $q): Builder => $q->where('status', 'failed'))
            ->whereDoesntHave('anchors', fn (Builder $q): Builder => $q->where('status', 'anchored'))
            ->count();

        $pending = Checkpoint::query()
            ->whereHas('anchors', fn (Builder $q): Builder => $q->where('status', 'pending'))
            ->whereDoesntHave('anchors', fn (Builder $q): Builder => $q->whereIn('status', ['anchored', 'failed']))
            ->count();

        $latestRaw = CheckpointAnchor::query()->where('status', 'anchored')->max('anchored_at');
        $latest = match (true) {
            $latestRaw instanceof DateTimeInterface => CarbonImmutable::instance($latestRaw),
            is_string($latestRaw) && $latestRaw !== '' => CarbonImmutable::parse($latestRaw),
            default => null,
        };

        return [
            Stat::make('Anchor coverage', "$anchored / $total")
                ->description($latest !== null
                    ? 'Last anchored '.$latest->diffForHumans()
                    : 'No anchored checkpoints')
                ->color($total > 0 && $anchored === $total ? 'success' : 'warning')
                ->icon('heroicon-o-link'),
            Stat::make('Pending', (string) $pending)
                ->color($pending > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),
            Stat::make('Failed', (string) $failed)
                ->color($failed > 0 ? 'danger' : 'gray')
                ->icon('heroicon-o-x-circle'),
        ];
    }
}
