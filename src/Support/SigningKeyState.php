<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

/**
 * The signing-key state of a checkpoint or entry - the single source of truth
 * for the signing-key badge color, icon, and label across the table
 * column/filter, detail badge, and key-ring widget.
 *
 * State is derived from the checkpoint's stored `(algorithm, key_id)` compared
 * to the active key in core's KeyRing; it never runs a provider sign/verify.
 * An entry with no checkpoint is `Unsigned`, never an error.
 */
enum SigningKeyState: string
{
    case Active = 'active';
    case Retired = 'retired';
    case Unsigned = 'unsigned';

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Retired => 'warning',
            self::Unsigned => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Active => 'heroicon-o-key',
            self::Retired => 'heroicon-o-archive-box',
            self::Unsigned => 'heroicon-o-no-symbol',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
