<?php

declare(strict_types=1);

namespace Chronicle\Filament;

use Chronicle\Filament\Resources\ChronicleEntryResource;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use UnitEnum;

final class ChronicleFilamentPlugin implements Plugin
{
    protected string | UnitEnum | null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected ?string $slug = null;

    protected ?string $cluster = null;

    protected ?bool $verification = null;

    protected ?Closure $authorizeUsing = null;

    protected ?Closure $labelResolver = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = Filament::getPlugin('chronicle-filament');

        return $plugin;
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

    public function navigationGroup(string | UnitEnum | null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function slug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function cluster(?string $cluster): static
    {
        $this->cluster = $cluster;

        return $this;
    }

    public function verification(bool $condition = true): static
    {
        $this->verification = $condition;

        return $this;
    }

    public function authorize(Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function labelResolver(Closure $callback): static
    {
        $this->labelResolver = $callback;

        return $this;
    }

    public function getNavigationGroup(): string | UnitEnum | null
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

    public function canVerify(?Model $record = null): bool
    {
        if ($this->authorizeUsing === null) {
            return true;
        }

        return (bool) ($this->authorizeUsing)($record);
    }
}
