<?php

declare(strict_types=1);

namespace Inlay\Forms\Testing;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inlay\Forms\Contracts\HasForms;
use Inlay\Forms\Form;
use Inlay\Support\Testing\Assertions;
use Inlay\Validation\ValidationRunner;

/**
 * Drives a standalone form page through its real lifecycle: the same schema the
 * browser receives, the same validation, and the same submit body.
 */
final class FormPageTester
{
    private HasForms $page;

    private ?string $formName = null;

    private ?FormTester $formTester = null;

    private Request $request;

    private ValidationFactory $validationFactory;

    private ValidationRunner $validationRunner;

    /** @var array<string, list<string>> */
    private array $errors = [];

    private mixed $result = null;

    private bool $submitted = false;

    /** @param class-string<HasForms>|HasForms $page */
    private function __construct(
        HasForms|string $page,
        private mixed $user = null,
        ?ValidationFactory $validationFactory = null,
        ?ValidationRunner $validationRunner = null,
    ) {
        if (is_string($page)) {
            if (! is_subclass_of($page, HasForms::class)) {
                throw new \InvalidArgumentException("Form page tester [{$page}] must implement ".HasForms::class.'.');
            }
            $page = Container::getInstance()->make($page);
        }

        $container = Container::getInstance();
        $this->page = $page;
        $this->validationFactory = $validationFactory ?? $container->make(ValidationFactory::class);
        $this->validationRunner = $validationRunner ?? $container->make(ValidationRunner::class);
        $this->request = Request::create('/', 'GET');
        $this->request->setUserResolver(fn (): mixed => $this->user);
    }

    /** @param class-string<HasForms>|HasForms $page */
    public static function make(
        HasForms|string $page,
        mixed $user = null,
        ?ValidationFactory $validationFactory = null,
        ?ValidationRunner $validationRunner = null,
    ): self {
        return new self($page, $user, $validationFactory, $validationRunner);
    }

    /** Select one of several named forms declared by the page. */
    public function forForm(string $name): self
    {
        $forms = $this->page->resolveForms($this->request);
        if (! array_key_exists($name, $forms)) {
            Assertions::fail("The page does not declare a form named [{$name}]. Declared: ".implode(', ', array_keys($forms)).'.');
        }

        $this->formName = $name;
        $this->formTester = null;

        return $this;
    }

    public function form(): Form
    {
        return $this->tester()->form();
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return $this->tester()->state();
    }

    /** @param array<string, mixed> $state */
    public function fillForm(array $state): self
    {
        $this->tester()->fillForm($state);

        return $this;
    }

    public function assertFormFieldExists(string $name, ?Closure $check = null): self
    {
        $this->tester()->assertFormFieldExists($name, $check);

        return $this;
    }

    public function assertFormFieldDoesNotExist(string $name): self
    {
        $this->tester()->assertFormFieldDoesNotExist($name);

        return $this;
    }

    /** @param array<string, mixed>|Closure $expected */
    public function assertSchemaStateSet(array|Closure $expected): self
    {
        $this->tester()->assertSchemaStateSet($expected);

        return $this;
    }

    /**
     * Submit the filled state through the page's own validation and submit
     * body, capturing validation errors instead of throwing.
     */
    public function call(): self
    {
        $state = $this->tester()->state();
        $request = Request::create('/', 'POST', $state);
        $request->setUserResolver(fn (): mixed => $this->user);
        if ($this->formName !== null) {
            $request->query->set('_inlay_form', $this->formName);
        }

        $this->errors = [];
        $this->result = null;
        $this->submitted = false;

        try {
            $this->result = $this->page->processForm($request, $this->validationRunner, $this->validationFactory);
            $this->submitted = true;
        } catch (ValidationException $exception) {
            $this->errors = $exception->errors();
        }

        return $this;
    }

    /** @param list<string>|array<string, string> $expected */
    public function assertHasFormErrors(array $expected): self
    {
        foreach ($expected as $field => $message) {
            $name = is_int($field) ? $message : $field;
            if (! array_key_exists($name, $this->errors)) {
                Assertions::fail("Expected a validation error for [{$name}], but none was recorded.");
            }
            if (! is_int($field) && ! in_array($message, $this->errors[$name], true)) {
                Assertions::fail("Expected the [{$name}] error to contain [{$message}].");
            }
        }

        Assertions::true(true, 'Expected validation errors were recorded.');

        return $this;
    }

    public function assertHasNoFormErrors(): self
    {
        if ($this->errors !== []) {
            Assertions::fail('Expected no validation errors, but found: '.implode(', ', array_keys($this->errors)).'.');
        }

        Assertions::true(true, 'Expected no validation errors.');

        return $this;
    }

    public function assertSubmitted(): self
    {
        if (! $this->submitted) {
            Assertions::fail('Expected the form to submit, but validation failed with: '.implode(', ', array_keys($this->errors)).'.');
        }

        Assertions::true(true, 'Expected the form to submit.');

        return $this;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    private function tester(): FormTester
    {
        if ($this->formTester === null) {
            $form = $this->formName === null
                ? $this->page->resolveForm($this->request)
                : $this->page->resolveForms($this->request)[$this->formName];
            $this->formTester = FormTester::make($form);
        }

        return $this->formTester;
    }
}
