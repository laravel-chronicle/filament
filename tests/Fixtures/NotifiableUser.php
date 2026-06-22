<?php

declare(strict_types=1);

namespace Chronicle\Filament\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Minimal authenticatable, notifiable user used to prove VerifyLedgerJob resolves
 * the initiating user and delivers a database notification on completion.
 */
class NotifiableUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
