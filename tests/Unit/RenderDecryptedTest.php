<?php

declare(strict_types=1);

use Chronicle\Filament\Resources\ChronicleEntryResource;

/**
 * renderDecrypted() pretty-prints a decrypted accessor value for the infolist.
 * Accessors may return null, a scalar (never-encrypted), or an array (plaintext);
 * exercise each branch directly since real seeded data only yields arrays.
 */
function renderDecrypted(mixed $value): string
{
    $method = new ReflectionMethod(ChronicleEntryResource::class, 'renderDecrypted');

    return (string) $method->invoke(null, $value);
}

it('renders a placeholder for a null value', function () {
    expect(renderDecrypted(null))->toBe('-');
});

it('renders a scalar value as a string', function () {
    expect(renderDecrypted('redacted'))->toBe('redacted')
        ->and(renderDecrypted(42))->toBe('42');
});

it('pretty-prints an array value as JSON', function () {
    expect(renderDecrypted(['key' => 'value']))
        ->toContain('"key"')
        ->toContain('"value"');
});
