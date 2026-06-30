<?php

declare(strict_types=1);

namespace Chronicle\Filament\Widgets;

use Chronicle\Encryption\KeyEncryptionManager;
use Chronicle\Encryption\SubjectKey;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Lifecycle\LegalHold;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Throwable;

/**
 * Stats widget summarising core's crypto-shredding state: how many subjects are
 * encrypted (active key), how many are erased (tombstone), how many are under an
 * active legal hold, and the active KEK id. Built from one grouped aggregate over
 * SubjectKey (by status), one count over active LegalHold rows, and the KEK
 * provider's id - never an unwrap, decrypt, or per-row query. Hidden when the
 * crypto-shredding surfaces are disabled.
 */
class CryptoShreddingWidget extends StatsOverviewWidget
{
    /**
     * Mount only when the crypto-shredding surfaces are enabled.
     */
    public static function canView(): bool
    {
        return ChronicleFilamentPlugin::get()->isCryptoShreddingEnabled();
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $rows = SubjectKey::query()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $status = is_string($row->status) ? $row->status : 'unknown';
            $counts[$status] = is_numeric($row->aggregate) ? (int) $row->aggregate : 0;
        }

        $encrypted = $counts['active'] ?? 0;
        $erased = $counts['erased'] ?? 0;
        $held = LegalHold::query()->whereNull('released_at')->count();

        return [
            Stat::make('Encrypted subjects', (string) $encrypted)
                ->description('Active per-subject keys')
                ->color('success')
                ->icon('heroicon-o-lock-closed'),
            Stat::make('Erased subjects', (string) $erased)
                ->description('Crypto-shredded tombstones')
                ->color($erased > 0 ? 'danger' : 'gray')
                ->icon('heroicon-o-fire'),
            Stat::make('On legal hold', (string) $held)
                ->description('Erasure blocked while held')
                ->color($held > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-scale'),
            Stat::make('Active KEK', $this->activeKekId())
                ->description('Wraps every active subject key')
                ->color('gray')
                ->icon('heroicon-o-key'),
        ];
    }

    /**
     * The active KEK id, or '-' when no usable KEK is configured. provider()
     * throws when the KEK config is absent/invalid (e.g. the visibility toggle is
     * forced on while core encryption is off) - degrade to a placeholder, never an
     * error.
     */
    protected function activeKekId(): string
    {
        try {
            return app(KeyEncryptionManager::class)->provider()->kekId();
        } catch (Throwable) {
            return '-';
        }
    }
}
