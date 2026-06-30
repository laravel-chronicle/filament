<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Carbon\CarbonInterface;
use Chronicle\Encryption\SubjectKey;
use Chronicle\Entry\Entry;
use Chronicle\Lifecycle\LegalHold;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-page priming store for crypto-shredding state. Given the distinct subjects
 * on a page, it batch-reads core's SubjectKey (status/erased_at/kek_id) and active
 * LegalHold rows in TWO queries total, memoised; per-entry stateFor()/isHeld()/
 * erasedAtFor()/kekIdFor() are then query-free.
 *
 * Reads status only - never unwraps a DEK, decrypts, or erases. When encryption
 * is disabled there are no SubjectKey rows, so every subject reads NotEncrypted
 * and unheld (the degradation path).
 */
final class SubjectErasureStore
{
    /**
     * @var array<string, array{state: ErasureState, erasedAt: ?CarbonInterface, kekId: ?string}>
     */
    protected array $subjects = [];

    /**
     * @var array<string, array{placedAt: ?CarbonInterface, reason: ?string}>
     */
    protected array $held = [];

    /**
     * @param  iterable<Entry>  $entries
     */
    public static function forEntries(iterable $entries): self
    {
        return self::forPairs(self::pairsFromEntries($entries));
    }

    /**
     * Prime this instance for a page of entries: reset, then batch-read SubjectKey
     * + active LegalHold for the distinct subjects in two queries (zero for an
     * empty page). Lets the store work as a request-scoped singleton the list and
     * detail pages prime once per render, so column closures read query-free.
     *
     * @param  iterable<Entry>  $entries
     */
    public function prime(iterable $entries): void
    {
        $this->subjects = [];
        $this->held = [];

        $pairs = self::pairsFromEntries($entries);

        if ($pairs === []) {
            return;
        }

        $this->primeSubjects($pairs);
        $this->primeHolds($pairs);
    }

    /**
     * Distinct (subject_type, subject_id) pairs for a set of entries, skipping any
     * entry with a null subject.
     *
     * @param  iterable<Entry>  $entries
     * @return list<array{0: string, 1: string}>
     */
    protected static function pairsFromEntries(iterable $entries): array
    {
        $pairs = [];

        foreach ($entries as $entry) {
            $type = $entry->subject_type;
            $id = $entry->subject_id;

            if ($type === null || $id === null) {
                continue;
            }

            $pairs[self::key($type, $id)] = [$type, $id];
        }

        return array_values($pairs);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public static function forPairs(array $pairs): self
    {
        $store = new self;

        $unique = [];
        foreach ($pairs as [$type, $id]) {
            $unique[self::key($type, $id)] = [$type, $id];
        }
        $unique = array_values($unique);

        if ($unique === []) {
            return $store;
        }

        $store->primeSubjects($unique);
        $store->primeHolds($unique);

        return $store;
    }

    public function stateFor(Entry $entry): ErasureState
    {
        $key = $this->keyForEntry($entry);

        if ($key === null) {
            return ErasureState::NotEncrypted;
        }

        return $this->subjects[$key]['state'] ?? ErasureState::NotEncrypted;
    }

    public function isHeld(Entry $entry): bool
    {
        $key = $this->keyForEntry($entry);

        return $key !== null && isset($this->held[$key]);
    }

    public function heldReasonFor(Entry $entry): ?string
    {
        $key = $this->keyForEntry($entry);

        return $key === null ? null : ($this->held[$key]['reason'] ?? null);
    }

    public function heldPlacedAtFor(Entry $entry): ?CarbonInterface
    {
        $key = $this->keyForEntry($entry);

        return $key === null ? null : ($this->held[$key]['placedAt'] ?? null);
    }

    public function erasedAtFor(Entry $entry): ?CarbonInterface
    {
        $key = $this->keyForEntry($entry);

        return $key === null ? null : ($this->subjects[$key]['erasedAt'] ?? null);
    }

    public function kekIdFor(Entry $entry): ?string
    {
        $key = $this->keyForEntry($entry);

        return $key === null ? null : ($this->subjects[$key]['kekId'] ?? null);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    protected function primeSubjects(array $pairs): void
    {
        $rows = SubjectKey::query()
            ->where(function (Builder $query) use ($pairs): void {
                foreach ($pairs as [$type, $id]) {
                    $query->orWhere(function (Builder $inner) use ($type, $id): void {
                        $inner->where('subject_type', $type)->where('subject_id', $id);
                    });
                }
            })
            ->get();

        foreach ($rows as $row) {
            /** @var SubjectKey $row */
            $this->subjects[self::key($row->subject_type, $row->subject_id)] = [
                'state' => $row->isErased() ? ErasureState::Erased : ErasureState::Encrypted,
                'erasedAt' => $row->erased_at,
                'kekId' => $row->kek_id,
            ];
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    protected function primeHolds(array $pairs): void
    {
        $rows = LegalHold::query()
            ->whereNull('released_at')
            ->where(function (Builder $query) use ($pairs): void {
                foreach ($pairs as [$type, $id]) {
                    $query->orWhere(function (Builder $inner) use ($type, $id): void {
                        $inner->where('subject_type', $type)->where('subject_id', $id);
                    });
                }
            })
            ->get(['subject_type', 'subject_id', 'reason', 'placed_at']);

        foreach ($rows as $row) {
            /** @var LegalHold $row */
            $this->held[self::key($row->subject_type, $row->subject_id)] = [
                'placedAt' => $row->placed_at,
                'reason' => $row->reason,
            ];
        }
    }

    protected function keyForEntry(Entry $entry): ?string
    {
        $type = $entry->subject_type;
        $id = $entry->subject_id;

        if ($type === null || $id === null) {
            return null;
        }

        return self::key($type, $id);
    }

    protected static function key(string $type, string $id): string
    {
        return $type."\0".$id;
    }
}
