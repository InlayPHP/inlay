<?php

declare(strict_types=1);

namespace Inlay\Notifications;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

final class NotificationManager
{
    public const SESSION_KEY = 'inlay.notifications';

    public const DATABASE_TABLE = 'inlay_notifications';

    /** @var list<array<string, mixed>> */
    private array $memory = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function send(Notification $notification): Notification
    {
        $request = $this->request();

        if ($request?->hasSession()) {
            $current = $request->session()->get(self::SESSION_KEY, []);
            $current = is_array($current) ? array_values($current) : [];
            $request->session()->put(self::SESSION_KEY, [...$current, $notification->toArray()]);
        } else {
            $this->memory[] = $notification->toArray();
        }

        return $notification;
    }

    /**
     * Persist a notification for a model that exposes getKey() and
     * getMorphClass(). Eloquent is intentionally not a hard dependency of the
     * session transport, so the database manager is resolved only when this
     * method is called.
     */
    public function sendToDatabase(Notification $notification, mixed $notifiable, ?string $connection = null): Notification
    {
        [$type, $id] = $this->notifiableIdentity($notifiable);
        $database = $this->databaseManager();
        $payload = $notification->toArray();
        $now = Carbon::now();

        $database->connection($connection)->table(self::DATABASE_TABLE)->insert([
            'notifiable_type' => $type,
            'notifiable_id' => $id,
            'data' => json_encode($payload, JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $notification;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function databaseNotifications(mixed $notifiable, bool $unreadOnly = false, int $limit = 50, ?string $connection = null): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Notification limits must be between 1 and 200.');
        }

        [$type, $id] = $this->notifiableIdentity($notifiable);
        $query = $this->databaseManager()
            ->connection($connection)
            ->table(self::DATABASE_TABLE)
            ->where('notifiable_type', $type)
            ->where('notifiable_id', $id)
            ->when($unreadOnly, static fn ($builder) => $builder->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit);

        return $query->get()->map(function (object $row): array {
            $data = json_decode((string) $row->data, true);

            return [
                'database_id' => $row->id,
                'read_at' => $row->read_at,
                'created_at' => $row->created_at,
                'data' => is_array($data) ? $data : [],
            ];
        })->all();
    }

    public function markDatabaseAsRead(mixed $notifiable, int|string $databaseId, ?string $connection = null): bool
    {
        [$type, $id] = $this->notifiableIdentity($notifiable);

        return (bool) $this->databaseManager()
            ->connection($connection)
            ->table(self::DATABASE_TABLE)
            ->where('id', $databaseId)
            ->where('notifiable_type', $type)
            ->where('notifiable_id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    public function markAllDatabaseAsRead(mixed $notifiable, ?string $connection = null): int
    {
        [$type, $id] = $this->notifiableIdentity($notifiable);

        return $this->databaseManager()
            ->connection($connection)
            ->table(self::DATABASE_TABLE)
            ->where('notifiable_type', $type)
            ->where('notifiable_id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /**
     * Return notifications once. Inertia shares call this during response
     * serialization, so a refresh cannot replay an old toast.
     *
     * @return list<array<string, mixed>>
     */
    public function pull(): array
    {
        $request = $this->request();
        $notifications = [];

        if ($request?->hasSession()) {
            $stored = $request->session()->get(self::SESSION_KEY, []);
            $request->session()->forget(self::SESSION_KEY);
            if (is_array($stored)) {
                $notifications = array_values($stored);
            }
        }

        $notifications = [...$notifications, ...$this->memory];
        $this->memory = [];

        return array_values(array_filter(
            $notifications,
            static fn (mixed $notification): bool => is_array($notification),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function pending(): array
    {
        return [...$this->memory];
    }

    public function clear(): void
    {
        $this->memory = [];
        $request = $this->request();
        if ($request?->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    private function databaseManager(): object
    {
        if (! $this->container->bound('db')) {
            throw new LogicException('Database notifications require a bound Laravel database manager.');
        }

        $database = $this->container->make('db');
        if (! is_object($database) || ! method_exists($database, 'connection')) {
            throw new LogicException('The bound database manager cannot create a connection.');
        }

        return $database;
    }

    /** @return array{0: string, 1: string|int} */
    private function notifiableIdentity(mixed $notifiable): array
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'getKey') || ! method_exists($notifiable, 'getMorphClass')) {
            throw new InvalidArgumentException('Database notifications require a notifiable model with getKey() and getMorphClass().');
        }

        $type = $notifiable->getMorphClass();
        $id = $notifiable->getKey();
        if (! is_string($type) || trim($type) === '' || (! is_string($id) && ! is_int($id))) {
            throw new InvalidArgumentException('A notifiable model must expose a non-empty morph class and scalar key.');
        }

        return [$type, $id];
    }

    private function request(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        return $request instanceof Request ? $request : null;
    }
}
