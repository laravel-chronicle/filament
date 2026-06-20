<?php

declare(strict_types=1);

namespace Chronicle\Filament\Policies;

use Chronicle\Entry\Entry;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Gate-layer enforcement of Chronicle's read-only invariant. Reading is allowed;
 * every mutation ability is denied unconditionally, for any caller (including
 * guests) and any Entry subclass. This is defence in depth behind the resource's
 * own can*() overrides and the model's own immutability.
 */
class EntryPolicy
{
    public function viewAny(?Authenticatable $user = null): bool
    {
        return true;
    }

    public function view(?Authenticatable $user, Entry $entry): bool
    {
        return true;
    }

    public function create(?Authenticatable $user = null): bool
    {
        return false;
    }

    public function update(?Authenticatable $user, Entry $entry): bool
    {
        return false;
    }

    public function delete(?Authenticatable $user, Entry $entry): bool
    {
        return false;
    }

    public function deleteAny(?Authenticatable $user = null): bool
    {
        return false;
    }

    public function restore(?Authenticatable $user, Entry $entry): bool
    {
        return false;
    }

    public function forceDelete(?Authenticatable $user, Entry $entry): bool
    {
        return false;
    }

    public function replicate(?Authenticatable $user, Entry $entry): bool
    {
        return false;
    }
}
