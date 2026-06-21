<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Carbon\CarbonImmutable;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Verification\EntryVerificationResult;
use Chronicle\Verification\VerificationResult;
use Illuminate\Database\Eloquent\Builder;

class VerificationResultStore
{
    /**
     * Per-request memo of entry records keyed by entry id, populated by
     * primeEntries() so badge rendering stays query-free (no N+1).
     *
     * @var array<string, VerificationRecord|null>|null
     */
    protected ?array $primedEntries = null;

    protected ?int $headSequence = null;

    public function recordChain(VerificationResult $result, string $chainKey = 'default'): VerificationRecord
    {
        return $this->upsert('chain', $chainKey, $result->isValid(), $result->failureType(), $result->entryId(), $result->checked());
    }

    public function recordEntry(string $entryId, EntryVerificationResult $result): VerificationRecord
    {
        return $this->upsert('entry', $entryId, $result->isValid(), $result->failureCode(), $result->isValid() ? null : $entryId, 1);
    }

    public function chainState(string $chainKey = 'default'): VerificationState
    {
        return $this->effectiveState($this->chainRecord($chainKey));
    }

    public function entryState(string $entryId): VerificationState
    {
        if ($this->primedEntries !== null && array_key_exists($entryId, $this->primedEntries)) {
            return $this->effectiveState($this->primedEntries[$entryId]);
        }

        return $this->effectiveState($this->entryRecord($entryId));
    }

    public function chainRecord(string $chainKey = 'default'): ?VerificationRecord
    {
        return VerificationRecord::query()->where('scope', 'chain')->where('subject_key', $chainKey)->first();
    }

    public function entryRecord(string $entryId): ?VerificationRecord
    {
        return VerificationRecord::query()->where('scope', 'entry')->where('subject_key', $entryId)->first();
    }

    /**
     * @param  iterable<int, string>  $entryIds
     */
    public function primeEntries(iterable $entryIds): void
    {
        $ids = array_values(array_map('strval', is_array($entryIds) ? $entryIds : iterator_to_array($entryIds)));

        // Cache the head once for this primed render so per-row badges stay
        // query-free; only primeEntries() may hold this memo (see currentHeadSequence).
        $this->headSequence = $this->queryHeadSequence();

        $records = VerificationRecord::query()
            ->where('scope', 'entry')
            ->whereIn('subject_key', $ids)
            ->get()
            ->keyBy('subject_key');

        $this->primedEntries = [];
        foreach ($ids as $id) {
            $this->primedEntries[$id] = $records->get($id);
        }
    }

    /**
     * @return array<int, string>
     */
    public function entryIdsWithState(VerificationState $state): array
    {
        $head = $this->currentHeadSequence();

        $query = VerificationRecord::query()->where('scope', 'entry');

        return match ($state) {
            VerificationState::Failed => $this->subjectKeys($query->where('state', 'failed')),
            VerificationState::Verified => $this->subjectKeys($query->where('state', 'verified')->where('verified_through', '>=', $head)),
            VerificationState::Stale => $this->subjectKeys($query->where('state', 'verified')->where('verified_through', '<', $head)),
            VerificationState::Unverified => [], // absence of a record - handled by the filter via whereNotIn
        };
    }

    /**
     * @param  Builder<VerificationRecord>  $query
     * @return array<int, string>
     */
    protected function subjectKeys(Builder $query): array
    {
        return $query->get()
            ->map(fn (VerificationRecord $record): string => $record->subject_key)
            ->values()
            ->all();
    }

    public function currentHeadSequence(): int
    {
        // Use the primed memo only when a render has primed it; otherwise query
        // fresh every call so freshly-appended entries flip verified -> stale.
        return $this->headSequence ?? $this->queryHeadSequence();
    }

    protected function queryHeadSequence(): int
    {
        $model = ChronicleEntryResource::getModel();
        $max = $model::query()->max('sequence');

        return is_numeric($max) ? (int) $max : 0;
    }

    protected function effectiveState(?VerificationRecord $record): VerificationState
    {
        if ($record === null) {
            return VerificationState::Unverified;
        }

        if ($record->state === 'failed') {
            return VerificationState::Failed;
        }

        if ($record->verified_through < $this->currentHeadSequence()) {
            return VerificationState::Stale;
        }

        return VerificationState::Verified;
    }

    protected function upsert(string $scope, string $key, bool $valid, ?string $failureCode, ?string $failedEntryId, int $checked): VerificationRecord
    {
        $this->primedEntries = null; // invalidate the badge memo after a write
        $this->headSequence = null;

        $record = VerificationRecord::query()->firstOrNew(['scope' => $scope, 'subject_key' => $key]);
        $record->state = $valid ? 'verified' : 'failed';
        $record->failure_code = $valid ? null : $failureCode;
        $record->failed_entry_id = $failedEntryId;
        $record->checked_count = $checked;
        $record->verified_through = $this->currentHeadSequence();
        $record->last_verified_at = CarbonImmutable::now();
        $record->save();

        return $record;
    }
}
