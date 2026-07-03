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

    /*
    |--------------------------------------------------------------------------
    | External anchoring (v1.1)
    |--------------------------------------------------------------------------
    |
    | enabled                     Master toggle for the anchor surfaces (detail
    |                             section, Verify-anchor action, column/filter,
    |                             coverage widget - wired in A2/A3). null follows
    |                             core's chronicle.anchoring.enabled; set true or
    |                             false to force. Everything stays hidden when
    |                             core anchoring is off.
    | verify_all_queue_threshold  The coverage widget's optional "Verify all
    |                             anchors" action dispatches to the queue when it
    |                             would cover more than this many checkpoints.
    |
    */

    'anchoring' => [
        'enabled' => null,
        'verify_all_queue_threshold' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signing-key visibility (v1.2)
    |--------------------------------------------------------------------------
    |
    | enabled  Master toggle for the signing-key surfaces (the "Signing key"
    |          column + filter, the ViewEntry Active/Retired badge, and the
    |          key-ring widget - wired in K2/K3). Display-only: signature
    |          verification stays inside core's chain/entry verifiers; this
    |          surface only shows which key signed each entry.
    |
    */

    'signing_keys' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Crypto-shredding visibility (v1.3)
    |--------------------------------------------------------------------------
    |
    | enabled  Master toggle for the read-only crypto-shredding surfaces (the
    |          erasure column/filter, detail, hold view, and widget - wired in
    |          S2). null follows core's chronicle.encryption.enabled; set true or
    |          false to force. Everything stays hidden when core encryption is off.
    |
    */

    'crypto_shredding' => [
        'enabled' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Subject erasure (GDPR Article 17) (v1.3)
    |--------------------------------------------------------------------------
    |
    | enabled              Master toggle for the irreversible Erase-subject action
    |                      (wired in S3). OFF BY DEFAULT and independent of the
    |                      visibility toggle - the action is absent and non-routable
    |                      unless this is true.
    | allow_hold_override  Whether an operator may erase a subject under an active
    |                      legal hold (with a distinct confirmation). OFF BY DEFAULT.
    |
    | The action is ALSO gated on ChronicleFilamentPlugin::eraseAuthorize(), which
    | DEFAULTS TO DENY - so enabling here alone never makes erasure reachable.
    |
    */

    'erasure' => [
        'enabled' => false,
        'allow_hold_override' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verifiable export (v1.4)
    |--------------------------------------------------------------------------
    |
    | enabled          Master toggle for the export + verify-export surfaces
    |                  (wired in E2). Default true.
    | disk             The storage disk artifact bundles are written to and read
    |                  from. null follows the application's default filesystem
    |                  disk (config('filesystems.default')).
    | path             The directory prefix under the disk for export bundles.
    | queue_threshold  Compliance reports covering more than this many entries are
    |                  dispatched to the queue instead of running synchronously.
    |                  (Exports are ALWAYS queued regardless of this value.)
    |
    | An export egresses the WHOLE dataset, so the export/report actions are gated
    | on ChronicleFilamentPlugin::canExport(), which DEFAULTS TO THE VERIFY GATE
    | (canVerify) and is never wider.
    |
    */

    'exports' => [
        'enabled' => true,
        'disk' => null,
        'path' => 'chronicle-exports',
        'queue_threshold' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance reporting (v1.4)
    |--------------------------------------------------------------------------
    |
    | enabled  Master toggle for the signed compliance-report surface (wired in
    |          E3). Default true. Reports are period-filtered and separately
    |          signed by core; gated on canExport().
    |
    */

    'reporting' => [
        'enabled' => true,
    ],

];
