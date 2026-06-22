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
 * and a label resolver. Each setting falls back to config/chronicle-filament.php.
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
