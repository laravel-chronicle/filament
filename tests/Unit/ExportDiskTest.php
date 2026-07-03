<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;

it('resolves the exports disk from config, falling back to the app default', function () {
    // Null config disk -> follows the application's default filesystem disk.
    config()->set('chronicle-filament.exports.disk', null);
    config()->set('filesystems.default', 'local');
    expect(ChronicleFilamentPlugin::make()->getExportsDisk())->toBe('local');

    // A different app default is followed when the plugin disk is still null.
    config()->set('filesystems.default', 'public');
    expect(ChronicleFilamentPlugin::make()->getExportsDisk())->toBe('public');

    // Explicit plugin config disk wins over the app default.
    config()->set('chronicle-filament.exports.disk', 's3');
    expect(ChronicleFilamentPlugin::make()->getExportsDisk())->toBe('s3');
});

it('resolves the exports path and report queue threshold from config', function () {
    // Defaults.
    expect(ChronicleFilamentPlugin::make()->getExportsPath())->toBe('chronicle-exports')
        ->and(ChronicleFilamentPlugin::make()->getExportsQueueThreshold())->toBe(1000);

    // Config overrides.
    config()->set('chronicle-filament.exports.path', 'audit/exports');
    config()->set('chronicle-filament.exports.queue_threshold', 250);

    expect(ChronicleFilamentPlugin::make()->getExportsPath())->toBe('audit/exports')
        ->and(ChronicleFilamentPlugin::make()->getExportsQueueThreshold())->toBe(250);
});
