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
    private array $subjects = [];

    /**
     * @var array<string, true>
     */
    private array $held = [];

    /**
     * @param  iterable<Entry>  $entries
     */
    public static function forEntries(iterable $entries): self
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

        return self::forPairs(array_values($pairs));
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
    private function primeSubjects(array $pairs): void
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
    private function primeHolds(array $pairs): void
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
            ->get(['subject_type', 'subject_id']);

        foreach ($rows as $row) {
            /** @var LegalHold $row */
            $this->held[self::key($row->subject_type, $row->subject_id)] = true;
        }
    }

    private function keyForEntry(Entry $entry): ?string
    {
        $type = $entry->subject_type;
        $id = $entry->subject_id;

        if ($type === null || $id === null) {
            return null;
        }

        return self::key($type, $id);
    }

    private static function key(string $type, string $id): string
    {
        return $type."\0".$id;
    }
}
