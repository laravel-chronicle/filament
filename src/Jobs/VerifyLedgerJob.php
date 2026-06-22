<?php

declare(strict_types=1);

namespace Chronicle\Filament\Jobs;

use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Verification\IntegrityVerifier;
use Chronicle\Verification\VerificationFailure;
use Chronicle\Verification\VerificationResult;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use JsonException;

class VerifyLedgerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $mode,           // chain | segment
        public readonly ?int $fromSequence,
        public readonly ?int $toSequence,
        public readonly ?int $notifyUserId,
        public readonly string $chainKey = 'default',
    ) {
        //
    }

    /**
     * @throws JsonException
     */
    public function handle(): void
    {
        $verifier = app(IntegrityVerifier::class);

        $result = $this->mode === 'segment'
            ? $verifier->verifyEntryRange((int) $this->fromSequence, (int) $this->toSequence)
            : $verifier->verify();

        app(VerificationResultStore::class)->recordChain($result, $this->chainKey);

        $this->notify($result);
    }

    protected function notify(VerificationResult $result): void
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title($result->isValid()
                ? ucfirst($this->mode).' verification passed'
                : ucfirst($this->mode).' verification failed');

        $result->isValid()
            ? $notification->success()
            : $notification->danger()->body('Failure: '.(VerificationFailure::tryFrom((string) $result->failureType())?->name ?? 'unknown'));

        $notification->sendToDatabase($user);
    }

    protected function resolveUser(): ?Authenticatable
    {
        if ($this->notifyUserId === null) {
            return null;
        }

        return Auth::getProvider()?->retrieveById($this->notifyUserId);
    }
}
