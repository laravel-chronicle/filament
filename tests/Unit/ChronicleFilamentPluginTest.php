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
        ->and($plugin->getLabelResolver())->toBeNull();
});

it('honors fluent overrides', function () {
    $plugin = ChronicleFilamentPlugin::make()
        ->navigationGroup('Security')
        ->navigationSort(50)
        ->slug('audit')
        ->cluster('App\\Filament\\Clusters\\Audit')
        ->verification(false)
        ->labelResolver(fn () => 'x');

    expect($plugin->getNavigationGroup())->toBe('Security')
        ->and($plugin->getNavigationSort())->toBe(50)
        ->and($plugin->getSlug())->toBe('audit')
        ->and($plugin->getCluster())->toBe('App\\Filament\\Clusters\\Audit')
        ->and($plugin->isVerificationEnabled())->toBeFalse()
        ->and($plugin->getLabelResolver())->toBeInstanceOf(Closure::class);
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
