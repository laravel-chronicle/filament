<?php

declare(strict_types=1);

namespace Chronicle\Filament\Jobs;

use Chronicle\Exports\ExportManager;
use Chronicle\Exports\ExportResult;
use Chronicle\Filament\Support\ExportArtifact;
use Chronicle\Filament\Support\ExportArtifactStore;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use JsonException;

/**
 * Queued verifiable export. Runs core's ExportManager into a local working
 * directory, zips the signed bundle onto the exports disk via ExportArtifactStore,
 * cleans up the working directory, then notifies the initiating user with the
 * dataset summary. It reads entries and writes artifact files only - never a
 * ledger mutation. Exports are ALWAYS queued.
 */
class ExportLedgerJob implements ShouldQueue
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
     * Export the full dataset to a signed bundle on the exports disk, then notify.
     *
     * @throws JsonException
     */
    public function handle(): void
    {
        $store = app(ExportArtifactStore::class);
        $workingDir = sys_get_temp_dir().'/chronicle-export-'.Str::uuid();

        try {
            $result = app(ExportManager::class)->export($workingDir);
            $artifact = $store->store($workingDir);
        } finally {
            $store->deleteLocalDir($workingDir);
        }

        $this->notify($result, $artifact);
    }

    /**
     * Send the initiating user a database notification carrying the bundle name
     * and the dataset summary. No-op when there is no user to notify.
     */
    protected function notify(ExportResult $result, ExportArtifact $artifact): void
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title('Export ready')
            ->body("Bundle $artifact->name: $result->entryCount entries, dataset hash ".substr($result->datasetHash, 0, 12).'.')
            ->success()
            ->sendToDatabase($user);
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
