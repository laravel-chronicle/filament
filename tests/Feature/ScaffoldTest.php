<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentServiceProvider;

it('auto-discovers the service provider', function () {
    expect(app()->getLoadedProviders())
        ->toHaveKey(ChronicleFilamentServiceProvider::class);
});

it('merges the plugin config with the documented defaults', function () {
    expect(config('chronicle-filament.entry_model'))->toBe(Entry::class)
        ->and(config('chronicle-filament.navigation.group'))->toBe('Chronicle')
        ->and(config('chronicle-filament.navigation.sort'))->toBeNull()
        ->and(config('chronicle-filament.slug'))->toBe('chronicle-entries')
        ->and(config('chronicle-filament.verification.enabled'))->toBeTrue()
        ->and(config('chronicle-filament.verification.queue_threshold'))->toBe(1000)
        ->and(config('chronicle-filament.verification.store.connection'))->toBeNull()
        ->and(config('chronicle-filament.anchoring.enabled'))->toBeNull()
        ->and(config('chronicle-filament.anchoring.verify_all_queue_threshold'))->toBe(1000);
});

it('publishes the config file under the chronicle-filament-config tag', function () {
    $target = config_path('chronicle-filament.php');

    if (file_exists($target)) {
        unlink($target);
    }

    $this->artisan('vendor:publish', [
        '--tag' => 'chronicle-filament-config',
    ])->assertExitCode(0);

    expect(file_exists($target))->toBeTrue();

    unlink($target);
});
