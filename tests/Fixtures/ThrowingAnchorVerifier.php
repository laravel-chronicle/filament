<?php

declare(strict_types=1);

namespace Chronicle\Filament\Tests\Fixtures;

use Chronicle\Anchoring\AnchorManager;
use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Verification\AnchorVerifier;
use RuntimeException;

/**
 * A readonly AnchorVerifier subclass whose anchor check always throws, used to
 * prove the panel never runs a provider verification on a read/render path and
 * surfaces provider errors non-destructively. Named (not anonymous) because
 * anonymous `readonly` classes require PHP 8.3, while a non-readonly class cannot
 * extend the readonly AnchorVerifier - a named readonly class works on PHP 8.2+.
 */
readonly class ThrowingAnchorVerifier extends AnchorVerifier
{
    public function __construct(
        AnchorManager $manager,
        private string $message = 'AnchorVerifier must not run',
    ) {
        parent::__construct($manager);
    }

    public function checkpointHasValidAnchor(Checkpoint $checkpoint): bool
    {
        throw new RuntimeException($this->message);
    }
}
