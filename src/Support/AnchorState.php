<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Chronicle\Anchoring\CheckpointAnchor;
use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Entry\Entry;

/**
 * The external-anchor state of a checkpoint or entry - the single source of
 * truth for anchor badge color, icon, and label across the detail view,
 * table column/filter, and coverage widget.
 *
 * State is derived from the stored CheckpointAnchor `status` only
 * (`pending|anchored|failed`); it never runs a provider verification.
 * `Invalid` is produced exclusively by the deliberate Verify-anchor action
 * (a tampered anchor), never by stored-status derivation.
 */
enum AnchorState: string
{
    case Anchored = 'anchored';
    case Pending = 'pending';
    case Failed = 'failed';
    case Unanchored = 'unanchored';
    case Invalid = 'invalid';

    public function color(): string
    {
        return match ($this) {
            self::Anchored => 'success',
            self::Pending => 'warning',
            self::Failed, self::Invalid => 'danger',
            self::Unanchored => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Anchored => 'heroicon-o-link',
            self::Pending => 'heroicon-o-clock',
            self::Failed => 'heroicon-o-x-circle',
            self::Invalid => 'heroicon-o-shield-exclamation',
            self::Unanchored => 'heroicon-o-no-symbol',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Derive anchor state from a set of stored CheckpointAnchor `status`
     * values. Reads stored status only - no provider verification.
     * Precedence: anchored > failed > pending > unanchored.
     *
     * @param  iterable<int, string>  $statuses
     */
    public static function fromStatuses(iterable $statuses): self
    {
        $seen = [];

        foreach ($statuses as $status) {
            $seen[$status] = true;
        }

        if (isset($seen['anchored'])) {
            return self::Anchored;
        }

        if (isset($seen['failed'])) {
            return self::Failed;
        }

        if (isset($seen['pending'])) {
            return self::Pending;
        }

        return self::Unanchored;
    }

    /**
     * Derive a checkpoint's anchor state from its loaded CheckpointAnchor rows.
     * Reads the `anchors` relation; callers eager-load it to stay N+1-free.
     */
    public static function forCheckpoint(Checkpoint $checkpoint): self
    {
        /** @var iterable<int, string> $statuses */
        $statuses = $checkpoint->anchors
            ->map(fn (CheckpointAnchor $anchor): string => $anchor->status)
            ->all();

        return self::fromStatuses($statuses);
    }

    /**
     * Derive an entry's anchor state from its checkpoint. An entry with no
     * checkpoint (`checkpoint_id` null) is Unanchored, never an error.
     */
    public static function forEntry(Entry $entry): self
    {
        if ($entry->checkpoint_id === null) {
            return self::Unanchored;
        }

        $checkpoint = $entry->checkpoint;

        return $checkpoint instanceof Checkpoint
            ? self::forCheckpoint($checkpoint)
            : self::Unanchored;
    }
}
