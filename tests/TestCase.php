<?php

declare(strict_types=1);

namespace Chronicle\Filament\Tests;

use Chronicle\ChronicleServiceProvider;
use Chronicle\Filament\ChronicleFilamentServiceProvider;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ChronicleServiceProvider::class,
            ChronicleFilamentServiceProvider::class,
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
}
