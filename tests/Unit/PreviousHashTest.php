<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Support\PreviousHash;

it('returns genesis 0 for the first entry', function () {
    $this->seedLedger(count: 3);

    $first = Entry::query()->where('sequence', 1)->firstOrFail();

    expect(PreviousHash::for($first))->toBe('0');
});

it('returns the prior entry chain hash for a later entry', function () {
    $this->seedLedger(count: 3);

    $second = Entry::query()->where('sequence', 2)->firstOrFail();
    $first = Entry::query()->where('sequence', 1)->firstOrFail();

    expect(PreviousHash::for($second))->toBe($first->chain_hash);
});
