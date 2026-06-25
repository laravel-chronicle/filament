<?php

declare(strict_types=1);

namespace Chronicle\Filament\Jobs;

use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Verification\AnchorVerifier;
use Chronicle\Verification\VerificationFailure;
use Chronicle\Verification\VerificationResult;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

/**
 * Queued bulk anchor verification for ledgers above the synchronous threshold.
 * Runs core's AnchorVerifier over the in-scope checkpoints (those carrying anchor
 * rows), then notifies the initiating user via a database notification. It reads
 * and notifies only - never a mutation.
 */
class VerifyAnchorsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int|string|null $notifyUserId,
    ) {
        //
    }

    /**
     * Verify every in-scope checkpoint's anchor and notify the initiating user.
     */
    public function handle(): void
    {
        /** @var Collection<int, Checkpoint> $checkpoints */
        $checkpoints = Checkpoint::query()->has('anchors')->get();

        $result = app(AnchorVerifier::class)->verify($checkpoints);

        $this->notify($result);
    }

    /**
     * Send the initiating user a pass/fail database notification, decoding the
     * AnchorInvalid case on failure. No-op when there is no user to notify.
     */
    protected function notify(VerificationResult $result): void
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title($result->isValid() ? 'Anchor verification passed' : 'Anchor verification failed');

        $result->isValid()
            ? $notification->success()
            : $notification->danger()->body('Failure: '.VerificationFailure::AnchorInvalid->name);

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
