<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;

it('enables exports by default, overridable by config then fluent', function () {
    // Default: config enabled -> true.
    expect(ChronicleFilamentPlugin::make()->isExportsEnabled())->toBeTrue();

    // Config can disable.
    config()->set('chronicle-filament.exports.enabled', false);
    expect(ChronicleFilamentPlugin::make()->isExportsEnabled())->toBeFalse()
        // Fluent override wins over config.
        ->and(ChronicleFilamentPlugin::make()->exports()->isExportsEnabled())->toBeTrue();
});

it('enables reporting by default, overridable by config then fluent', function () {
    expect(ChronicleFilamentPlugin::make()->isReportingEnabled())->toBeTrue();

    config()->set('chronicle-filament.reporting.enabled', false);
    expect(ChronicleFilamentPlugin::make()->isReportingEnabled())->toBeFalse()
        ->and(ChronicleFilamentPlugin::make()->reporting()->isReportingEnabled())->toBeTrue();
});
