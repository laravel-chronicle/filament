<?php

declare(strict_types=1);

namespace Chronicle\Filament;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ChronicleFilamentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('chronicle-filament')
            ->hasConfigFile()
            ->hasViews();
    }

    public function packageRegistered(): void
    {
        // One shared plugin instance so a panel's fluent configuration is
        // visible to the resource's static methods, which Filament invokes
        // during boot/route registration when no panel is "current" yet.
        $this->app->singleton(ChronicleFilamentPlugin::class);
    }
}
