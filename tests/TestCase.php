<?php

declare(strict_types=1);

namespace Chronicle\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Chronicle\ChronicleServiceProvider;
use Chronicle\Filament\ChronicleFilamentServiceProvider;
use Chronicle\Filament\Tests\Fixtures\TestPanelProvider;
use Chronicle\Testing\LedgerSeeder;
use Chronicle\Testing\SeededLedger;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use Throwable;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            ChronicleServiceProvider::class,
            ChronicleFilamentServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        Config::set('database.default', 'testing');
        Config::set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Core's published dev keypair, so core's signing provider boots clearly.
        Config::set('chronicle.signing.keys.chronicle-dev-key.private_key', 'RcSfC2MuYTPnkrL/MIA4/l/sAjirGXXIFXZEPokdwh1Lcz+SvNE7bjvgCsDotjnlHfJyZ4XW/kUXemtoyaa92Q==');
        Config::set('chronicle.signing.keys.chronicle-dev-key.public_key', 'S3M/krzRO2474ArA6LY55R3ycmeF1v5FF3praMmmvdk=');
    }

    protected function defineDatabaseMigrations(): void
    {
        $coreDir = dirname((string) (new ReflectionClass(ChronicleServiceProvider::class))->getFileName());

        $this->loadMigrationsFrom($coreDir.'/../database/migrations');
    }

    /**
     * @throws Throwable
     */
    protected function seedLedger(int $count = 5, int $checkpointEvery = 0): SeededLedger
    {
        return LedgerSeeder::make()
            ->count($count)
            ->checkpointEvery($checkpointEvery)
            ->seed();
    }
}
