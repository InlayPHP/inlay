<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Inlay\Actions\Action;
use Inlay\Actions\ActionRunner;
use Inlay\Notifications\Notification;
use Inlay\Notifications\NotificationManager;

it('builds a stable transport-safe notification contract', function (): void {
    $notification = Notification::make('Saved')
        ->description('The profile is ready.')
        ->success()
        ->icon('check')
        ->duration(7000)
        ->id('profile-saved')
        ->action('View profile', '/account/profile');

    $payload = $notification->toArray();

    expect($payload)->toBe([
        'contract' => 'inlay.notifications.v1',
        'id' => 'profile-saved',
        'title' => 'Saved',
        'body' => 'The profile is ready.',
        'status' => 'success',
        'icon' => 'check',
        'duration' => 7000,
        'persistent' => false,
        'actions' => [['label' => 'View profile', 'url' => '/account/profile']],
    ])->and($notification->toArray()['id'])->toBe('profile-saved');
});

it('supports persistent and aliased presentation settings', function (): void {
    $payload = Notification::make()
        ->heading('Review required')
        ->warning()
        ->persistent()
        ->toArray();

    expect($payload['title'])->toBe('Review required')
        ->and($payload['status'])->toBe('warning')
        ->and($payload['duration'])->toBeNull()
        ->and($payload['persistent'])->toBeTrue();
});

it('rejects unsafe notification data', function (): void {
    Notification::make('Saved')->status('critical');
})->throws(InvalidArgumentException::class);

it('rejects unsafe notification actions', function (): void {
    Notification::make('Saved')->action('Run', 'javascript:alert(1)');
})->throws(InvalidArgumentException::class);

it('stores and pulls session notifications exactly once', function (): void {
    $request = Request::create('/account');
    $request->setLaravelSession(new Store('inlay-test', new ArraySessionHandler(120)));
    $container = new Container;
    $container->instance('request', $request);
    $manager = new NotificationManager($container);

    $manager->send(Notification::make('Saved')->success());
    $manager->send(Notification::make('Queued')->info());

    expect($manager->pull())->toHaveCount(2)
        ->and($manager->pull())->toBe([])
        ->and($request->session()->has(NotificationManager::SESSION_KEY))->toBeFalse();
});

it('keeps notifications in memory when no request session exists', function (): void {
    $manager = new NotificationManager(new Container);
    $manager->send(Notification::make('Offline')->info());

    expect($manager->pending())->toHaveCount(1)
        ->and($manager->pull()[0]['title'])->toBe('Offline')
        ->and($manager->pending())->toBe([]);
});

it('delivers action success messages through the optional notification manager', function (): void {
    $container = new Container;
    $manager = new NotificationManager($container);
    $container->instance(NotificationManager::class, $manager);
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $runner = new ActionRunner(
        $container,
        new Factory(new Translator(new ArrayLoader, 'en'), $container),
        $capsule->getDatabaseManager(),
    );
    $action = Action::make('save')
        ->authorizeUsing(fn (): bool => true)
        ->action(fn (): string => 'saved')
        ->successNotificationTitle('Saved.');

    $result = $runner->run($action, Request::create('/save', 'POST'));

    expect($result->message)->toBe('Saved.')
        ->and($manager->pull()[0])->toMatchArray(['title' => 'Saved.', 'status' => 'success']);
});

it('delivers, scopes, and marks database notifications without changing session delivery', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create(NotificationManager::DATABASE_TABLE, function ($table): void {
        $table->increments('id');
        $table->string('notifiable_type');
        $table->string('notifiable_id');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    $manager = new NotificationManager($container);
    $container->instance('db', $capsule->getDatabaseManager());

    $user = new class extends Model
    {
        protected $table = 'users';

        public function getMorphClass()
        {
            return 'users';
        }
    };
    $user->setAttribute('id', 7);
    $other = new class extends Model
    {
        protected $table = 'users';

        public function getMorphClass()
        {
            return 'users';
        }
    };
    $other->setAttribute('id', 8);

    $manager->sendToDatabase(Notification::make('Saved')->success(), $user);
    $manager->sendToDatabase(Notification::make('Other user')->info(), $other);

    $rows = $manager->databaseNotifications($user, unreadOnly: true);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['data'])->toMatchArray(['title' => 'Saved', 'status' => 'success'])
        ->and($manager->markDatabaseAsRead($user, $rows[0]['database_id']))->toBeTrue()
        ->and($manager->databaseNotifications($user, unreadOnly: true))->toBe([])
        ->and($manager->markAllDatabaseAsRead($other))->toBe(1);
});
