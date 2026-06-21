<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Support\ReferenceLabel;
use Illuminate\Support\Facades\DB;

it('uses the plugin label resolver override when set', function () {
    ChronicleFilamentPlugin::make()->labelResolver(
        fn (string $type, string $id) => "custom:$type:$id"
    );

    expect(ReferenceLabel::for('App\\Models\\User', '42'))->toBe('custom:App\\Models\\User:42');
});

it('falls back to core resolveReference and never queries', function () {
    DB::enableQueryLog();

    // Unknown FQCN -> humanised basename + id, no DB hit.
    $label = ReferenceLabel::for('App\\Models\\Invoice', '7');

    expect($label)->toBe('Invoice #7')
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('renders system actor as System', function () {
    expect(ReferenceLabel::for('system', ''))->toBe('System');
});
