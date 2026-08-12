<?php

declare(strict_types=1);

namespace Inlay\Installer;

use Illuminate\Console\Command;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Rule;

final class MakeUserCommand extends Command
{
    protected $signature = 'inlay:make-user
        {--name= : User name}
        {--email= : User email address}
        {--password= : User password (prefer the hidden interactive prompt)}';

    protected $description = 'Create the first user who can sign in to an Inlay panel';

    public function __construct(
        private readonly Factory $validator,
        private readonly Hasher $hasher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $modelClass = (string) config('auth.providers.users.model', 'App\\Models\\User');

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->components->error("The configured auth user model [{$modelClass}] is not an Eloquent model.");

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Name')));
        $email = trim((string) ($this->option('email') ?: $this->ask('Email address')));
        $passwordOption = $this->option('password');
        $password = is_string($passwordOption) && $passwordOption !== ''
            ? $passwordOption
            : (string) $this->secret('Password (at least 8 characters)');

        if ((! is_string($passwordOption) || $passwordOption === '') && $password !== (string) $this->secret('Confirm password')) {
            $this->components->error('The password confirmation does not match.');

            return self::FAILURE;
        }

        /** @var Model $model */
        $model = new $modelClass;
        $validator = $this->validator->make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique($model->getTable(), 'email')],
                'password' => ['required', 'string', 'min:8', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $model->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $this->hasher->make($password),
        ])->save();

        $this->components->info("Created {$email}. You can now sign in to the Inlay panel.");

        return self::SUCCESS;
    }
}
