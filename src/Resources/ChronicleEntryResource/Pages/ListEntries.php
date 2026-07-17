<?php

declare(strict_types=1);

namespace Chronicle\Filament\Resources\ChronicleEntryResource\Pages;

use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Entry\Entry;
use Chronicle\Facades\Chronicle;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Jobs\ComplianceReportJob;
use Chronicle\Filament\Jobs\ExportLedgerJob;
use Chronicle\Filament\Jobs\VerifyAnchorsJob;
use Chronicle\Filament\Jobs\VerifyLedgerJob;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Support\ComplianceReportStore;
use Chronicle\Filament\Support\ExportArtifactStore;
use Chronicle\Filament\Support\SubjectErasureStore;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Widgets\AnchorCoverageWidget;
use Chronicle\Filament\Widgets\CryptoShreddingWidget;
use Chronicle\Filament\Widgets\SigningKeyRingWidget;
use Chronicle\Filament\Widgets\VerificationHealthWidget;
use Chronicle\Reports\ComplianceReport;
use Chronicle\Verification\AnchorVerifier;
use Chronicle\Verification\ExportVerifier;
use Chronicle\Verification\IntegrityVerifier;
use Chronicle\Verification\VerificationFailure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * The entry browse page. Primes the verification result store once per render so
 * status badges stay query-free, hosts the "Verify chain" header action (sync or
 * queued), and mounts the verification health widget.
 */
class ListEntries extends ListRecords
{
    protected static string $resource = ChronicleEntryResource::class;

    /**
     * Load the page of records and prime the verification result store for them
     * in a single query, so status badges render without per-row lookups.
     *
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

        // Prime crypto-shredding state for the page in two queries, so the erasure
        // column renders without per-row lookups. Skipped entirely when the
        // surfaces are off, so a disabled panel pays nothing.
        if (ChronicleFilamentPlugin::get()->isCryptoShreddingEnabled()) {
            $entries = [];

            foreach ($records instanceof Collection ? $records->all() : $records->items() as $record) {
                if ($record instanceof Entry) {
                    $entries[] = $record;
                }
            }

            app(SubjectErasureStore::class)->prime($entries);
        }

        return $records;
    }

    /**
     * The gated "Verify chain" header action: runs the full-ledger verifier
     * synchronously below the queue threshold and dispatches a queued job above
     * it, recording the outcome to the store.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportLedger')
                ->label('Export ledger')
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->modalHeading('Export the full ledger')
                ->modalDescription('This exports the entire dataset as a signed, verifiable bundle (plaintext for unencrypted columns, ciphertext for encrypted fields). Only export to least-privilege storage. The export runs in the background - you will be notified when the signed bundle is ready.')
                ->modalSubmitActionLabel('Queue export')
                ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isExportsEnabled()
                    && ChronicleFilamentPlugin::get()->canExport())
                ->action(function (): void {
                    // Re-check at execution: ->visible() hides the button, it does not
                    // stop a crafted call. Mirror the visible() gate exactly.
                    if (! ChronicleFilamentPlugin::get()->isExportsEnabled()
                        || ! ChronicleFilamentPlugin::get()->canExport()) {
                        Notification::make()->title('Export is not permitted')->danger()->send();

                        return;
                    }

                    // Exports are ALWAYS queued - never run in the request.
                    ExportLedgerJob::dispatch(Auth::id());

                    Notification::make()
                        ->title('Export queued')
                        ->body("The full-dataset export is running in the background; you'll be notified when the signed bundle is ready.")
                        ->info()
                        ->send();
                }),
            Action::make('verifyExport')
                ->label('Verify export')
                ->icon('heroicon-o-shield-check')
                ->modalHeading('Verify an export bundle')
                ->modalDescription('Re-verify a signed bundle against this ledger\'s key ring. Pick a prior bundle from the exports disk, or upload a zip.')
                ->modalSubmitActionLabel('Verify')
                ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isExportsEnabled()
                    && ChronicleFilamentPlugin::get()->canExport())
                ->schema([
                    Select::make('bundle')
                        ->label('Prior bundle on the exports disk')
                        ->options(fn (): array => app(ExportArtifactStore::class)
                            ->all()
                            ->mapWithKeys(fn ($artifact): array => [$artifact->path => $artifact->name])
                            ->all())
                        ->searchable(),
                    FileUpload::make('upload')
                        ->label('...or upload a zip bundle')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->disk('local')
                        ->directory('chronicle-verify-uploads'),
                ])
                ->action(function (array $data): void {
                    // Re-check at execution: ->visible() only hides the button.
                    if (! ChronicleFilamentPlugin::get()->isExportsEnabled()
                        || ! ChronicleFilamentPlugin::get()->canExport()) {
                        Notification::make()->title('Export verification is not permitted')->danger()->send();

                        return;
                    }

                    $store = app(ExportArtifactStore::class);

                    $contents = $this->resolveBundleContents($store, $data);

                    if ($contents === null) {
                        Notification::make()
                            ->title('Choose a bundle to verify')
                            ->body('Pick a prior bundle or upload a zip.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $dir = $store->extractToLocalDir($contents);

                    try {
                        $result = app(ExportVerifier::class)->verify($dir);
                    } finally {
                        $store->deleteLocalDir($dir);
                    }

                    if ($result->isValid()) {
                        Notification::make()
                            ->title('Export verified')
                            ->body("{$result->entryCount()} entries; dataset hash ".substr((string) $result->datasetHash(), 0, 12).'.')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Export verification failed')
                        ->body('Reason: '.($result->failureCode() ?? 'unknown'))
                        ->danger()
                        ->send();
                }),
            Action::make('downloadLatestExport')
                ->label('Download latest export')
                ->icon('heroicon-o-arrow-down-on-square')
                ->color('gray')
                ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isExportsEnabled()
                    && ChronicleFilamentPlugin::get()->canExport()
                    && app(ExportArtifactStore::class)->latest() !== null)
                ->action(function () {
                    // Re-check at execution: ->visible() hides the button, it does not
                    // stop a crafted call. This action egresses the full dataset.
                    if (! ChronicleFilamentPlugin::get()->isExportsEnabled()
                        || ! ChronicleFilamentPlugin::get()->canExport()) {
                        Notification::make()->title('Export is not permitted')->danger()->send();

                        return null;
                    }

                    $store = app(ExportArtifactStore::class);
                    $latest = $store->latest();

                    if ($latest === null) {
                        // TOCTOU guard: visible() already requires latest() !== null, so this
                        // only fires if a bundle is deleted between render and click.
                        // @codeCoverageIgnoreStart
                        Notification::make()->title('No export bundles yet')->info()->send();

                        return null;
                        // @codeCoverageIgnoreEnd
                    }

                    // Egresses the full dataset - already gated on canExport().
                    return $store->disk()->download($latest->path, $latest->name);
                }),
            Action::make('complianceReport')
                ->label('Compliance report')
                ->icon('heroicon-o-document-check')
                ->modalHeading('Generate a signed compliance report')
                ->modalDescription('Produce a signed compliance report over a period (leave both dates blank to cover the whole ledger). The report summarises ledger integrity and coverage and is signed by the key ring; it egresses period-filtered ledger data, so it is gated like an export. Large periods run in the background - you will be notified when the signed report is ready.')
                ->modalSubmitActionLabel('Generate report')
                ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isReportingEnabled()
                    && ChronicleFilamentPlugin::get()->canExport())
                ->schema([
                    DatePicker::make('from')
                        ->label('From (inclusive)')
                        ->native(false),
                    DatePicker::make('to')
                        ->label('To (inclusive)')
                        ->native(false),
                ])
                ->action(function (array $data): ?Response {
                    // Re-check at execution: ->visible() only hides the button. This
                    // action egresses period-filtered ledger data.
                    if (! ChronicleFilamentPlugin::get()->isReportingEnabled()
                        || ! ChronicleFilamentPlugin::get()->canExport()) {
                        Notification::make()->title('Report generation is not permitted')->danger()->send();

                        return null;
                    }

                    $from = isset($data['from']) && is_string($data['from']) && $data['from'] !== ''
                        ? Carbon::parse($data['from'])->startOfDay()
                        : null;
                    $to = isset($data['to']) && is_string($data['to']) && $data['to'] !== ''
                        ? Carbon::parse($data['to'])->endOfDay()
                        : null;

                    // Queue heavy reports; count the period's entries the same way core will.
                    if ($this->periodEntryCount($from, $to) > ChronicleFilamentPlugin::get()->getExportsQueueThreshold()) {
                        ComplianceReportJob::dispatch($from?->toIso8601String(), $to?->toIso8601String(), Auth::id());

                        Notification::make()
                            ->title('Report queued')
                            ->body("The compliance report is running in the background; you'll be notified when the signed report is ready.")
                            ->info()
                            ->send();

                        return null;
                    }

                    $tmp = (string) tempnam(sys_get_temp_dir(), 'chronicle-report-');

                    try {
                        $result = app(ComplianceReport::class)->generate($tmp, $from, $to);
                    } finally {
                        @unlink($tmp);
                    }

                    if ($result->isEmpty()) {
                        Notification::make()
                            ->title('Report covers no entries')
                            ->body('No ledger entries fall in the selected period; nothing was generated.')
                            ->warning()
                            ->send();

                        return null;
                    }

                    // Store the signed bundle for later download, then render the
                    // report HTML inline so the browser displays it immediately.
                    app(ComplianceReportStore::class)->store($result);

                    return response($result->html, 200, ['Content-Type' => 'text/html']);
                }),
            Action::make('downloadLatestReport')
                ->label('Download latest report')
                ->icon('heroicon-o-arrow-down-on-square')
                ->color('gray')
                ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isReportingEnabled()
                    && ChronicleFilamentPlugin::get()->canExport()
                    && app(ComplianceReportStore::class)->latest() !== null)
                ->action(function () {
                    // Re-check at execution: ->visible() hides the button, it does not
                    // stop a crafted call. This action egresses period-filtered data.
                    if (! ChronicleFilamentPlugin::get()->isReportingEnabled()
                        || ! ChronicleFilamentPlugin::get()->canExport()) {
                        Notification::make()->title('Report generation is not permitted')->danger()->send();

                        return null;
                    }

                    $store = app(ComplianceReportStore::class);
                    $latest = $store->latest();

                    if ($latest === null) {
                        // TOCTOU guard: visible() already requires latest() !== null, so this
                        // only fires if a bundle is deleted between render and click.
                        // @codeCoverageIgnoreStart
                        Notification::make()->title('No compliance reports yet')->info()->send();

                        return null;
                        // @codeCoverageIgnoreEnd
                    }

                    // Egresses period-filtered ledger data - already gated on canExport().
                    return $store->disk()->download($latest->path, $latest->name);
                }),
            Action::make('verifyChain')
                ->label('Verify chain')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->visible(fn (): bool => ChronicleFilamentPlugin::get()->canVerify())
                ->action(function (): void {
                    // Re-check at execution: ->visible() only hides the button.
                    if (! ChronicleFilamentPlugin::get()->canVerify()) {
                        Notification::make()->title('Verification is not permitted')->danger()->send();

                        return;
                    }

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
            Action::make('verifyAllAnchors')
                ->label('Verify all anchors')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isAnchoringEnabled()
                    && ChronicleFilamentPlugin::get()->canVerify())
                ->action(function (): void {
                    // Re-check at execution: ->visible() hides the button, it does not
                    // stop a crafted call. Mirror the visible() gate exactly.
                    if (! ChronicleFilamentPlugin::get()->isAnchoringEnabled()
                        || ! ChronicleFilamentPlugin::get()->canVerify()) {
                        Notification::make()->title('Anchor verification is not permitted')->danger()->send();

                        return;
                    }

                    // In-scope = checkpoints carrying anchor rows (deliberate; never on render).
                    $checkpoints = Checkpoint::query()->has('anchors')->get();
                    $count = $checkpoints->count();

                    if ($count === 0) {
                        Notification::make()->title('No anchored checkpoints to verify')->info()->send();

                        return;
                    }

                    $threshold = ChronicleFilamentPlugin::get()->getVerifyAllQueueThreshold();

                    if ($count > $threshold) {
                        VerifyAnchorsJob::dispatch(Auth::id());

                        Notification::make()
                            ->title('Anchor verification queued')
                            ->body("Verifying anchors for $count checkpoints in the background; you'll be notified on completion.")
                            ->info()
                            ->send();

                        return;
                    }

                    $result = app(AnchorVerifier::class)->verify($checkpoints);

                    $notification = Notification::make()
                        ->title($result->isValid() ? 'All anchors verified' : 'Anchor verification failed');

                    $result->isValid()
                        ? $notification->success()->send()
                        : $notification->danger()->body('Failure: '.VerificationFailure::AnchorInvalid->name)->send();
                }),
        ];
    }

    /**
     * Read the raw zip bytes for the verify-export action: prefer an uploaded
     * file, else the selected prior bundle on the exports' disk. Returns null when
     * neither was provided.
     *
     * Accepts the raw Filament action-data array (key type is whatever the form
     * state carries); only the string `upload`/`bundle` keys are read.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function resolveBundleContents(ExportArtifactStore $store, array $data): ?string
    {
        $upload = $data['upload'] ?? null;

        if (is_string($upload) && $upload !== '') {
            return (string) Storage::disk('local')->get($upload);
        }

        $bundle = $data['bundle'] ?? null;

        if (is_string($bundle) && $bundle !== '') {
            return (string) $store->disk()->get($bundle);
        }

        return null;
    }

    /**
     * Count the ledger entries a report over the given period will cover, matching
     * core's ComplianceReport filtering (created_at >= from, <= to). Read-only.
     */
    protected function periodEntryCount(?Carbon $from, ?Carbon $to): int
    {
        $query = Chronicle::newEntryQuery();

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query->count();
    }

    /**
     * @return array<class-string<Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            VerificationHealthWidget::class,
            AnchorCoverageWidget::class,
            SigningKeyRingWidget::class,
            CryptoShreddingWidget::class,
        ];
    }
}
