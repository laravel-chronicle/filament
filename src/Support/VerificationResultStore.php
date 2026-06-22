<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Carbon\CarbonImmutable;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Verification\EntryVerificationResult;
use Chronicle\Verification\VerificationResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Records and reads back verification outcomes for chains, segments, and entries,
 * deriving the effective state (verified / failed / unverified / stale) so badge
 * state never depends on core's resume-only run log. Batch-primes a page of
 * entries in one query to keep badge rendering N+1-free.
 */
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

    /**
     * Record the outcome of a chain or segment verification under the given key.
     */
    public function recordChain(VerificationResult $result, string $chainKey = 'default'): VerificationRecord
    {
        return $this->upsert('chain', $chainKey, $result->isValid(), $result->failureType(), $result->entryId(), $result->checked());
    }

    /**
     * Record the outcome of a single-entry verification.
     */
    public function recordEntry(string $entryId, EntryVerificationResult $result): VerificationRecord
    {
        return $this->upsert('entry', $entryId, $result->isValid(), $result->failureCode(), $result->isValid() ? null : $entryId, 1);
    }

    /**
     * The effective verification state for a chain or segment key.
     */
    public function chainState(string $chainKey = 'default'): VerificationState
    {
        return $this->effectiveState($this->chainRecord($chainKey));
    }

    /**
     * The effective verification state for an entry, served from the primed memo
     * when a render has primed it, otherwise read fresh from the store.
     */
    public function entryState(string $entryId): VerificationState
    {
        if ($this->primedEntries !== null && array_key_exists($entryId, $this->primedEntries)) {
            return $this->effectiveState($this->primedEntries[$entryId]);
        }

        return $this->effectiveState($this->entryRecord($entryId));
    }

    /**
     * The raw stored record for a chain or segment key, or null if never verified.
     */
    public function chainRecord(string $chainKey = 'default'): ?VerificationRecord
    {
        return VerificationRecord::query()->where('scope', 'chain')->where('subject_key', $chainKey)->first();
    }

    /**
     * The raw stored record for an entry, or null if never verified.
     */
    public function entryRecord(string $entryId): ?VerificationRecord
    {
        return VerificationRecord::query()->where('scope', 'entry')->where('subject_key', $entryId)->first();
    }

    /**
     * Load the stored records and chain head for a page of entries in one query,
     * memorizing them so subsequent entryState() calls issue no per-row queries.
     *
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
     * The entry ids currently in the given effective state, for the table filter.
     * Verified/stale are split by whether the record covers the current head;
     * unverified (absence of a record) is handled by the filter via whereNotIn.
     *
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
     * Pluck the subject keys (entry ids) from a record query.
     *
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

    /**
     * The highest entry sequence in the ledger - the chain head against which a
     * stored record is judged current or stale.
     */
    public function currentHeadSequence(): int
    {
        // Use the primed memo only when a render has primed it; otherwise query
        // fresh every call so freshly-appended entries flip verified -> stale.
        return $this->headSequence ?? $this->queryHeadSequence();
    }

    /**
     * Query the chain head sequence fresh from the configured entry model.
     */
    protected function queryHeadSequence(): int
    {
        /** @var class-string<Model> $model */
        $model = ChronicleEntryResource::getModel();
        $max = $model::query()->max('sequence');

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * Derive the effective state from a stored record: unverified when absent,
     * failed when the record failed, stale when verified before later entries
     * were appended, otherwise verified.
     */
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

    /**
     * Insert or update the record for a scope/key, stamping the verified-through
     * head and timestamp, and invalidate the per-render memos after the write.
     */
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
