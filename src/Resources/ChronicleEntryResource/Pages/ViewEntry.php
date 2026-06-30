<?php

declare(strict_types=1);

namespace Chronicle\Filament\Resources\ChronicleEntryResource\Pages;

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Support\SubjectErasureStore;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The read-only entry detail page. Renders the infolist defined on the resource
 * and exposes no header actions - the detail view can never mutate an entry.
 */
class ViewEntry extends ViewRecord
{
    protected static string $resource = ChronicleEntryResource::class;

    /**
     * Prime the crypto-shredding store for the viewed record, so the erasure
     * detail section reads state/hold in two queries with no DEK unwrap. Skipped
     * when the surfaces are off.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (ChronicleFilamentPlugin::get()->isCryptoShreddingEnabled() && $this->record instanceof Entry) {
            app(SubjectErasureStore::class)->prime([$this->record]);
        }
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        // No Edit/Delete actions - read-only. The Verify-anchor action only reads and
        // records a verification result; it never mutates the entry or anchor.
        return [
            ChronicleEntryResource::verifyAnchorAction(),
        ];
    }
}
