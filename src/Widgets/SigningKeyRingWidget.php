<?php

declare(strict_types=1);

namespace Chronicle\Filament\Widgets;

use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Support\KeyRingSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Stats widget summarising core's signing key ring: the active key, how many
 * keys are in the ring, how many are retired, and the active key's checkpoint
 * coverage (checkpoints signed by the active key vs total). Built from
 * KeyRingSnapshot - provider metadata plus one grouped checkpoint aggregate -
 * and never runs a provider sign()/verify() on load. Hidden when the
 * signing-key surfaces are disabled.
 */
class SigningKeyRingWidget extends StatsOverviewWidget
{
    /**
     * Mount only when the signing-key surfaces are enabled.
     */
    public static function canView(): bool
    {
        return ChronicleFilamentPlugin::get()->isSigningKeysEnabled();
    }

    /**
     * Build the ring summary from KeyRingSnapshot. keys()/activeLabel() are
     * config-backed metadata (no DB); checkpointCounts() is one grouped
     * aggregate over the checkpoint table - never a provider verification.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $snapshot = KeyRingSnapshot::make();

        $keys = $snapshot->keys();
        $totalKeys = count($keys);
        $retiredKeys = count(array_filter($keys, static fn (array $key): bool => $key['active'] === false));

        $counts = $snapshot->checkpointCounts();
        $activeCheckpoints = $counts[$snapshot->activeLabel()] ?? 0;
        $totalCheckpoints = array_sum($counts);

        return [
            Stat::make('Active signing key', $snapshot->activeLabel())
                ->description($totalKeys === 1 ? '1 key in the ring' : "$totalKeys keys in the ring")
                ->color('success')
                ->icon('heroicon-o-key'),
            Stat::make('Retired keys', (string) $retiredKeys)
                ->description('Still verify historical entries')
                ->color($retiredKeys > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-archive-box'),
            Stat::make('Active key coverage', "$activeCheckpoints / $totalCheckpoints")
                ->description('Checkpoints signed by the active key')
                ->color($totalCheckpoints > 0 && $activeCheckpoints === $totalCheckpoints ? 'success' : 'warning')
                ->icon('heroicon-o-shield-check'),
        ];
    }
}
