<?php

declare(strict_types=1);

return [
    'component' => 'inlay-media-manager/index',
    'per_page' => 24,
    'max_per_page' => 100,
    'signed_url_minutes' => 10,
    'include_references' => true,
    'max_references_per_asset' => 50,
    // Only these disks are exposed by the built-in filesystem browser. An
    // empty list falls back to the configured media disk.
    'storage_disks' => [],
    'storage_browser_limit' => 100,
];
