<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;

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
