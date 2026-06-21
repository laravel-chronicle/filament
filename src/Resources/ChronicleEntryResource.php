<?php

declare(strict_types=1);

namespace Chronicle\Filament\Resources;

use BackedEnum;
use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Chronicle\Filament\Support\PreviousHash;
use Chronicle\Filament\Support\ReferenceLabel;
use Filament\Clusters\Cluster;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
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
use Illuminate\Support\Facades\Config;
use Stringable;
use UnitEnum;

/**
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
                    ->state(fn (Entry $record): string => $record->subject_type === null
                        ? '-'
                        : ReferenceLabel::for($record->subject_type, (string) $record->subject_id)),
                TextColumn::make('verification_status')
                    ->label('Verified')
                    ->badge()
                    // Verification store lands in Session 4; until then every
                    // entry reads as "unverified". Shape is final so Session 4
                    // only swaps the data source.
                    ->state(fn (Entry $record): string => 'unverified')
                    ->color('gray')
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
                    // Stub: options final, but the store-backed query arrives in
                    // Session 4. No-op query keeps the filter inert until then.
                    ->options([
                        'verified' => 'Verified',
                        'failed' => 'Failed',
                        'unverified' => 'Unverified',
                        'stale' => 'Stale',
                    ])
                    ->query(fn (Builder $query): Builder => $query),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('checkpoint');
    }

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
                        ->state(fn (Entry $record): string => $record->subject_type === null
                            ? '-'
                            : ReferenceLabel::for($record->subject_type, (string) $record->subject_id)),
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
                                $value === null => '—',
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
            // Anchor-proof slot is a placeholder until v1.1 (DISCOVERY §5).
        ]);
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
}
