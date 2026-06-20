<?php

declare(strict_types=1);

namespace Chronicle\Filament\Resources\ChronicleEntryResource\Pages;

use Chronicle\Filament\Resources\ChronicleEntryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewEntry extends ViewRecord
{
    protected static string $resource = ChronicleEntryResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        // No Edit/Delete actions - read-only. Verify actions arrive in Session 4.
        return [];
    }
}
