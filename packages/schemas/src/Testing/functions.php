<?php

declare(strict_types=1);

use Inlay\Schemas\Contracts\ProvidesSchema;
use Inlay\Schemas\Schema;
use Inlay\Schemas\Testing\SchemaTester;

if (! function_exists('inlaySchema')) {
    /**
     * Drive a schema, a reusable fragment, or a plain component list through
     * its real context and assert on the resolved tree.
     *
     * @param  Schema|ProvidesSchema|list<mixed>  $schema
     */
    function inlaySchema(Schema|ProvidesSchema|array $schema, string $name = 'schema'): SchemaTester
    {
        return SchemaTester::make($schema, $name);
    }
}
