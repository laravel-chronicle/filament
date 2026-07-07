<?php

declare(strict_types=1);

namespace Chronicle\Filament\Jobs;

use Chronicle\Filament\Support\ComplianceReportArtifact;
use Chronicle\Filament\Support\ComplianceReportStore;
use Chronicle\Reports\ComplianceReport;
use Chronicle\Reports\ComplianceReportResult;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use JsonException;

/**
 * Queued signed compliance report. Runs core's ComplianceReport::generate() for
 * the given period into a temp file, stores the signed report bundle on the
 * exports disk via ComplianceReportStore, then notifies the initiating user with
 * the entry count and covered period. Reads entries and writes artifact files
 * only - never a ledger mutation. Dispatched only above exports.queue_threshold.
 */
class ComplianceReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ?string $fromIso,
        public readonly ?string $toIso,
        public readonly int|string|null $notifyUserId,
    ) {
        //
    }

    /**
     * Generate the signed report for the stored period, store the bundle, then notify.
     *
     * @throws JsonException
     */
    public function handle(): void
    {
        $from = $this->fromIso !== null ? Carbon::parse($this->fromIso) : null;
        $to = $this->toIso !== null ? Carbon::parse($this->toIso) : null;

        $tmp = (string) tempnam(sys_get_temp_dir(), 'chronicle-report-');

        try {
            $result = app(ComplianceReport::class)->generate($tmp, $from, $to);
            $artifact = app(ComplianceReportStore::class)->store($result);
        } finally {
            @unlink($tmp);
        }

        $this->notify($result, $artifact);
    }

    /**
     * Send the initiating user a database notification carrying the report bundle
     * name, entry count, and covered period. No-op when there is no user to notify.
     */
    protected function notify(ComplianceReportResult $result, ComplianceReportArtifact $artifact): void
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return;
        }

        $period = $result->from !== null || $result->to !== null
            ? ($result->from?->toDateString() ?? '∞').' – '.($result->to?->toDateString() ?? '∞')
            : 'all entries';

        Notification::make()
            ->title('Report ready')
            ->body("Report $artifact->name: $result->entryCount entries for $period.")
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
