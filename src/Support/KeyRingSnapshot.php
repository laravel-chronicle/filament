<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Entry\Entry;
use Chronicle\Signing\KeyRing;

/**
 * A read-only snapshot of core's signing key ring: which keys exist (with an
 * active flag) and how to derive a checkpoint's / entry's SigningKeyState by
 * comparing its stored (algorithm, key_id) to the active key.
 *
 * Reads KeyRing::all() + active() metadata only - never sign() or verify().
 * Provider resolution is local (config-backed); reading algorithm()/keyId()
 * makes no network call. Per-key checkpoint counts come from one cheap
 * aggregate over the checkpoint table.
 */
final readonly class KeyRingSnapshot
{
    /**
     * @param  array<string, array{algorithm: string, keyId: ?string, active: bool}>  $keys
     */
    protected function __construct(
        protected array $keys,
        protected string $activeAlgorithm,
        protected ?string $activeKeyId,
    ) {}

    public static function make(): self
    {
        return self::fromRing(app(KeyRing::class));
    }

    public static function fromRing(KeyRing $ring): self
    {
        $active = $ring->active();
        $activeAlgorithm = $active->algorithm();
        $activeKeyId = $active->keyId();

        $keys = [];

        foreach ($ring->all() as $label => $provider) {
            $algorithm = $provider->algorithm();
            $keyId = $provider->keyId();

            $keys[$label] = [
                'algorithm' => $algorithm,
                'keyId' => $keyId,
                'active' => $algorithm === $activeAlgorithm && $keyId === $activeKeyId,
            ];
        }

        return new self($keys, $activeAlgorithm, $activeKeyId);
    }

    /**
     * @return array<string, array{algorithm: string, keyId: ?string, active: bool}>
     */
    public function keys(): array
    {
        return $this->keys;
    }

    public function activeLabel(): string
    {
        return $this->activeAlgorithm.':'.($this->activeKeyId ?? '');
    }

    /**
     * Derive state from a stored (algorithm, key_id). A null algorithm (no
     * checkpoint) is Unsigned; a non-active key is Retired - it still verifies
     * historical artifacts, so retired keys stay in the ring.
     */
    public function stateFor(?string $algorithm, ?string $keyId): SigningKeyState
    {
        if ($algorithm === null) {
            return SigningKeyState::Unsigned;
        }

        return $algorithm === $this->activeAlgorithm && $keyId === $this->activeKeyId
            ? SigningKeyState::Active
            : SigningKeyState::Retired;
    }

    public function forCheckpoint(Checkpoint $checkpoint): SigningKeyState
    {
        return $this->stateFor($checkpoint->algorithm, $checkpoint->key_id);
    }

    /**
     * Derive an entry's signing-key state from its checkpoint. An entry with no
     * checkpoint (`checkpoint_id` null) is Unsigned, never an error. Reads the
     * already eager-loaded `checkpoint` relation - no new per-row query.
     */
    public function forEntry(Entry $entry): SigningKeyState
    {
        if ($entry->checkpoint_id === null) {
            return SigningKeyState::Unsigned;
        }

        $checkpoint = $entry->checkpoint;

        return $checkpoint instanceof Checkpoint
            ? $this->forCheckpoint($checkpoint)
            : SigningKeyState::Unsigned;
    }

    /**
     * Per-key checkpoint counts keyed "{algorithm}:{key_id}", from one grouped
     * aggregate over the checkpoint table. Never a provider verification.
     *
     * @return array<string, int>
     */
    public function checkpointCounts(): array
    {
        $rows = Checkpoint::query()
            ->toBase()
            ->selectRaw('algorithm, key_id, count(*) as aggregate')
            ->groupBy('algorithm', 'key_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $algorithm = is_string($row->algorithm) ? $row->algorithm : 'unknown';
            $keyId = is_string($row->key_id) ? $row->key_id : '';
            $counts["$algorithm:$keyId"] = is_numeric($row->aggregate) ? (int) $row->aggregate : 0;
        }

        return $counts;
    }
}
