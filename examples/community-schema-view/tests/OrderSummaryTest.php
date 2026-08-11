<?php

declare(strict_types=1);

use Acme\InlayOrderSummary\OrderSummary;
use Inlay\Schemas\Components\Text;

it('publishes a stable wire-safe community schema view contract', function (): void {
    $payload = json_decode(json_encode(
        OrderSummary::make(['number' => 'INV-42', 'total' => '$129.50'])
            ->schema([Text::make('Payment captured')]),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->type->toBe('view')
        ->rendererCategory->toBe('schema')
        ->view->toBe(OrderSummary::VIEW)
        ->data->toBe(['number' => 'INV-42', 'total' => '$129.50'])
        ->and($payload['schema'][0]['content'])->toBe('Payment captured');
});
