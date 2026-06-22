<?php

declare(strict_types=1);

namespace Chronicle\Filament\Resources\ChronicleEntryResource\Pages;

use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Support\VerificationResultStore;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

class ListEntries extends ListRecords
{
    protected static string $resource = ChronicleEntryResource::class;

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();

        app(VerificationResultStore::class)->primeEntries(
            collect($records->items() ?? $records->all())->map(fn ($record) => (string) $record->getKey()),
        );

        return $records;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        // No Create action - read-only.
        return [];
    }
}
