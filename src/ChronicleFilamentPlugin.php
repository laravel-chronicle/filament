<?php

declare(strict_types=1);

namespace Chronicle\Filament;

use Chronicle\Filament\Resources\ChronicleEntryResource;
use Closure;
use Filament\Clusters\Cluster;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use UnitEnum;

/**
 * The Filament plugin for Chronicle: registers the read-only entry resource on a
 * panel and exposes a fluent API for host configuration - navigation placement,
 * slug, cluster, verification toggle, the authorize() gate for verify actions,
 * a label resolver, and the v1.4 export/report toggles and canExport() gate.
 * Each setting falls back to config/chronicle-filament.php.
 */
final class ChronicleFilamentPlugin implements Plugin
{
    protected string|UnitEnum|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected ?string $slug = null;

    /**
     * @var class-string<Cluster>|null
     */
    protected ?string $cluster = null;

    protected ?bool $verification = null;

    protected ?bool $anchoring = null;

    protected ?bool $signingKeys = null;

    protected ?bool $cryptoShredding = null;

    protected ?bool $erasure = null;

    protected ?bool $eraseAllowHoldOverride = null;

    protected ?Closure $eraseAuthorizeUsing = null;

    protected ?bool $exports = null;

    protected ?bool $reporting = null;

    protected ?Closure $exportAuthorizeUsing = null;

    protected ?Closure $authorizeUsing = null;

    protected ?Closure $labelResolver = null;

    /**
     * Resolve the shared plugin instance for registering on a panel.
     */
    public static function make(): ChronicleFilamentPlugin
    {
        return app(ChronicleFilamentPlugin::class);
    }

    /**
     * Resolve the active plugin instance, preferring the one attached to the
     * panel handling the current request and falling back to the shared
     * container instance during boot and route registration.
     */
    public static function get(): ChronicleFilamentPlugin
    {
        // Prefer the instance attached to the panel handling the current
        // request; fall back to the shared container instance during boot
        // and route registration, when no panel is "current" yet.
        $panel = Filament::getCurrentPanel();

        if ($panel?->hasPlugin('chronicle-filament')) {
            /** @var static $plugin */
            $plugin = $panel->getPlugin('chronicle-filament');

            return $plugin;
        }

        return app(ChronicleFilamentPlugin::class);
    }

    public function getId(): string
    {
        return 'chronicle-filament';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            ChronicleEntryResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public function navigationGroup(string|UnitEnum|null $group): ChronicleFilamentPlugin
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): ChronicleFilamentPlugin
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function slug(string $slug): ChronicleFilamentPlugin
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * @param  class-string<Cluster>|null  $cluster
     */
    public function cluster(?string $cluster): ChronicleFilamentPlugin
    {
        $this->cluster = $cluster;

        return $this;
    }

    public function verification(bool $condition = true): ChronicleFilamentPlugin
    {
        $this->verification = $condition;

        return $this;
    }

    public function anchoring(bool $condition = true): ChronicleFilamentPlugin
    {
        $this->anchoring = $condition;

        return $this;
    }

    public function signingKeys(bool $condition = true): ChronicleFilamentPlugin
    {
        $this->signingKeys = $condition;

        return $this;
    }

    public function cryptoShredding(bool $condition = true): ChronicleFilamentPlugin
    {
        $this->cryptoShredding = $condition;

        return $this;
    }

    public function erasure(bool $condition = true): ChronicleFilamentPlugin
    {
        $this->erasure = $condition;

        return $this;
    }

    public function eraseAllowHoldOverride(bool $condition = true): ChronicleFilamentPlugin
    {
        $this->eraseAllowHoldOverride = $condition;

        return $this;
    }

    public function eraseAuthorize(Closure $callback): ChronicleFilamentPlugin
    {
        $this->eraseAuthorizeUsing = $callback;

        return $this;
    }

    public function exports(bool $condition = true): ChronicleFilamentPlugin
    {
        $this->exports = $condition;

        return $this;
    }

    public function reporting(bool $condition = true): ChronicleFilamentPlugin
    {
        $this->reporting = $condition;

        return $this;
    }

    public function authorize(Closure $callback): ChronicleFilamentPlugin
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function labelResolver(Closure $callback): ChronicleFilamentPlugin
    {
        $this->labelResolver = $callback;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        if ($this->navigationGroup !== null) {
            return $this->navigationGroup;
        }

        $group = Config::string('chronicle-filament.navigation.group', 'Chronicle');

        return $group !== '' ? $group : null;
    }

    public function getNavigationSort(): ?int
    {
        if ($this->navigationSort !== null) {
            return $this->navigationSort;
        }

        $sort = Config::get('chronicle-filament.navigation.sort');

        return is_int($sort) ? $sort : null;
    }

    public function getSlug(): string
    {
        return $this->slug ?? Config::string('chronicle-filament.slug', 'chronicle-entries');
    }

    /**
     * @return class-string<Cluster>|null
     */
    public function getCluster(): ?string
    {
        return $this->cluster;
    }

    public function isVerificationEnabled(): bool
    {
        return $this->verification ?? Config::boolean('chronicle-filament.verification.enabled', true);
    }

    /**
     * Whether the anchor surfaces are enabled. Fluent override wins; otherwise
     * the plugin's anchoring.enabled config when set to a bool; otherwise follow
     * core's chronicle.anchoring.enabled (default false). Everything stays hidden
     * when core anchoring is off.
     */
    public function isAnchoringEnabled(): bool
    {
        if ($this->anchoring !== null) {
            return $this->anchoring;
        }

        $configured = Config::get('chronicle-filament.anchoring.enabled');

        if (is_bool($configured)) {
            return $configured;
        }

        return Config::boolean('chronicle.anchoring.enabled', false);
    }

    /**
     * Whether the signing-key surfaces (column, filter, detail badge, widget)
     * are enabled. Fluent override wins; otherwise the plugin's
     * signing_keys.enabled config (default true). Display-only - no verification.
     */
    public function isSigningKeysEnabled(): bool
    {
        return $this->signingKeys ?? Config::boolean('chronicle-filament.signing_keys.enabled', true);
    }

    /**
     * Whether the read-only crypto-shredding surfaces are enabled. Fluent
     * override wins; otherwise the plugin's crypto_shredding.enabled config when
     * set to a bool; otherwise follow core's chronicle.encryption.enabled
     * (default false). Everything stays hidden when core encryption is off.
     */
    public function isCryptoShreddingEnabled(): bool
    {
        if ($this->cryptoShredding !== null) {
            return $this->cryptoShredding;
        }

        $configured = Config::get('chronicle-filament.crypto_shredding.enabled');

        if (is_bool($configured)) {
            return $configured;
        }

        return Config::boolean('chronicle.encryption.enabled', false);
    }

    /**
     * Whether the irreversible Erase-subject action is enabled. OFF BY DEFAULT
     * and independent of the visibility toggle. Fluent override wins; otherwise
     * the plugin's erasure.enabled config (default false).
     */
    public function isErasureEnabled(): bool
    {
        return $this->erasure ?? Config::boolean('chronicle-filament.erasure.enabled', false);
    }

    /**
     * Whether an erase may override an active legal hold. OFF BY DEFAULT. Fluent
     * override wins; otherwise erasure.allow_hold_override config (default false).
     */
    public function isEraseHoldOverrideAllowed(): bool
    {
        return $this->eraseAllowHoldOverride ?? Config::boolean('chronicle-filament.erasure.allow_hold_override', false);
    }

    /**
     * Whether the verifiable-export surfaces (export + verify-export, wired in
     * E2) are enabled. Fluent override wins; otherwise the plugin's
     * exports.enabled config (default true). Read-only w.r.t. the ledger.
     */
    public function isExportsEnabled(): bool
    {
        return $this->exports ?? Config::boolean('chronicle-filament.exports.enabled', true);
    }

    /**
     * Whether the signed compliance-report surface (wired in E3) is enabled.
     * Fluent override wins; otherwise the plugin's reporting.enabled config
     * (default true). Read-only w.r.t. the ledger.
     */
    public function isReportingEnabled(): bool
    {
        return $this->reporting ?? Config::boolean('chronicle-filament.reporting.enabled', true);
    }

    /**
     * Whether the current user may run the Erase-subject action. SEPARATE from
     * canVerify() and DENY BY DEFAULT: with no eraseAuthorize() closure set the
     * action can never run. Never the verify/read gate.
     */
    public function canErase(?Model $record = null): bool
    {
        if ($this->eraseAuthorizeUsing === null) {
            return false;
        }

        return (bool) ($this->eraseAuthorizeUsing)($record);
    }

    public function getVerifyAllQueueThreshold(): int
    {
        return Config::integer('chronicle-filament.anchoring.verify_all_queue_threshold', 1000);
    }

    public function getLabelResolver(): ?Closure
    {
        return $this->labelResolver;
    }

    /**
     * Whether the current user may run the verify actions. Defaults to allowed
     * when no authorize() closure is configured; otherwise evaluates the closure.
     */
    public function canVerify(?Model $record = null): bool
    {
        if ($this->authorizeUsing === null) {
            return true;
        }

        return (bool) ($this->authorizeUsing)($record);
    }

    /**
     * Apply the host's labelResolver() override for a reference, returning null
     * when no resolver is set, or it yields no usable label.
     */
    public function resolveLabel(string $type, string $id): ?string
    {
        if ($this->labelResolver === null) {
            return null;
        }

        $label = ($this->labelResolver)($type, $id);

        return is_string($label) && $label !== '' ? $label : null;
    }
}
