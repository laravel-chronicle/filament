<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;
use Filament\Facades\Filament;

it('exposes a stable id', function () {
    expect(ChronicleFilamentPlugin::make()->getId())->toBe('chronicle-filament');
});

it('falls back to config defaults', function () {
    $plugin = ChronicleFilamentPlugin::make();

    expect($plugin->getNavigationGroup())->toBe('Chronicle')
        ->and($plugin->getNavigationSort())->toBeNull()
        ->and($plugin->getSlug())->toBe('chronicle-entries')
        ->and($plugin->getCluster())->toBeNull()
        ->and($plugin->isVerificationEnabled())->toBeTrue()
        ->and($plugin->getLabelResolver())->toBeNull()
        ->and($plugin->isAnchoringEnabled())->toBeFalse()
        ->and($plugin->getVerifyAllQueueThreshold())->toBe(1000)
        ->and($plugin->isSigningKeysEnabled())->toBeTrue();
});

it('honors fluent overrides', function () {
    $plugin = ChronicleFilamentPlugin::make()
        ->navigationGroup('Security')
        ->navigationSort(50)
        ->slug('audit')
        ->cluster('App\\Filament\\Clusters\\Audit')
        ->verification(false)
        ->labelResolver(fn () => 'x')
        ->anchoring()
        ->signingKeys(false);

    expect($plugin->getNavigationGroup())->toBe('Security')
        ->and($plugin->getNavigationSort())->toBe(50)
        ->and($plugin->getSlug())->toBe('audit')
        ->and($plugin->getCluster())->toBe('App\\Filament\\Clusters\\Audit')
        ->and($plugin->isVerificationEnabled())->toBeFalse()
        ->and($plugin->getLabelResolver())->toBeInstanceOf(Closure::class)
        ->and($plugin->isAnchoringEnabled())->toBeTrue()
        ->and($plugin->isSigningKeysEnabled())->toBeFalse();
});

it('gates verification via the authorize closure, allowing by default', function () {
    expect(ChronicleFilamentPlugin::make()->canVerify())->toBeTrue();

    $denied = ChronicleFilamentPlugin::make()->authorize(fn () => false);
    expect($denied->canVerify())->toBeFalse();
});

it('resolves the plugin attached to the current panel', function () {
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);

    expect(ChronicleFilamentPlugin::get())->toBe($panel->getPlugin('chronicle-filament'));
});

it('boots a panel without registering anything extra', function () {
    $panel = Filament::getPanel('admin');

    // boot() is a no-op hook; assert it runs cleanly for coverage of the contract.
    ChronicleFilamentPlugin::make()->boot($panel);

    expect($panel->getPlugin('chronicle-filament'))->toBeInstanceOf(ChronicleFilamentPlugin::class);
});

it('resolves anchoring from plugin config, then core, then the fluent override', function () {
    // Default: plugin config null -> follow core (false in the test env).
    expect(ChronicleFilamentPlugin::make()->isAnchoringEnabled())->toBeFalse();

    // Core anchoring on, plugin config still null -> follows core.
    config()->set('chronicle.anchoring.enabled', true);
    expect(ChronicleFilamentPlugin::make()->isAnchoringEnabled())->toBeTrue();

    // Plugin config wins over core.
    config()->set('chronicle-filament.anchoring.enabled', false);
    expect(ChronicleFilamentPlugin::make()->isAnchoringEnabled())->toBeFalse()
        // Fluent override wins over everything.
        ->and(ChronicleFilamentPlugin::make()->anchoring()->isAnchoringEnabled())->toBeTrue();

});

it('resolves signing keys from config, then the fluent override', function () {
    // Default: config enabled -> true.
    expect(ChronicleFilamentPlugin::make()->isSigningKeysEnabled())->toBeTrue();

    // Config can disable.
    config()->set('chronicle-filament.signing_keys.enabled', false);
    expect(ChronicleFilamentPlugin::make()->isSigningKeysEnabled())->toBeFalse()
        // Fluent override wins over config.
        ->and(ChronicleFilamentPlugin::make()->signingKeys()->isSigningKeysEnabled())->toBeTrue();
});
