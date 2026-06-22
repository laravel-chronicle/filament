<?php

declare(strict_types=1);

namespace Chronicle\Filament\Resources\ChronicleEntryResource\Pages;

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Jobs\VerifyLedgerJob;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Widgets\VerificationHealthWidget;
use Chronicle\Verification\IntegrityVerifier;
use Chronicle\Verification\VerificationFailure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

class ListEntries extends ListRecords
{
    protected static string $resource = ChronicleEntryResource::class;

    /**
     * @return Collection<int, Model>|Paginator<int, Model>|CursorPaginator<int, Model>
     */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();

        $ids = [];

        foreach ($records instanceof Collection ? $records->all() : $records->items() as $record) {
            if ($record instanceof Entry) {
                $ids[] = $record->id;
            }
        }

        app(VerificationResultStore::class)->primeEntries($ids);

        return $records;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('verifyChain')
                ->label('Verify chain')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->visible(fn (): bool => ChronicleFilamentPlugin::get()->canVerify())
                ->action(function (): void {
                    /** @var class-string<Model> $model */
                    $model = ChronicleEntryResource::getModel();
                    $maxSequence = $model::query()->max('sequence');
                    $count = is_numeric($maxSequence) ? (int) $maxSequence : 0;
                    $threshold = Config::integer('chronicle-filament.verification.queue_threshold', 1000);

                    if ($count > $threshold) {
                        VerifyLedgerJob::dispatch('chain', null, null, Auth::id());

                        Notification::make()
                            ->title('Chain verification queued')
                            ->body("Verifying $count entries in the background; you'll be notified on completion.")
                            ->info()
                            ->send();

                        return;
                    }

                    $result = app(IntegrityVerifier::class)->verify();
                    app(VerificationResultStore::class)->recordChain($result);

                    $notification = Notification::make()
                        ->title($result->isValid() ? 'Chain verified' : 'Chain verification failed');

                    $result->isValid()
                        ? $notification->success()->send()
                        : $notification->danger()->body('Failure: '.(VerificationFailure::tryFrom((string) $result->failureType())->name ?? 'unknown'))->send();
                }),
        ];
    }

    /**
     * @return array<class-string<Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            VerificationHealthWidget::class,
        ];
    }
}
