<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

/**
 * The crypto-shredding state of an entry's subject - the single source of truth
 * for the erasure badge color, icon, and label across the (S2) table
 * column/filter, detail, and widget.
 *
 * Derived from core's per-subject SubjectKey row (status only): an active key is
 * Encrypted, an erased tombstone is Erased, no row is NotEncrypted. It never
 * unwraps a DEK, decrypts, or erases. Legal hold is a SEPARATE flag carried by
 * SubjectErasureStore, not a case here.
 */
enum ErasureState: string
{
    case Encrypted = 'encrypted';
    case Erased = 'erased';
    case NotEncrypted = 'not_encrypted';

    public function color(): string
    {
        return match ($this) {
            self::Encrypted => 'success',
            self::Erased => 'danger',
            self::NotEncrypted => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Encrypted => 'heroicon-o-lock-closed',
            self::Erased => 'heroicon-o-fire',
            self::NotEncrypted => 'heroicon-o-lock-open',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Encrypted => 'Encrypted',
            self::Erased => 'Erased',
            self::NotEncrypted => 'Not encrypted',
        };
    }
}
