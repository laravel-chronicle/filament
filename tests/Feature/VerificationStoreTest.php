<?php

declare(strict_types=1);

use Chronicle\Filament\Support\VerificationRecord;

it('persists a verification record row on the store table', function () {
    $record = VerificationRecord::query()->create([
        'scope' => 'chain',
        'subject_key' => 'default',
        'state' => 'verified',
        'failure_code' => null,
        'failed_entry_id' => null,
        'checked_count' => 5,
        'verified_through' => 5,
        'last_verified_at' => now(),
    ]);

    expect($record->exists)->toBeTrue()
        ->and(VerificationRecord::query()->where('scope', 'chain')->count())->toBe(1)
        ->and($record->fresh()->checked_count)->toBe(5);
});
