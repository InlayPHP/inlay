<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Inlay\Forms\Contracts\HasForms;
use Inlay\Forms\Testing\FormPageTester;
use Inlay\Validation\ValidationRunner;

if (! function_exists('inlayForm')) {
    /**
     * Drive a standalone form page through its real schema, validation, and
     * submit body.
     *
     * @param  class-string<HasForms>|HasForms  $page
     */
    function inlayForm(
        HasForms|string $page,
        mixed $user = null,
        ?ValidationFactory $validationFactory = null,
        ?ValidationRunner $validationRunner = null,
    ): FormPageTester {
        return FormPageTester::make($page, $user, $validationFactory, $validationRunner);
    }
}
