<?php

declare(strict_types=1);

namespace Chronicle\Filament\Tests\Fixtures;

use Chronicle\Filament\ChronicleFilamentPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(ChronicleFilamentPlugin::make());
    }
}
