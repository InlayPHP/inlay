<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Gate;
use Illuminate\Container\Container;
use Inlay\Authorization\AbilityDefinition;
use Inlay\Authorization\AbilityRegistry;
use Inlay\Authorization\AuthorizationManager;

it('registers deterministic, owned ability definitions for plugins and resources', function (): void {
    $registry = (new AbilityRegistry)
        ->register(
            AbilityDefinition::make('users.viewAny')
                ->group('User management')
                ->description('List users'),
            'inlay.users',
        )
        ->register(
            AbilityDefinition::make('users.delete')->dangerous(),
            'inlay.users',
        );

    expect(array_keys($registry->all()))->toBe(['users.delete', 'users.viewAny'])
        ->and($registry->owner('users.delete'))->toBe('inlay.users')
        ->and($registry->get('users.delete')->jsonSerialize())->toMatchArray([
            'name' => 'users.delete',
            'label' => 'Delete',
            'group' => 'Users',
            'dangerous' => true,
        ]);
});

it('delegates every decision and denial explanation to Laravel Gate', function (): void {
    $container = new Container;
    $gate = new Gate($container, fn (): null => null);
    $gate->define('reports.view', fn (object $user): bool => $user->allowed);
    $manager = new AuthorizationManager($gate, new AbilityRegistry);
    $allowed = (object) ['allowed' => true];
    $denied = (object) ['allowed' => false];

    expect($manager->allows($allowed, 'reports.view'))->toBeTrue()
        ->and($manager->allows($denied, 'reports.view'))->toBeFalse()
        ->and($manager->allows(null, 'reports.view'))->toBeFalse()
        ->and($manager->inspect($denied, 'reports.view')->denied())->toBeTrue()
        ->and($manager->inspect(null, 'reports.view')->message())->toBe('Authentication is required.');
});

it('rejects duplicate ability ownership and malformed ability names', function (): void {
    $registry = new AbilityRegistry;
    $registry->register(AbilityDefinition::make('users.view'), 'first');
    $registry->register(AbilityDefinition::make('users.view'), 'second');
})->throws(InvalidArgumentException::class);

it('rejects ability names without a stable namespace', function (): void {
    AbilityDefinition::make('view-users');
})->throws(InvalidArgumentException::class);
