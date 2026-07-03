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

it('defaults canExport to the verify gate and never wider', function () {
    // No exportAuthorize closure -> follows canVerify (allowed by default).
    expect(ChronicleFilamentPlugin::make()->canExport())->toBeTrue();

    // A non-verifier (verify denied) is therefore also denied export by default.
    app()->forgetInstance(ChronicleFilamentPlugin::class);
    $nonVerifier = ChronicleFilamentPlugin::make()->authorize(fn () => false);
    expect($nonVerifier->canVerify())->toBeFalse()
        ->and($nonVerifier->canExport())->toBeFalse();
});

it('lets exportAuthorize tighten export below the verify gate', function () {
    app()->forgetInstance(ChronicleFilamentPlugin::class);

    // Verify is allowed, but exportAuthorize denies -> export denied (tightened).
    $plugin = ChronicleFilamentPlugin::make()
        ->authorize(fn () => true)
        ->exportAuthorize(fn () => false);

    expect($plugin->canVerify())->toBeTrue()
        ->and($plugin->canExport())->toBeFalse();

    // And it can grant when verify would too.
    app()->forgetInstance(ChronicleFilamentPlugin::class);
    expect(ChronicleFilamentPlugin::make()->exportAuthorize(fn () => true)->canExport())->toBeTrue();
});
