<?php

declare(strict_types=1);

namespace Chronicle\Filament\Tests\Fixtures;

use Chronicle\Entry\Entry;

/**
 * A host-style Entry subclass, used to prove the entry_model override is honored
 * by the resource and the policy.
 */
class CustomEntry extends Entry {}
