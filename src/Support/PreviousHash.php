<?php

declare(strict_types=1);

namespace Chronicle\Filament\Support;

use Chronicle\Entry\Entry;
use Chronicle\Hashing\ChainHasher;

/**
 * The "previous hash" for an entry is the chain_hash of the entry one sequence
 * earlier; there is no previous_hash column. The genesis predecessor is
 * ChainHasher::GENESIS ('0'), matching core's EntryVerifier.
 */
final class PreviousHash
{
    public static function for(Entry $entry): string
    {
        $previous = $entry->newQuery()
            ->where('sequence', $entry->sequence - 1)
            ->first();

        return $previous->chain_hash ?? ChainHasher::GENESIS;
    }
}
