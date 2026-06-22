<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * @property int $id
 * @property string $scope
 * @property string $subject_key
 * @property string $state
 * @property string|null $failure_code
 * @property string|null $failed_entry_id
 * @property int $checked_count
 * @property int $verified_through
 * @property CarbonImmutable|null $last_verified_at
 */
class VerificationRecord extends Model
{
    protected $table = 'chronicle_filament_verification_records';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return string[]
     */
    protected function casts(): array
    {
        return [
            'checked_count' => 'integer',
            'verified_through' => 'integer',
            'last_verified_at' => 'immutable_datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        $connection = Config::get('chronicle-filament.verification.store.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }
}
