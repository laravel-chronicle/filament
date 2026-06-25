<?php

declare(strict_types=1);

namespace Chronicle\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Chronicle\Anchoring\CheckpointAnchor;
use Chronicle\Anchoring\CheckpointDigest;
use Chronicle\Anchoring\NullAnchor;
use Chronicle\Checkpoints\Checkpoint;
use Chronicle\ChronicleServiceProvider;
use Chronicle\Filament\ChronicleFilamentServiceProvider;
use Chronicle\Filament\Tests\Fixtures\TestPanelProvider;
use Chronicle\Signing\Ed25519SigningProvider;
use Chronicle\Signing\KeyRing;
use Chronicle\Testing\LedgerSeeder;
use Chronicle\Testing\SeededLedger;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\Livewire\Partials\DataStoreOverride;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\DataStore;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use Throwable;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Filament's SupportServiceProvider re-binds Livewire's DataStore to its
        // own override with a non-shared bind(), which (registered after
        // LivewireServiceProvider) drops Livewire's shared instance. Livewire's
        // store() then resolves a fresh DataStore - and a fresh component-state
        // WeakMap - on every call, so per-component state (e.g. the validation
        // error bag) never persists and rendering throws. Re-bind the override
        // as a singleton so component state is shared, as it is at runtime.
        $this->app->singleton(DataStore::class, DataStoreOverride::class);
    }

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
        // Rendering the Livewire/Filament surface boots the encrypter (cookies,
        // sessions), which requires an application key.
        Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));

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

        $migration = require __DIR__.'/../database/migrations/create_chronicle_filament_verification_records_table.php.stub';
        $migration->up();
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

    /**
     * Turn core anchoring on with the in-DB NullAnchor provider registered, so
     * the plugin's isAnchoringEnabled() follows core and seeded anchors verify.
     */
    protected function enableAnchoring(): void
    {
        Config::set('chronicle.anchoring.enabled', true);
        Config::set('chronicle.anchoring.providers.null.provider', NullAnchor::class);
    }

    /**
     * Attach a CheckpointAnchor row to a seeded checkpoint. A valid anchor stores
     * the checkpoint digest as its proof (NullAnchor::verify passes); a tampered
     * one stores a bogus proof (verify fails).
     */
    protected function seedAnchor(
        string $checkpointId,
        string $status = 'anchored',
        bool $valid = true,
        string $provider = 'null',
    ): CheckpointAnchor {
        $checkpoint = Checkpoint::query()->findOrFail($checkpointId);

        $anchor = new CheckpointAnchor([
            'checkpoint_id' => $checkpointId,
            'provider' => $provider,
            'reference' => 'ref-'.$status,
            'proof' => $valid ? CheckpointDigest::for($checkpoint) : 'tampered-proof',
            'status' => $status,
            'anchored_at' => $status === 'anchored' ? now()->toImmutable() : null,
            'created_at' => now()->toImmutable(),
        ]);
        $anchor->save();

        return $anchor;
    }

    /**
     * Relabel a seeded checkpoint's stored signing key id, so it derives a
     * non-active (Retired) SigningKeyState. The seeder signs with the active
     * dev key; this is the display-only way to simulate a key rotation without
     * re-signing - K2/K3 only read the stored (algorithm, key_id).
     */
    protected function retireCheckpoint(string $checkpointId, string $keyId = 'old-key'): void
    {
        Checkpoint::query()->where('id', $checkpointId)->update(['key_id' => $keyId]);
    }

    /**
     * Register a second, verify-only key in core's signing ring, so it appears as
     * a "Signing key" filter option (KeyRing::all() drives the options). Uses
     * core's published dev public key and rebinds the KeyRing singleton so the
     * new key is visible. Pair with retireCheckpoint($id, $keyId) to put a
     * checkpoint under it.
     */
    protected function registerRetiredKey(string $keyId = 'retired-key'): void
    {
        Config::set("chronicle.signing.keys.$keyId", [
            'provider' => Ed25519SigningProvider::class,
            'algorithm' => 'ed25519',
            'public_key' => 'S3M/krzRO2474ArA6LY55R3ycmeF1v5FF3praMmmvdk=',
        ]);

        $this->app->forgetInstance(KeyRing::class);
    }
}
