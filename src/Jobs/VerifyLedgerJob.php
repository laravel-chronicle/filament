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

/**
 * Queued verification of a chain or a segment for ledgers above the synchronous
 * threshold. Runs core's IntegrityVerifier, records the outcome to the result
 * store, and notifies the initiating user via a database notification.
 */
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
        public readonly int|string|null $notifyUserId,
        public readonly string $chainKey = 'default',
    ) {
        //
    }

    /**
     * Run the verifier for the job's mode (chain or segment), record the outcome
     * to the result store, and notify the initiating user.
     *
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

    /**
     * Send the initiating user a pass/fail database notification, decoding the
     * failure case on failure. No-op when there is no user to notify.
     */
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
            : $notification->danger()->body('Failure: '.(VerificationFailure::tryFrom((string) $result->failureType())->name ?? 'unknown'));

        $notification->sendToDatabase($user);
    }

    /**
     * Resolve the user to notify from the stored id, or null when none was set.
     */
    protected function resolveUser(): ?Authenticatable
    {
        if ($this->notifyUserId === null) {
            return null;
        }

        return Auth::getProvider()->retrieveById($this->notifyUserId);
    }
}
