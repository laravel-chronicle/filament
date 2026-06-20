<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Policies\EntryPolicy;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;

it('denies every mutation ability regardless of caller', function () {
    $entry = new Entry;

    $callers = [null, new User, tap(new User, fn (User $u) => $u->forceFill(['id' => 99]))];

    foreach ($callers as $caller) {
        expect(Gate::forUser($caller)->denies('create', Entry::class))->toBeTrue()
            ->and(Gate::forUser($caller)->denies('update', $entry))->toBeTrue()
            ->and(Gate::forUser($caller)->denies('delete', $entry))->toBeTrue()
            ->and(Gate::forUser($caller)->denies('deleteAny', Entry::class))->toBeTrue()
            ->and(Gate::forUser($caller)->denies('restore', $entry))->toBeTrue()
            ->and(Gate::forUser($caller)->denies('forceDelete', $entry))->toBeTrue()
            ->and(Gate::forUser($caller)->denies('replicate', $entry))->toBeTrue();
    }
});

it('allows reading', function () {
    $entry = new Entry;

    expect(Gate::forUser(new User)->allows('viewAny', Entry::class))->toBeTrue()
        ->and(Gate::forUser(new User)->allows('view', $entry))->toBeTrue();
});

it('is the policy registered for the resolved entry model', function () {
    expect(Gate::getPolicyFor(Entry::class))->toBeInstanceOf(EntryPolicy::class);
});
