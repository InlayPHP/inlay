<?php

declare(strict_types=1);

require_once __DIR__.'/../examples/community-schema-view/src/OrderSummary.php';
require_once __DIR__.'/../examples/community-schema-view/tests/OrderSummaryTest.php';

it('keeps the community schema view package manifests publishable after renaming', function (): void {
    $composer = json_decode(
        (string) file_get_contents(__DIR__.'/../examples/community-schema-view/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $package = json_decode(
        (string) file_get_contents(__DIR__.'/../examples/community-schema-view/package.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer)
        ->name->toBe('acme/inlay-order-summary')
        ->and($composer['require'])
        ->toHaveKeys(['php', 'inlayphp/schemas'])
        ->and($package)
        ->name->toBe('@acme/inlay-order-summary')
        ->and($package['exports'])
        ->toHaveKeys(['./react', './vue']);
});
