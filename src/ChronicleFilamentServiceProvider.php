<?php

declare(strict_types=1);

namespace Chronicle\Filament;

use Chronicle\Entry\Entry;
use Chronicle\Filament\Policies\EntryPolicy;
use Chronicle\Filament\Support\SubjectErasureStore;
use Chronicle\Filament\Support\VerificationResultStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Boots the package: publishes config, views, and the verification-records
 * migration; binds the shared plugin and verification result store as
 * singletons; and registers the read-only EntryPolicy for the configured
 * entry model.
 */
final class ChronicleFilamentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('chronicle-filament')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_chronicle_filament_verification_records_table');
    }

    /**
     * Bind the plugin and verification result store as shared singletons.
     */
    public function packageRegistered(): void
    {
        // One shared plugin instance so a panel's fluent configuration is
        // visible to the resource's static methods, which Filament invokes
        // during boot/route registration when no panel is "current" yet.
        $this->app->singleton(ChronicleFilamentPlugin::class);
        $this->app->singleton(VerificationResultStore::class);
        // Shared so a render's prime() (list page / detail page) is visible to the
        // erasure column and detail closures - mirrors VerificationResultStore.
        $this->app->singleton(SubjectErasureStore::class);
    }

    /**
     * Register the read-only EntryPolicy against the configured entry model.
     */
    public function packageBooted(): void
    {
        /** @var class-string $model */
        $model = Config::string('chronicle-filament.entry_model', Entry::class);

        Gate::policy($model, EntryPolicy::class);
    }
}
