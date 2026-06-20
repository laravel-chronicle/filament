<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;

return [

    /*
    |--------------------------------------------------------------------------
    | Entry model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model the resource reads. Defaults to Chronicle's base Entry.
    | Point this at a subclass of Chronicle\Entry\Entry to add accessors or
    | relations. With core >= 1.13 the override is honored end-to-end by core's
    | reader and verifiers when chronicle.models.entry is set to the same class.
    |
    */

    'entry_model' => Entry::class,

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    'navigation' => [
        'group' => 'Chronicle',
        'sort' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource slug
    |--------------------------------------------------------------------------
    */

    'slug' => 'chronicle-entries',

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    |
    | enabled          Master toggle for the verification surface (badges,
    |                  verify actions, health widget). Built in Session 4.
    | queue_threshold  Chain / segment verifies covering more than this many
    |                  entries are dispatched to the queue instead of running
    |                  synchronously.
    | store.connection Database connection for the plugin-owned verification
    |                  result store. null = the application's default connection.
    |
    */

    'verification' => [
        'enabled' => true,
        'queue_threshold' => 1000,
        'store' => [
            'connection' => null,
        ],
    ],

];
