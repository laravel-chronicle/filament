<?php

declare(strict_types=1);

namespace Chronicle\Filament\Resources\ChronicleEntryResource\Pages;

use Chronicle\Filament\Resources\ChronicleEntryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListEntries extends ListRecords
{
    protected static string $resource = ChronicleEntryResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        // No Create action - read-only.
        return [];
    }
}
