<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Chronicle\Facades\Chronicle;
use Chronicle\Filament\ChronicleFilamentPlugin;

/**
 * Query-free actor/subject display labels. Applies the plugin's
 * ->labelResolver() override first, then delegates to core's
 * Chronicle::resolveReference(), which honours Relation::morphMap() and never
 * touches the database. Hosts that want hydrated labels override via the
 * plugin closure; the default path stays N+1-free.
 */
final class ReferenceLabel
{
    public static function for(string $type, string $id): string
    {
        $override = ChronicleFilamentPlugin::get()->resolveLabel($type, $id);

        if ($override !== null) {
            return $override;
        }

        return Chronicle::resolveReference($type, $id)->label;
    }
}
