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
}
