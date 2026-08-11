<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use Inlay\Validation\Console\MakeValidationCommand;
use Inlay\Validation\Concerns\UsesValidation;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationRunner;
use Inlay\Validation\ValidationServiceProvider;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function makeValidationRunner(?Container $container = null): ValidationRunner
{
    $translator = new Translator(new ArrayLoader, 'en');

    return new ValidationRunner(new Factory($translator), $container);
}

it('exposes a domain-neutral abstract validation base class', function (): void {
    $validation = new ReflectionClass(Validation::class);

    expect($validation->isAbstract())->toBeTrue()
        ->and($validation->getMethod('rules')->isAbstract())->toBeTrue()
        ->and($validation->getMethod('prepare')->isAbstract())->toBeFalse()
        ->and($validation->getMethod('messages')->isAbstract())->toBeFalse()
        ->and($validation->getMethod('attributes')->isAbstract())->toBeFalse()
        ->and($validation->getMethod('after')->isAbstract())->toBeFalse();
});

it('registers the validation runner as the package execution service', function (): void {
    $app = new Application(dirname(__DIR__));
    $app->instance(\Illuminate\Contracts\Validation\Factory::class, new Factory(new Translator(new ArrayLoader, 'en')));
    $provider = new ValidationServiceProvider($app);
    $provider->register();

    expect($app->make(ValidationRunner::class))->toBeInstanceOf(ValidationRunner::class)
        ->and($app->make(ValidationRunner::class))->toBe($app->make(ValidationRunner::class))
        ->and(class_exists('Inlay\\Validation\\Validation'.'Profile'))->toBeFalse();
});

it('declares the Laravel contracts used directly by the validation runner', function (): void {
    $manifest = json_decode(
        (string) file_get_contents(__DIR__.'/../packages/validation/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['require'])->toHaveKey('illuminate/contracts', '^12.0');
});

it('generates application-owned validation classes without overwriting by default', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-validation-generator-'.bin2hex(random_bytes(6));
    $appPath = $root.'/app';

    try {
        $files->ensureDirectoryExists($appPath);
        $files->put($root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        $app = new Application($root);
        $app->useAppPath($appPath);
        $command = new MakeValidationCommand($files);
        $command->setLaravel($app);
        $console = new ConsoleApplication;
        $console->setAutoExit(false);
        $console->add($command);
        $output = new BufferedOutput;

        $status = $console->run(new ArrayInput([
            'command' => 'make:inlay-validation',
            'name' => 'Domain/Record',
        ]), $output);
        $path = $appPath.'/Validation/Domain/RecordRules.php';

        expect($status)->toBe(0)
            ->and($files->exists($path))->toBeTrue()
            ->and($files->get($path))->toContain('namespace App\\Validation\\Domain;')
            ->and($files->get($path))->toContain('final class RecordRules extends Validation')
            ->and($files->get($path))->toContain('public function rules(ValidationContext $context): array');

        $files->append($path, "\n// keep me\n");
        $secondStatus = $console->run(new ArrayInput([
            'command' => 'make:inlay-validation',
            'name' => 'Domain/Record',
        ]), new BufferedOutput);
        expect($secondStatus)->toBe(1)
            ->and($files->get($path))->toContain('// keep me');

        $forcedStatus = $console->run(new ArrayInput([
            'command' => 'make:inlay-validation',
            'name' => 'Domain/RecordRules',
            '--force' => true,
        ]), new BufferedOutput);
        expect($forcedStatus)->toBe(0)
            ->and($files->get($path))->not->toContain('// keep me');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('carries immutable validation context between consumers', function (): void {
    $record = (object) ['id' => 42];
    $user = (object) ['id' => 7];

    $context = ValidationContext::make(
        operation: 'upsert',
        source: ValidationContext::SOURCE_IMPORT,
        record: $record,
        user: $user,
        options: ['mapping' => ['email' => 'Email Address']],
    );
    $withData = $context->withData(['company' => ['name' => 'Acme']]);

    expect($context->data())->toBe([])
        ->and($withData->input('company.name'))->toBe('Acme')
        ->and($withData->input('missing', 'fallback'))->toBe('fallback')
        ->and($withData->option('mapping.email'))->toBe('Email Address')
        ->and($withData->record())->toBe($record)
        ->and($withData->user())->toBe($user)
        ->and($withData->isOperation('create', 'upsert'))->toBeTrue()
        ->and($withData->isSource(ValidationContext::SOURCE_IMPORT))->toBeTrue();
});

it('prepares data before resolving contextual Laravel rules', function (): void {
    $validation = new class extends Validation
    {
        public function prepare(array $data, ValidationContext $context): array
        {
            $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));

            return $data;
        }

        public function rules(ValidationContext $context): array
        {
            return [
                'email' => ['required', 'email'],
                'password' => [$context->isOperation('create') ? 'required' : 'nullable', 'string', 'min:8'],
                'normalized' => [$context->input('email') === 'user@example.com' ? 'required' : 'nullable'],
            ];
        }

        public function messages(ValidationContext $context): array
        {
            return ['email.email' => 'Enter a real :attribute.'];
        }

        public function attributes(ValidationContext $context): array
        {
            return ['email' => 'email address'];
        }
    };

    $validator = makeValidationRunner();
    $context = ValidationContext::make('create');

    $validated = $validator->validate($validation, [
        'email' => ' USER@EXAMPLE.COM ',
        'password' => 'password123',
        'normalized' => true,
    ], $context);

    expect($validated['email'])->toBe('user@example.com')
        ->and($validator->make($validation, ['email' => 'wrong'], $context)->errors()->toArray())
        ->toMatchArray([
            'email' => ['Enter a real email address.'],
        ]);
});

it('runs validation after hooks against the prepared validator data', function (): void {
    $validation = new class extends Validation
    {
        public function rules(ValidationContext $context): array
        {
            return ['start' => ['required', 'integer'], 'end' => ['required', 'integer']];
        }

        public function after(ValidationContext $context): array
        {
            return [
                static function (Validator $validator): void {
                    if ($validator->getValue('end') <= $validator->getValue('start')) {
                        $validator->errors()->add('end', 'The end must be after the start.');
                    }
                },
            ];
        }
    };

    $validator = makeValidationRunner()->make($validation, ['start' => 10, 'end' => 5]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('end'))->toBe('The end must be after the start.');
});

it('resolves validation class names through the Laravel container', function (): void {
    $container = new Container;
    $container->bind(ValidationWithConstructorDependency::class);

    $validated = makeValidationRunner($container)->validate(
        ValidationWithConstructorDependency::class,
        ['token' => 'centralized'],
    );

    expect($validated)->toBe(['token' => 'centralized']);
});

it('rejects empty contexts and unrelated validation classes', function (): void {
    ValidationContext::make(operation: '');
})->throws(InvalidArgumentException::class);

it('rejects classes that are not validation classes', function (): void {
    makeValidationRunner()->make(stdClass::class, []);
})->throws(InvalidArgumentException::class);

it('adapts Laravel-style requests to the same validation lifecycle', function (): void {
    $request = new FakeValidationRequest([
        'email' => ' USER@EXAMPLE.COM ',
        'password' => 'password123',
    ]);

    $request->runPreparation();

    expect($request->all()['email'])->toBe('user@example.com')
        ->and($request->rules())->toBe([
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8'],
        ])
        ->and($request->messages())->toBe(['email.email' => 'Enter a valid email.'])
        ->and($request->attributes())->toBe(['email' => 'email address'])
        ->and($request->after())->toHaveCount(1);
});

it('rejects unrelated validation classes from the Form Request adapter', function (): void {
    $request = new class
    {
        use UsesValidation;

        protected function validation(): string
        {
            return stdClass::class;
        }

        public function all(): array
        {
            return [];
        }
    };

    expect(fn () => $request->rules())
        ->toThrow(InvalidArgumentException::class, 'must extend '.Validation::class);
});

final class ValidationTestDependency
{
    public function expectedToken(): string
    {
        return 'centralized';
    }
}

final class ValidationWithConstructorDependency extends Validation
{
    public function __construct(private readonly ValidationTestDependency $dependency) {}

    public function rules(ValidationContext $context): array
    {
        return ['token' => ['required', 'in:'.$this->dependency->expectedToken()]];
    }
}

final class RequestValidation extends Validation
{
    public function prepare(array $data, ValidationContext $context): array
    {
        $data['email'] = strtolower(trim((string) $data['email']));

        return $data;
    }

    public function rules(ValidationContext $context): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => [$context->isOperation('create') ? 'required' : 'nullable', 'min:8'],
        ];
    }

    public function messages(ValidationContext $context): array
    {
        return ['email.email' => 'Enter a valid email.'];
    }

    public function attributes(ValidationContext $context): array
    {
        return ['email' => 'email address'];
    }

    public function after(ValidationContext $context): array
    {
        return [static function (Validator $validator): void {}];
    }
}

final class FakeValidationRequest
{
    use UsesValidation;

    /** @param array<string, mixed> $data */
    public function __construct(private array $data) {}

    protected function validation(): string
    {
        return RequestValidation::class;
    }

    protected function validationOperation(): string
    {
        return 'create';
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function replace(array $data): void
    {
        $this->data = $data;
    }

    public function runPreparation(): void
    {
        $this->prepareForValidation();
    }
}
