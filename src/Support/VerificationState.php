<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

enum VerificationState: string
{
    case Verified = 'verified';
    case Failed = 'failed';
    case Unverified = 'unverified';
    case Stale = 'stale';

    public function color(): string
    {
        return match ($this) {
            self::Verified => 'success',
            self::Failed => 'danger',
            self::Stale => 'warning',
            self::Unverified => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Verified => 'heroicon-o-check-badge',
            self::Failed => 'heroicon-o-x-circle',
            self::Stale => 'heroicon-o-clock',
            self::Unverified => 'heroicon-o-question-mark-circle',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
