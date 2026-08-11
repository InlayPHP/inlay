<?php

declare(strict_types=1);

use Inlay\Support\Condition;
use Inlay\Support\ClosureEvaluator;
use Inlay\Support\SafeUrl;

it('resolves union intersection and variadic closure dependencies', function (): void {
    $date = new DateTimeImmutable('2026-07-27');
    $collection = new ArrayObject(['schema', 'form']);

    $union = ClosureEvaluator::evaluate(
        fn (Stringable|DateTimeInterface $value, string ...$suffixes): string => $value->format('Y-m-d').':'.implode(',', $suffixes),
        named: ['suffixes' => ['stable', 'typed']],
        typed: [DateTimeImmutable::class => $date],
    );
    $intersection = ClosureEvaluator::evaluate(
        fn (Countable&IteratorAggregate $value): int => count($value),
        typed: [ArrayObject::class => $collection],
    );

    expect($union)->toBe('2026-07-27:stable,typed')
        ->and($intersection)->toBe(2);
});

it('serializes allow-listed conditions', function (): void {
    expect(Condition::make('account_type', 'company')->jsonSerialize())->toBe([
        'path' => 'account_type',
        'operator' => 'equals',
        'value' => 'company',
    ])->and(Condition::truthy('enabled')->jsonSerialize())->toBe([
        'path' => 'enabled',
        'operator' => 'truthy',
        'value' => null,
    ])->and(Condition::make('role', ['admin', 'owner'], 'in')->jsonSerialize()['value'])->toBe(['admin', 'owner']);
});

it('serializes recursively composed conditions without changing leaf payloads', function (): void {
    $condition = Condition::all(
        Condition::make('account.type', 'company'),
        Condition::any(
            Condition::truthy('account.verified'),
            Condition::not(Condition::blank('account.tax_id')),
        ),
    );

    expect(json_decode(json_encode($condition, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'logic' => 'all',
        'conditions' => [
            ['path' => 'account.type', 'operator' => 'equals', 'value' => 'company'],
            [
                'logic' => 'any',
                'conditions' => [
                    ['path' => 'account.verified', 'operator' => 'truthy', 'value' => null],
                    [
                        'logic' => 'not',
                        'conditions' => [
                            ['path' => 'account.tax_id', 'operator' => 'blank', 'value' => null],
                        ],
                    ],
                ],
            ],
        ],
    ])->and($condition->isLeaf())->toBeFalse()
        ->and($condition->logic())->toBe('all')
        ->and($condition->conditions())->toHaveCount(2);
});

it('evaluates serialized conditions with the same wire semantics as React and Vue', function (): void {
    $state = [
        'enabled' => false,
        'roles' => ['admin', 'editor'],
        'profile' => ['name' => '  '],
        'count' => 1,
        'empty_array' => [],
    ];

    expect(Condition::all(
        Condition::falsy('enabled'),
        Condition::make('count', 1.0),
        Condition::make('roles', ['admin', 'editor']),
    )->matches($state))->toBeTrue()
        ->and(Condition::make('roles.0', ['admin', 'owner'], 'in')->matches($state))->toBeTrue()
        ->and(Condition::blank('profile.name')->matches($state))->toBeTrue()
        ->and(Condition::truthy('empty_array')->matches($state))->toBeTrue()
        ->and(Condition::not(Condition::filled('missing'))->matches($state))->toBeTrue();
});

it('rejects empty condition groups', function (): void {
    Condition::all();
})->throws(InvalidArgumentException::class);

it('rejects unsafe condition shapes', function (string $path, mixed $value, string $operator): void {
    Condition::make($path, $value, $operator);
})->with([
    ['', true, 'equals'],
    ['role', 'admin', 'contains-php'],
    ['role', 'admin', 'in'],
])->throws(InvalidArgumentException::class);

it('normalizes safe relative and allow-listed absolute URLs', function (string $url): void {
    $safe = SafeUrl::from("  {$url}  ");

    expect($safe->value())->toBe($url)
        ->and((string) $safe)->toBe($url)
        ->and($safe->jsonSerialize())->toBe($url);
})->with([
    '/admin/users',
    '../users',
    '?tab=users',
    '#users',
    'https://example.com/users',
    'http://example.com/users',
    'mailto:support@example.com',
    'tel:+85212345678',
]);

it('rejects unsafe and unsupported URLs centrally', function (string $url): void {
    SafeUrl::from($url);
})->with([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'ftp://example.com/file',
    '//evil.example/path',
    '\\\\evil.example\\path',
    '/\\evil.example/path',
    '\\/evil.example/path',
    "https://example.com/\nmalicious",
    '',
    '   ',
])->throws(InvalidArgumentException::class);
