<?php

declare(strict_types=1);

namespace Chronicle\Filament\Widgets;

use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Support\VerificationState;
use Chronicle\Verification\CheckpointChainVerifier;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use JsonException;

/**
 * Stats widget on the list page surfacing the chain's stored verification status
 * and last-verified time alongside a cheap checkpoint-spine check (O(#checkpoints),
 * no full per-entry re-hash on load), reporting the first detected gap.
 */
class VerificationHealthWidget extends StatsOverviewWidget
{
    /**
     * Build the two stats: the chain's stored status with last-verified time, and
     * a cheap checkpoint-spine check reporting the first detected gap.
     *
     * @return array<int, Stat>
     *
     * @throws JsonException
     */
    protected function getStats(): array
    {
        $store = app(VerificationResultStore::class);
        $record = $store->chainRecord();
        $state = $store->chainState();

        // O(#checkpoints) spine attestation - never a full per-entry re-hash.
        $spine = app(CheckpointChainVerifier::class)->verify();
        $spineState = $spine->isValid() ? VerificationState::Verified : VerificationState::Failed;

        return [
            Stat::make('Chain status', $state->label())
                ->description($record?->last_verified_at !== null
                    ? 'Last verified '.$record->last_verified_at->diffForHumans()
                    : 'Never verified')
                ->color($state->color())
                ->icon($state->icon()),
            Stat::make('Spine check', $spineState->label())
                ->description($spine->isValid()
                    ? 'Checkpoint chain intact'
                    : 'First gap at '.((string) $spine->entryId()))
                ->color($spineState->color())
                ->icon($spineState->icon()),
        ];
    }
}
