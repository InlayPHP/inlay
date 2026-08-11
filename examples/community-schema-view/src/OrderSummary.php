<?php

declare(strict_types=1);

namespace Acme\InlayOrderSummary;

use Closure;
use Inlay\Schemas\Components\View;

final class OrderSummary
{
    public const VIEW = 'acme/order-summary';

    /** @param array<string, mixed>|Closure $data */
    public static function make(array|Closure $data = []): View
    {
        return View::make(self::VIEW)->viewData($data);
    }
}
