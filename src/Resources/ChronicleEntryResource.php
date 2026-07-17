<?php

declare(strict_types=1);

namespace Chronicle\Filament\Resources;

use BackedEnum;
use Carbon\CarbonInterface;
use Chronicle\Anchoring\CheckpointAnchor;
use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Encryption\SubjectKey;
use Chronicle\Entry\Entry;
use Chronicle\Facades\Chronicle;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Jobs\VerifyLedgerJob;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Chronicle\Filament\Support\AnchorState;
use Chronicle\Filament\Support\ErasureState;
use Chronicle\Filament\Support\KeyRingSnapshot;
use Chronicle\Filament\Support\PreviousHash;
use Chronicle\Filament\Support\ReferenceLabel;
use Chronicle\Filament\Support\SigningKeyState;
use Chronicle\Filament\Support\SubjectErasureStore;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Support\VerificationState;
use Chronicle\Lifecycle\LegalHold;
use Chronicle\Verification\AnchorVerifier;
use Chronicle\Verification\EntryVerifier;
use Chronicle\Verification\IntegrityVerifier;
use Chronicle\Verification\VerificationFailure;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Clusters\Cluster;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * The read-only Filament resource for the Chronicle audit ledger: browse and
 * view entries, filter by action/actor/subject/date/verification state, and run
 * the gated verify actions (entry, chain, segment). Every mutation ability is
 * hard-denied and no Create/Edit pages exist, so the panel can never rewrite
 * history.
 *
 * @extends resource<Entry>
 */
class ChronicleEntryResource extends Resource
{
    /**
     * Resolve the entry model from config so a host Entry subclass is honored
     * end-to-end (core >= 1.13 also reads chronicle.models.entry).
     *
     * @return class-string<Entry>
     */
    public static function getModel(): string
    {
        /** @var class-string<Entry> $model */
        $model = Config::string('chronicle-filament.entry_model', Entry::class);

        return $model;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return ChronicleFilamentPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return ChronicleFilamentPlugin::get()->getNavigationSort();
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return 'heroicon-o-shield-check';
    }

    public static function getNavigationLabel(): string
    {
        return 'Audit Log';
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return ChronicleFilamentPlugin::get()->getSlug();
    }

    /**
     * @return class-string<Cluster>|null
     */
    public static function getCluster(): ?string
    {
        return ChronicleFilamentPlugin::get()->getCluster();
    }

    // --- Read-only invariant (UI-layer defence in depth) ---

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canView(Model $record): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    /**
     * The entry browse table: columns, filters (including verification state),
     * and the gated verify actions (per-row, and segment as a bulk action).
     * Defaults to newest-first and defers loading.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sequence', 'desc')
            ->deferLoading()
            ->persistFiltersInSession()
            ->columns([
                TextColumn::make('sequence')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('action')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('actor')
                    ->label('Actor')
                    ->state(fn (Entry $record): string => ReferenceLabel::for($record->actor_type, $record->actor_id)),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->state(fn (Entry $record): string => ReferenceLabel::for((string) $record->subject_type, (string) $record->subject_id)),
                TextColumn::make('verification_status')
                    ->label('Verified')
                    ->badge()
                    ->state(fn (Entry $record): string => app(VerificationResultStore::class)->entryState($record->id)->label())
                    ->color(fn (Entry $record): string => app(VerificationResultStore::class)->entryState($record->id)->color())
                    ->icon(fn (Entry $record): string => app(VerificationResultStore::class)->entryState($record->id)->icon())
                    ->tooltip(fn (Entry $record): ?string => static::verificationTooltip($record))
                    ->toggleable(),
                TextColumn::make('anchor_state')
                    ->label('Anchor')
                    ->badge()
                    ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isAnchoringEnabled())
                    ->state(fn (Entry $record): string => AnchorState::forEntry($record)->label())
                    ->color(fn (Entry $record): string => AnchorState::forEntry($record)->color())
                    ->icon(fn (Entry $record): string => AnchorState::forEntry($record)->icon())
                    ->toggleable(),
                TextColumn::make('signing_key')
                    ->label('Signing key')
                    ->badge()
                    ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isSigningKeysEnabled())
                    ->state(fn (Entry $record): string => $record->checkpoint->key_id ?? 'Unsigned')
                    ->color(fn (Entry $record): string => KeyRingSnapshot::make()->forEntry($record)->color())
                    ->icon(fn (Entry $record): string => KeyRingSnapshot::make()->forEntry($record)->icon())
                    ->tooltip(fn (Entry $record): ?string => static::signingKeyTooltip($record))
                    ->toggleable(),
                TextColumn::make('erasure_state')
                    ->label('Erasure')
                    ->badge()
                    ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isCryptoShreddingEnabled())
                    ->state(fn (Entry $record): string => app(SubjectErasureStore::class)->stateFor($record)->label())
                    ->color(fn (Entry $record): string => app(SubjectErasureStore::class)->stateFor($record)->color())
                    ->icon(fn (Entry $record): string => app(SubjectErasureStore::class)->stateFor($record)->icon())
                    ->description(fn (Entry $record): ?string => app(SubjectErasureStore::class)->isHeld($record) ? 'On hold' : null)
                    ->tooltip(fn (Entry $record): ?string => static::erasureTooltip($record))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => Entry::query()
                        ->select('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),
                SelectFilter::make('actor_type')
                    ->label('Actor type')
                    ->options(fn (): array => Entry::query()
                        ->select('actor_type')
                        ->distinct()
                        ->orderBy('actor_type')
                        ->pluck('actor_type', 'actor_type')
                        ->all()),
                SelectFilter::make('subject_type')
                    ->label('Subject type')
                    ->options(fn (): array => Entry::query()
                        ->whereNotNull('subject_type')
                        ->select('subject_type')
                        ->distinct()
                        ->orderBy('subject_type')
                        ->pluck('subject_type', 'subject_type')
                        ->all()),
                Filter::make('recorded')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (is_string($from)) {
                            $query->whereDate('created_at', '>=', $from);
                        }

                        if (is_string($until)) {
                            $query->whereDate('created_at', '<=', $until);
                        }

                        return $query;
                    }),
                SelectFilter::make('verification_status')
                    ->label('Verification')
                    ->options([
                        'verified' => 'Verified',
                        'failed' => 'Failed',
                        'unverified' => 'Unverified',
                        'stale' => 'Stale',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        $state = VerificationState::from($value);
                        $store = app(VerificationResultStore::class);

                        if ($state === VerificationState::Unverified) {
                            // Unverified = no stored record at all: exclude every entry that
                            // has any stored state.
                            $recorded = array_merge(
                                $store->entryIdsWithState(VerificationState::Verified),
                                $store->entryIdsWithState(VerificationState::Failed),
                                $store->entryIdsWithState(VerificationState::Stale),
                            );

                            return $query->whereNotIn('id', $recorded);
                        }

                        return $query->whereIn('id', $store->entryIdsWithState($state));
                    }),
                SelectFilter::make('anchor_state')
                    ->label('Anchor')
                    ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isAnchoringEnabled())
                    ->options([
                        'anchored' => 'Anchored',
                        'pending' => 'Pending',
                        'failed' => 'Failed',
                        'unanchored' => 'Unanchored',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        return match (AnchorState::from($value)) {
                            AnchorState::Anchored => $query->whereHas('checkpoint.anchors', fn (Builder $q): Builder => $q->where('status', 'anchored')),
                            AnchorState::Failed => $query
                                ->whereHas('checkpoint.anchors', fn (Builder $q): Builder => $q->where('status', 'failed'))
                                ->whereDoesntHave('checkpoint.anchors', fn (Builder $q): Builder => $q->where('status', 'anchored')),
                            AnchorState::Pending => $query
                                ->whereHas('checkpoint.anchors', fn (Builder $q): Builder => $q->where('status', 'pending'))
                                ->whereDoesntHave('checkpoint.anchors', fn (Builder $q): Builder => $q->whereIn('status', ['anchored', 'failed'])),
                            AnchorState::Unanchored => $query->where(fn (Builder $q): Builder => $q
                                ->whereNull('checkpoint_id')
                                ->orWhereDoesntHave('checkpoint.anchors')),
                            default => $query,
                        };
                    }),
                SelectFilter::make('signing_key')
                    ->label('Signing key')
                    ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isSigningKeysEnabled())
                    ->options(fn (): array => static::signingKeyFilterOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        // Filter values are ring labels "{algorithm}:{keyId}".
                        [$algorithm, $keyId] = array_pad(explode(':', $value, 2), 2, '');

                        return $query->whereHas('checkpoint', fn (Builder $q): Builder => $q
                            ->where('algorithm', $algorithm)
                            ->where('key_id', $keyId));
                    }),
                SelectFilter::make('erasure_state')
                    ->label('Erasure')
                    ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isCryptoShreddingEnabled())
                    ->options([
                        'encrypted' => 'Encrypted',
                        'erased' => 'Erased',
                        'not_encrypted' => 'Not encrypted',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        $keys = (new SubjectKey)->getTable();
                        $entries = $query->getModel()->getTable();

                        $correlate = function (QueryBuilder $sub) use ($keys, $entries): QueryBuilder {
                            return $sub->select(DB::raw(1))
                                ->from($keys)
                                ->whereColumn("$keys.subject_type", "$entries.subject_type")
                                ->whereColumn("$keys.subject_id", "$entries.subject_id");
                        };

                        return match (ErasureState::from($value)) {
                            ErasureState::Encrypted => $query->whereExists(
                                fn (QueryBuilder $sub) => $correlate($sub)->where('status', 'active'),
                            ),
                            ErasureState::Erased => $query->whereExists(
                                fn (QueryBuilder $sub) => $correlate($sub)->where('status', 'erased'),
                            ),
                            ErasureState::NotEncrypted => $query->whereNotExists(
                                fn (QueryBuilder $sub) => $correlate($sub),
                            ),
                        };
                    }),
                SelectFilter::make('legal_hold')
                    ->label('Legal hold')
                    ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isCryptoShreddingEnabled())
                    ->options([
                        'held' => 'On hold',
                        'released' => 'Not on hold',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        $holds = (new LegalHold)->getTable();
                        $entries = $query->getModel()->getTable();

                        $correlate = function (QueryBuilder $sub) use ($holds, $entries): QueryBuilder {
                            return $sub->select(DB::raw(1))
                                ->from($holds)
                                ->whereColumn("$holds.subject_type", "$entries.subject_type")
                                ->whereColumn("$holds.subject_id", "$entries.subject_id")
                                ->whereNull("$holds.released_at");
                        };

                        return $value === 'held'
                            ? $query->whereExists(fn (QueryBuilder $sub) => $correlate($sub))
                            : $query->whereNotExists(fn (QueryBuilder $sub) => $correlate($sub));
                    }),
                Filter::make('erasure_proofs')
                    ->label('Erasure proofs only')
                    ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isCryptoShreddingEnabled())
                    ->query(fn (Builder $query): Builder => $query->where('action', 'subject.erased')),
            ])
            ->recordActions([
                Action::make('verifyEntry')
                    ->label('Verify')
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn (Entry $record): bool => ChronicleFilamentPlugin::get()->canVerify($record))
                    ->action(function (Entry $record): void {
                        // Re-check at execution: ->visible() hides the button, it does
                        // not stop a crafted call. Mirror the visible() gate so
                        // render-time and execution-time authorization can't disagree.
                        if (! ChronicleFilamentPlugin::get()->canVerify($record)) {
                            Notification::make()->title('Verification is not permitted')->danger()->send();

                            return;
                        }

                        $result = app(EntryVerifier::class)->verify($record->id);
                        app(VerificationResultStore::class)->recordEntry($record->id, $result);

                        $notification = Notification::make()
                            ->title($result->isValid() ? 'Entry verified' : 'Entry verification failed');

                        $result->isValid()
                            ? $notification->success()->send()
                            : $notification->danger()->body(
                                'Failure: '.(VerificationFailure::tryFrom((string) $result->failureCode())->name ?? 'unknown'),
                            )->send();
                    }),
                static::verifyAnchorAction(),
                static::eraseSubjectAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('verifySegment')
                        ->label('Verify segment')
                        ->icon('heroicon-o-shield-check')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn (): bool => ChronicleFilamentPlugin::get()->canVerify())
                        ->action(function (Collection $records): void {
                            // Re-check at execution: ->visible() only hides the button.
                            if (! ChronicleFilamentPlugin::get()->canVerify()) {
                                Notification::make()->title('Verification is not permitted')->danger()->send();

                                return;
                            }

                            $sequences = [];

                            foreach ($records as $record) {
                                if ($record instanceof Entry) {
                                    $sequences[] = $record->sequence;
                                }
                            }

                            if ($sequences === []) {
                                return;
                            }

                            $min = min($sequences);
                            $max = max($sequences);
                            $span = $max - $min + 1;
                            $threshold = Config::integer('chronicle-filament.verification.queue_threshold', 1000);

                            if ($span > $threshold) {
                                VerifyLedgerJob::dispatch('segment', $min, $max, Auth::id(), 'segment');

                                Notification::make()
                                    ->title('Segment verification queued')
                                    ->body("Verifying entries $min-$max in the background.")
                                    ->info()
                                    ->send();

                                return;
                            }

                            $result = app(IntegrityVerifier::class)->verifyEntryRange($min, $max);
                            app(VerificationResultStore::class)->recordChain($result, 'segment');

                            $notification = Notification::make()
                                ->title($result->isValid() ? "Segment $min-$max verified" : "Segment $min-$max verification failed");

                            $result->isValid()
                                ? $notification->success()->send()
                                : $notification->danger()->body(
                                    'First failure at entry '.((string) $result->entryId()).': '.(VerificationFailure::tryFrom((string) $result->failureType())->name ?? 'unknown'),
                                )->send();
                        }),
                ]),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEntries::route('/'),
            'view' => ViewEntry::route('/{record}'),
        ];
    }

    /**
     * Eager-load each entry's checkpoint so signature/anchor fields render
     * without per-row queries.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('checkpoint.anchors');
    }

    /**
     * The read-only entry detail infolist: collapsible Identity, Integrity,
     * Signature, Payload, and Decrypted sections, rendered through the model's
     * decrypted accessors with an erased-subject indicator.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns()
                ->collapsible()
                ->components([
                    TextEntry::make('sequence')->label('Sequence #'),
                    TextEntry::make('created_at')->label('Recorded')->dateTime(),
                    TextEntry::make('action')->badge(),
                    TextEntry::make('correlation_id')->label('Correlation ID')->placeholder('-'),
                    TextEntry::make('actor')
                        ->label('Actor')
                        ->state(fn (Entry $record): string => ReferenceLabel::for($record->actor_type, $record->actor_id)),
                    TextEntry::make('subject')
                        ->label('Subject')
                        ->state(fn (Entry $record): string => ReferenceLabel::for((string) $record->subject_type, (string) $record->subject_id)),
                    TextEntry::make('tags')
                        ->badge()
                        ->state(fn (Entry $record): array => $record->tags ?? []),
                ]),
            Section::make('Integrity')
                ->columns(1)
                ->collapsible()
                ->components([
                    TextEntry::make('chain_hash')->label('Current hash')->copyable(),
                    TextEntry::make('previous_hash')
                        ->label('Previous hash')
                        ->state(fn (Entry $record): string => PreviousHash::for($record))
                        ->copyable(),
                    TextEntry::make('payload_hash')->label('Payload hash')->copyable(),
                ]),
            Section::make('Signature')
                ->columns(3)
                ->collapsible()
                ->components([
                    // Signature, algorithm, and key id live on the checkpoint,
                    // not the entry; null/placeholder when the entry is unanchored.
                    TextEntry::make('checkpoint.algorithm')->label('Algorithm')->placeholder('Unanchored'),
                    TextEntry::make('checkpoint.key_id')->label('Key ID')->placeholder('Unanchored'),
                    TextEntry::make('checkpoint.signature')->label('Signature')->placeholder('Unanchored')->copyable()->columnSpanFull(),
                    // Active/Retired state of the signing key, derived from the
                    // checkpoint's stored (algorithm, key_id) vs the active key.
                    // Hidden when unsigned (no checkpoint) or the gate is off.
                    TextEntry::make('signing_key_state')
                        ->label('Key state')
                        ->badge()
                        ->visible(fn (Entry $record): bool => ChronicleFilamentPlugin::get()->isSigningKeysEnabled()
                            && $record->checkpoint_id !== null)
                        ->state(fn (Entry $record): string => KeyRingSnapshot::make()->forEntry($record)->label())
                        ->color(fn (Entry $record): string => KeyRingSnapshot::make()->forEntry($record)->color())
                        ->icon(fn (Entry $record): string => KeyRingSnapshot::make()->forEntry($record)->icon()),
                    // A retired key still verifies the artifacts it signed - say so.
                    TextEntry::make('signing_key_hint')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->visible(fn (Entry $record): bool => ChronicleFilamentPlugin::get()->isSigningKeysEnabled()
                            && KeyRingSnapshot::make()->forEntry($record) === SigningKeyState::Retired)
                        ->state('Retired key - still verifies historical entries.'),
                ]),
            Section::make('Payload')
                ->collapsible()
                ->components([
                    // KeyValueEntry escapes each value as a string, so nested
                    // array values (e.g. empty context/metadata) must be encoded
                    // before display or rendering throws.
                    KeyValueEntry::make('payload')
                        ->state(fn (Entry $record): array => array_map(
                            static fn (mixed $value): string => match (true) {
                                $value === null => '-',
                                is_scalar($value) => (string) $value,
                                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
                            },
                            $record->payload,
                        )),
                ]),
            Section::make('Decrypted data')
                ->columns(1)
                ->collapsible()
                ->collapsed()
                ->components([
                    // Render via decrypted accessors (never raw casts); erased
                    // subjects surface a tombstone instead of ciphertext.
                    TextEntry::make('metadata')
                        ->state(fn (Entry $record): string => static::renderDecrypted($record->decryptedMetadata())),
                    TextEntry::make('context')
                        ->state(fn (Entry $record): string => static::renderDecrypted($record->decryptedContext())),
                    TextEntry::make('diff')
                        ->state(fn (Entry $record): string => static::renderDecrypted($record->decryptedDiff())),
                    TextEntry::make('erased')
                        ->label('Subject erased')
                        ->state(fn (Entry $record): string => $record->erased() ? 'Yes (crypto-shredded)' : 'No'),
                ]),
            Section::make('Subject erasure')
                ->columns()
                ->collapsible()
                ->visible(fn (): bool => ChronicleFilamentPlugin::get()->isCryptoShreddingEnabled())
                ->components([
                    TextEntry::make('erasure_state')
                        ->label('State')
                        ->badge()
                        ->state(fn (Entry $record): string => app(SubjectErasureStore::class)->stateFor($record)->label())
                        ->color(fn (Entry $record): string => app(SubjectErasureStore::class)->stateFor($record)->color())
                        ->icon(fn (Entry $record): string => app(SubjectErasureStore::class)->stateFor($record)->icon()),
                    TextEntry::make('erasure_hold')
                        ->label('Legal hold')
                        ->state(fn (Entry $record): string => app(SubjectErasureStore::class)->isHeld($record) ? 'On hold' : 'None'),
                    TextEntry::make('erasure_kek')
                        ->label('Wrapping KEK')
                        ->placeholder('-')
                        ->state(fn (Entry $record): ?string => app(SubjectErasureStore::class)->kekIdFor($record)),
                    TextEntry::make('erasure_erased_at')
                        ->label('Erased at')
                        ->dateTime()
                        ->placeholder('-')
                        ->state(fn (Entry $record): ?CarbonInterface => app(SubjectErasureStore::class)->erasedAtFor($record)),
                    TextEntry::make('erasure_requester')
                        ->label('Requested by')
                        ->placeholder('-')
                        ->visible(fn (Entry $record): bool => $record->action === 'subject.erased')
                        ->state(fn (Entry $record): string => static::erasureMetadata($record, 'requester')),
                    TextEntry::make('erasure_reason')
                        ->label('Reason')
                        ->placeholder('-')
                        ->columnSpanFull()
                        ->visible(fn (Entry $record): bool => $record->action === 'subject.erased')
                        ->state(fn (Entry $record): string => static::erasureMetadata($record, 'reason')),
                    TextEntry::make('erasure_hold_reason')
                        ->label('Hold reason')
                        ->placeholder('-')
                        ->visible(fn (Entry $record): bool => app(SubjectErasureStore::class)->isHeld($record))
                        ->state(fn (Entry $record): ?string => app(SubjectErasureStore::class)->heldReasonFor($record)),
                    TextEntry::make('erasure_hold_placed_at')
                        ->label('Hold placed at')
                        ->dateTime()
                        ->placeholder('-')
                        ->visible(fn (Entry $record): bool => app(SubjectErasureStore::class)->isHeld($record))
                        ->state(fn (Entry $record): ?CarbonInterface => app(SubjectErasureStore::class)->heldPlacedAtFor($record)),
                    TextEntry::make('erasure_notice')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->visible(fn (Entry $record): bool => app(SubjectErasureStore::class)->stateFor($record) === ErasureState::Erased)
                        ->state("This subject's personal data has been crypto-shredded and is permanently unreadable. The entry itself is unchanged and still verifies - its hash chain and signature are intact."),
                ]),
            Section::make('External anchoring')
                ->collapsible()
                ->components([
                    // Disabled / no-checkpoint / no-rows degrade to copy, never an error.
                    TextEntry::make('anchor_placeholder')
                        ->hiddenLabel()
                        ->state(fn (Entry $record): string => static::anchorEmptyLabel($record))
                        ->visible(fn (Entry $record): bool => static::anchorEmptyLabel($record) !== ''),
                    // Stored status only - no provider verification on render.
                    RepeatableEntry::make('checkpoint.anchors')
                        ->hiddenLabel()
                        ->visible(fn (Entry $record): bool => static::anchorEmptyLabel($record) === '')
                        ->schema([
                            TextEntry::make('provider'),
                            TextEntry::make('anchor_state')
                                ->label('Status')
                                ->badge()
                                ->state(fn (CheckpointAnchor $anchor): string => AnchorState::fromStatuses([$anchor->status])->label())
                                ->color(fn (CheckpointAnchor $anchor): string => AnchorState::fromStatuses([$anchor->status])->color())
                                ->icon(fn (CheckpointAnchor $anchor): string => AnchorState::fromStatuses([$anchor->status])->icon()),
                            TextEntry::make('anchored_at')->label('Anchored at')->dateTime()->placeholder('-'),
                            TextEntry::make('reference')->placeholder('-'),
                            TextEntry::make('proof')->copyable()->limit(32)->placeholder('-'),
                        ]),
                ]),        ]);
    }

    /**
     * The deliberate, read-only Verify-anchor action, shared by the table row
     * and the ViewEntry header. Calls AnchorVerifier::checkpointHasValidAnchor()
     * - never on render - records the outcome, and notifies. Hidden when the
     * entry is unanchored, anchoring is off, or authorization denies it.
     */
    public static function verifyAnchorAction(): Action
    {
        return Action::make('verifyAnchor')
            ->label('Verify anchor')
            ->icon('heroicon-o-link')
            ->requiresConfirmation()
            ->visible(fn (Entry $record): bool => ChronicleFilamentPlugin::get()->isAnchoringEnabled()
                && $record->checkpoint_id !== null
                && ChronicleFilamentPlugin::get()->canVerify($record))
            ->action(function (Entry $record): void {
                // Re-check at execution: ->visible() hides the button, it does not
                // stop a crafted call. Mirror the visible() gate's authorization
                // (the anchoredness half is re-checked by the instanceof guard below).
                if (! ChronicleFilamentPlugin::get()->isAnchoringEnabled()
                    || ! ChronicleFilamentPlugin::get()->canVerify($record)) {
                    Notification::make()->title('Anchor verification is not permitted')->danger()->send();

                    return;
                }

                $checkpoint = $record->checkpoint;

                if (! $checkpoint instanceof Checkpoint) {
                    Notification::make()->title('Entry is not anchored')->warning()->send();

                    return;
                }

                try {
                    $valid = app(AnchorVerifier::class)->checkpointHasValidAnchor($checkpoint);
                } catch (Throwable $e) {
                    // Provider errors (e.g. a TSA timeout) surface non-destructively.
                    Notification::make()
                        ->title('Anchor verification could not run')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                app(VerificationResultStore::class)->recordAnchor($checkpoint->id, $valid);

                $notification = Notification::make()
                    ->title($valid ? 'Anchor verified' : 'Anchor verification failed');

                $valid
                    ? $notification->success()->send()
                    : $notification->danger()->body('Failure: '.VerificationFailure::AnchorInvalid->name)->send();
            });
    }

    /**
     * The panel's ONLY write: the irreversible, off-by-default GDPR erase action.
     * Calls core's Chronicle::eraseSubject(), which destroys the subject's DEK and
     * APPENDS a subject.erased proof - it never updates or deletes an entry. Guards
     * (see docs/.../v1.3-s3.md "Guard enforcement matrix"): off by default (G1) +
     * separate deny-by-default authorize (G2), single-subject (G3), has-a-subject
     * (G4), type-to-confirm (G5) + mandatory reason (G6), legal hold blocks (G7),
     * idempotent (G9), no self-writes (G10). Each gate is enforced BOTH in
     * ->visible() and re-checked in the closure (defense in depth).
     */
    public static function eraseSubjectAction(): Action
    {
        return Action::make('eraseSubject')
            ->label('Erase subject (GDPR)')
            ->icon('heroicon-o-fire')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Erase subject (GDPR Article 17)')
            ->modalDescription('This permanently destroys the subject\'s encryption key. Existing entries are NOT changed - they stay in the ledger and still verify - but this subject\'s personal data becomes unreadable forever. This cannot be undone.')
            ->modalSubmitActionLabel('Erase subject')
            // G1 + G2 + G4: never mounts unless enabled, authorized, and the entry
            // has a subject. (G3: this is a per-record action, never a bulk action.)
            ->visible(fn (Entry $record): bool => static::canEraseSubject($record))
            ->schema(fn (Entry $record): array => [
                // G5: type-to-confirm the exact subject reference.
                TextInput::make('confirm_subject')
                    ->label('Type the subject to confirm')
                    ->helperText('Enter exactly: '.$record->subject_type.':'.$record->subject_id)
                    ->required()
                    ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($value !== $record->subject_type.':'.$record->subject_id) {
                            $fail('The subject does not match. Type it exactly as shown.');
                        }
                    }),
                // G6: a reason is mandatory.
                Textarea::make('reason')
                    ->label('Reason (required)')
                    ->required()
                    ->maxLength(1000),
                // G8: a distinct, required confirmation - present ONLY when the
                // subject is on hold AND overriding a hold is permitted.
                Checkbox::make('legal_hold_override')
                    ->label('I understand this subject is on legal hold and I am overriding it.')
                    ->visible(fn (): bool => $record->subject_type !== null
                        && $record->subject_id !== null
                        && ChronicleFilamentPlugin::get()->isEraseHoldOverrideAllowed()
                        && LegalHold::isHeld($record->subject_type, $record->subject_id))
                    ->accepted()
                    ->required(),
            ])
            ->action(function (Entry $record, array $data): void {
                // G1 + G2 + G4 re-checked at execution time: defeat any crafted call.
                if (! static::canEraseSubject($record)) {
                    Notification::make()->title('Erase is not permitted')->danger()->send();

                    return;
                }

                /** @var string $type */
                $type = $record->subject_type;
                /** @var string $id */
                $id = $record->subject_id;

                // G7/G8: a fresh hold read (not a render snapshot). A hold blocks
                // unless overriding is permitted AND the distinct override checkbox
                // was accepted - only then is legalHoldOverride passed and recorded.
                $held = LegalHold::isHeld($type, $id);
                $override = false;

                if ($held) {
                    $override = ChronicleFilamentPlugin::get()->isEraseHoldOverrideAllowed()
                        && ($data['legal_hold_override'] ?? false) === true;

                    if (! $override) {
                        Notification::make()
                            ->title('Subject is on legal hold')
                            ->body('Erasure is blocked while an active legal hold exists.')
                            ->danger()
                            ->send();

                        return;
                    }
                }

                $reason = is_string($data['reason'] ?? null) ? trim((string) $data['reason']) : '';

                // G10: the only write - core destroys the DEK and APPENDS the proof.
                $erased = Chronicle::eraseSubject(
                    $type,
                    $id,
                    requester: static::eraseRequester(),
                    reason: $reason !== '' ? $reason : null,
                    legalHoldOverride: $override,
                );

                // G9: already erased -> friendly no-op, not an error.
                if (! $erased) {
                    Notification::make()
                        ->title('Subject already erased')
                        ->body('No changes were made - this subject was already crypto-shredded.')
                        ->info()
                        ->send();

                    return;
                }

                // Re-prime the store so the row/detail reflect the new tombstone.
                app(SubjectErasureStore::class)->prime([$record]);

                Notification::make()
                    ->title('Subject erased')
                    ->body('The subject\'s key was destroyed and a subject.erased proof was appended. The ledger is unchanged and still verifies.')
                    ->success()
                    ->send();
            });
    }

    /**
     * The single erase gate: enabled (G1) AND authorized (G2) AND the entry has a
     * subject (G4). Used by both ->visible() and the closure re-check. Never
     * consults the verify/read gate.
     */
    protected static function canEraseSubject(Entry $record): bool
    {
        return ChronicleFilamentPlugin::get()->isErasureEnabled()
            && ChronicleFilamentPlugin::get()->canErase($record)
            && $record->subject_type !== null
            && $record->subject_id !== null;
    }

    /**
     * The authenticated user reference recorded as the erasure requester, or null
     * when unauthenticated (core then records 'system').
     */
    protected static function eraseRequester(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }

    /**
     * Pretty-print a decrypted accessor value for display. Accessors may return
     * an array (plaintext), a scalar (never-encrypted), or a tombstone string.
     */
    protected static function renderDecrypted(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        // Arrays (plaintext payloads) and any other structured value.
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build the verification badge tooltip from the stored record: last-verified
     * time plus the decoded failure case, or null when the entry is unverified.
     */
    protected static function verificationTooltip(Entry $record): ?string
    {
        $store = app(VerificationResultStore::class);
        $entryRecord = $store->entryRecord($record->id);

        if ($entryRecord === null) {
            return null;
        }

        $when = $entryRecord->last_verified_at?->diffForHumans();
        $failure = VerificationFailure::tryFrom((string) $entryRecord->failure_code)?->name;

        return $failure !== null
            ? "Last checked $when - failure: $failure"
            : "Last verified $when";
    }

    /**
     * The signing-key column tooltip: the algorithm plus the derived state, with
     * a retired-key reassurance. Null for an unsigned entry (no checkpoint).
     * Reads the eager-loaded checkpoint metadata only - never a provider verify.
     */
    protected static function signingKeyTooltip(Entry $record): ?string
    {
        $checkpoint = $record->checkpoint;

        if (! $checkpoint instanceof Checkpoint) {
            return null;
        }

        $state = KeyRingSnapshot::make()->forCheckpoint($checkpoint);

        return $state === SigningKeyState::Retired
            ? $checkpoint->algorithm.' - retired key (still verifies historical entries)'
            : $checkpoint->algorithm.' - '.$state->label();
    }

    /**
     * The erasure column tooltip: the wrapping KEK and erased-at for an
     * encrypted/erased subject, plus a legal-hold note. Null for a never-encrypted
     * subject. Reads the primed SubjectErasureStore only - no DEK unwrap.
     */
    protected static function erasureTooltip(Entry $record): ?string
    {
        $store = app(SubjectErasureStore::class);
        $state = $store->stateFor($record);

        if ($state === ErasureState::NotEncrypted) {
            return $store->isHeld($record) ? 'On legal hold' : null;
        }

        $parts = [];
        $kekId = $store->kekIdFor($record);

        if ($kekId !== null) {
            $parts[] = "KEK: $kekId";
        }

        $erasedAt = $store->erasedAtFor($record);

        if ($erasedAt !== null) {
            $parts[] = 'Erased '.$erasedAt->diffForHumans();
        }

        if ($store->isHeld($record)) {
            $parts[] = 'On legal hold';
        }

        return $parts === [] ? null : implode(' - ', $parts);
    }

    /**
     * Read a string key off a subject.erased proof entry's plain metadata (the
     * erasure requester/reason), defaulting to '-'. The proof's metadata is the
     * audit record of the erasure, not the erased subject's PII - it is never
     * encrypted, so this reads the cast array directly.
     */
    protected static function erasureMetadata(Entry $record, string $key): string
    {
        $value = $record->metadata[$key] ?? null;

        return is_scalar($value) ? (string) $value : '-';
    }

    /**
     * Signing-key filter options keyed by the ring label "{algorithm}:{keyId}",
     * built from KeyRingSnapshot::keys() (i.e. core's KeyRing::all()). The active
     * key is suffixed "(active)". Reads provider metadata only - no verify.
     *
     * @return array<string, string>
     */
    protected static function signingKeyFilterOptions(): array
    {
        $options = [];

        foreach (KeyRingSnapshot::make()->keys() as $label => $key) {
            $options[$label] = $key['active'] ? $label.' (active)' : $label;
        }

        return $options;
    }

    /**
     * The degraded copy for the anchor section, or '' when real anchor rows
     * should render instead. Reads stored state only - never a provider verify.
     */
    protected static function anchorEmptyLabel(Entry $record): string
    {
        if (! ChronicleFilamentPlugin::get()->isAnchoringEnabled()) {
            return 'Anchoring not configured';
        }

        if ($record->checkpoint_id === null) {
            return 'Unanchored';
        }

        $checkpoint = $record->checkpoint;

        if ($checkpoint === null || $checkpoint->anchors->isEmpty()) {
            return 'No anchors';
        }

        return '';
    }
}
