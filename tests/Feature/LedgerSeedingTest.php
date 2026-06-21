<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Verification\IntegrityVerifier;

it('seeds a verifiable ledger on the eloquent driver', function () {
    $result = $this->seedLedger(count: 6, checkpointEvery: 3);

    expect($result->entries)->toBe(6)
        ->and(Entry::query()->count())->toBe(6)
        ->and(app(IntegrityVerifier::class)->verify()->isValid())->toBeTrue();
});
