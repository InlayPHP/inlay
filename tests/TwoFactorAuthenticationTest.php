<?php

declare(strict_types=1);

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Inlay\Panel;
use Inlay\TwoFactorAuthentication\Concerns\HasTwoFactorAuthentication;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorQrCodeRenderer;
use Inlay\TwoFactorAuthentication\Fortify\FortifyChallengeBridge;
use Inlay\TwoFactorAuthentication\Fortify\InertiaTwoFactorChallengeViewResponse;
use Inlay\TwoFactorAuthentication\TwoFactorAuthenticationPlugin;
use Inlay\TwoFactorAuthentication\TwoFactorLoginStep;
use Inlay\TwoFactorAuthentication\TwoFactorManager;

final class TwoFactorTestUser extends Model implements TwoFactorAuthenticatable
{
    use AuthenticatableTrait;
    use HasTwoFactorAuthentication;

    protected $table = 'two_factor_test_users';

    protected $guarded = [];

    protected $casts = ['two_factor_confirmed_at' => 'datetime', 'two_factor_recovery_codes' => 'array'];
}

final class FortifyTwoFactorTestUser extends Model implements TwoFactorAuthenticatable
{
    use AuthenticatableTrait;
    use \Inlay\TwoFactorAuthentication\Concerns\UsesFortifyTwoFactorAuthentication;

    protected $table = 'two_factor_test_users';

    protected $guarded = [];

    protected $casts = ['two_factor_confirmed_at' => 'datetime'];
}

function twoFactorManager(): array
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('two_factor_test_users', function ($table): void {
        $table->increments('id');
        $table->timestamps();
        $table->text('two_factor_secret')->nullable();
        $table->json('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
    });

    $encrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');

    return [new TwoFactorManager($encrypter, $capsule->getDatabaseManager()), $capsule, $encrypter];
}

it('matches the RFC TOTP vector and creates an otpauth enrollment payload', function (): void {
    [$manager] = twoFactorManager();
    $user = new TwoFactorTestUser;
    $user->save();

    expect($manager->totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(59, 30)))->toBe('287082')
        ->and($manager->verifyTotp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59))->toBeTrue();

    $enrollment = $manager->beginEnrollment($user, 'test@example.com');
    expect($enrollment->secret)->not->toBeEmpty()
        ->and($enrollment->otpauthUri)->toStartWith('otpauth://totp/')
        ->and($enrollment->recoveryCodes)->toHaveCount(8)
        ->and($enrollment->jsonSerialize())->toHaveKeys(['contract', 'secret', 'otpauthUri', 'recoveryCodes'])
        ->and($user->twoFactorSecret())->not->toBe($enrollment->secret)
        ->and($user->twoFactorRecoveryCodes()[0])->not->toBe($enrollment->recoveryCodes[0])
        ->and($user->twoFactorConfirmedAt())->toBeNull();
});

it('confirms, consumes a recovery code once, and disables two-factor state transactionally', function (): void {
    [$manager] = twoFactorManager();
    $user = new TwoFactorTestUser;
    $user->save();
    $enrollment = $manager->beginEnrollment($user, 'test@example.com');
    $code = $manager->totp($enrollment->secret, intdiv(time(), 30));

    expect($manager->confirmEnrollment($user, $code))->toBeTrue()
        ->and($manager->isEnabled($user->refresh()))->toBeTrue()
        ->and($manager->verifyChallenge($user, $enrollment->recoveryCodes[0]))->toBeTrue()
        ->and($manager->verifyChallenge($user->refresh(), $enrollment->recoveryCodes[0]))->toBeFalse();

    $manager->disable($user->refresh());
    expect($manager->isEnabled($user->refresh()))->toBeFalse()
        ->and($user->twoFactorRecoveryCodes())->toBeEmpty();
});

it('does not accept a challenge while enrollment is still unconfirmed', function (): void {
    [$manager] = twoFactorManager();
    $user = new TwoFactorTestUser;
    $user->save();
    $enrollment = $manager->beginEnrollment($user, 'test@example.com');

    expect($manager->verifyChallenge($user->refresh(), $enrollment->recoveryCodes[0]))->toBeFalse();
});

it('regenerates encrypted recovery codes without changing the enrolled secret', function (): void {
    [$manager] = twoFactorManager();
    $user = new TwoFactorTestUser;
    $user->save();
    $enrollment = $manager->beginEnrollment($user, 'test@example.com');
    $manager->confirmEnrollment($user, $manager->totp($enrollment->secret, intdiv(time(), 30)));
    $secret = $user->twoFactorSecret();

    $codes = $manager->regenerateRecoveryCodes($user->refresh());

    expect($codes)->toHaveCount(8)
        ->and($user->refresh()->twoFactorSecret())->toBe($secret)
        ->and($manager->verifyChallenge($user->refresh(), $enrollment->recoveryCodes[0]))->toBeFalse()
        ->and($manager->verifyChallenge($user->refresh(), $codes[0]))->toBeTrue();
});

it('adapts Fortify encrypted recovery-code storage to the Inlay manager', function (): void {
    [$manager] = twoFactorManager();
    $previousContainer = Container::getInstance();
    $container = new Container;
    $fortifyEncrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $container->instance('encrypter', $fortifyEncrypter);
    Container::setInstance($container);

    try {
        $user = new FortifyTwoFactorTestUser;
        $user->save();
        $enrollment = $manager->beginEnrollment($user, 'fortify@example.com');
        $storedCodes = $user->getAttribute('two_factor_recovery_codes');

        expect($storedCodes)->toBeString()
            ->and($storedCodes)->not->toContain($enrollment->recoveryCodes[0])
            ->and($user->twoFactorRecoveryCodes())->toHaveCount(8);

        expect($manager->confirmEnrollment($user, $manager->totp($enrollment->secret, intdiv(time(), 30))))->toBeTrue()
            ->and($manager->verifyChallenge($user->refresh(), $enrollment->recoveryCodes[0]))->toBeTrue()
            ->and($manager->verifyChallenge($user->refresh(), $enrollment->recoveryCodes[0]))->toBeFalse();

        $regenerated = $manager->regenerateRecoveryCodes($user->refresh());
        expect($regenerated)->toHaveCount(8)
            ->and($manager->verifyChallenge($user->refresh(), $regenerated[0]))->toBeTrue();

        $manager->disable($user->refresh());
        expect($user->refresh()->getAttribute('two_factor_secret'))->toBeNull()
            ->and($user->getAttribute('two_factor_recovery_codes'))->toBeNull();
    } finally {
        Container::setInstance($previousContainer);
    }
});

it('suspends an enabled panel login behind the challenge route', function (): void {
    [$manager] = twoFactorManager();
    $user = new TwoFactorTestUser;
    $user->save();
    $enrollment = $manager->beginEnrollment($user, 'test@example.com');
    $manager->confirmEnrollment($user, $manager->totp($enrollment->secret, intdiv(time(), 30)));
    $request = Request::create('/admin/login', 'POST');
    $request->setLaravelSession(new Store('inlay-two-factor-test', new ArraySessionHandler(120)));

    $response = (new TwoFactorLoginStep($manager))->handle(
        new Inlay\Auth\LoginAttempt($request, Panel::make('admin'), $user->refresh(), true),
        fn (): ?Symfony\Component\HttpFoundation\Response => null,
    );

    expect($response?->getStatusCode())->toBe(302)
        ->and($response?->getTargetUrl())->toEndWith('/admin/two-factor-challenge')
        ->and($request->session()->get('inlay.two-factor.pending.user'))->toBe($user->getKey());
});

it('runs its user-column migration idempotently and rolls it back', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $container = new Container;
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->schema());
    $container->instance('config', new Repository([
        'inlay-two-factor' => [
            'table' => 'migration_users',
            'secret_column' => 'otp_secret',
            'recovery_codes_column' => 'otp_recovery_codes',
            'confirmed_at_column' => 'otp_confirmed_at',
        ],
    ]));
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    try {
        Schema::create('migration_users', function ($table): void {
            $table->increments('id');
        });

        $migration = require __DIR__.'/../packages/two-factor-authentication/database/migrations/2026_08_01_000000_add_two_factor_columns_to_users_table.php';
        $migration->up();
        $migration->up();

        expect(Schema::getColumnListing('migration_users'))->toContain(
            'otp_secret',
            'otp_recovery_codes',
            'otp_confirmed_at',
        );

        $migration->down();
        expect(Schema::getColumnListing('migration_users'))->not->toContain(
            'otp_secret',
            'otp_recovery_codes',
            'otp_confirmed_at',
        );
    } finally {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }
});

it('registers public challenge routes through the panel plugin', function (): void {
    $panel = Panel::make('admin')->plugin(TwoFactorAuthenticationPlugin::make());

    expect($panel->getRoutes())->toHaveCount(7)
        ->and($panel->getRoutes()[0]->name())->toBe('two-factor.challenge')
        ->and($panel->getRoutes()[0]->requiresAuthentication())->toBeFalse()
        ->and($panel->getRoutes()[1]->method())->toBe('POST')
        ->and($panel->getRoutes()[1]->middlewareList())->toContain('throttle:6,1')
        ->and($panel->getRoutes()[2]->name())->toBe('two-factor.settings')
        ->and($panel->getRoutes()[2]->requiresAuthentication())->toBeTrue();
});

it('keeps QR rendering as an application-bound optional contract', function (): void {
    expect(interface_exists(TwoFactorQrCodeRenderer::class))->toBeTrue()
        ->and((new ReflectionClass(TwoFactorQrCodeRenderer::class))->hasMethod('render'))->toBeTrue();
});

it('publishes a Fortify challenge response through the shared form contract', function (): void {
    $response = new InertiaTwoFactorChallengeViewResponse(
        component: 'custom-two-factor/challenge',
        action: '/two-factor-challenge',
    );
    $props = $response->props();
    $form = $props['challengeForm']->jsonSerialize();

    expect($props['inlayPage']['type'])->toBe('two-factor-challenge')
        ->and($form['contract'])->toBe('inlay.forms.v1')
        ->and($form['action'])->toBe('/two-factor-challenge')
        ->and($form['method'])->toBe('post')
        ->and($form['schema'])->toHaveCount(2);
});

it('keeps the Fortify challenge bridge opt-in when Fortify is absent', function (): void {
    if (class_exists('Laravel\\Fortify\\Fortify')) {
        test()->markTestSkipped('Fortify is installed in this environment.');
    }

    expect(function (): void {
        FortifyChallengeBridge::register();
    })->toThrow(\LogicException::class);
});
