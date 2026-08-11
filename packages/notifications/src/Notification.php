<?php

declare(strict_types=1);

namespace Inlay\Notifications;

use Inlay\Support\SafeUrl;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A transport-safe notification that can be delivered over Inertia or JSON.
 *
 * Notification callbacks are deliberately not supported. The object is a
 * value contract: it contains only data that can be serialized, inspected,
 * queued, and rendered by either React or Vue.
 */
final class Notification implements JsonSerializable
{
    /** @var list<array{label: string, url: string}> */
    private array $actions = [];

    private string $status = 'info';

    private ?string $body = null;

    private ?string $icon = null;

    private ?int $duration = 5000;

    private bool $persistent = false;

    private ?string $id = null;

    private ?string $generatedId = null;

    private function __construct(private string $title)
    {
        $this->title = trim($title);
    }

    public static function make(string $title = ''): self
    {
        return new self($title);
    }

    public function title(string $title): self
    {
        $this->title = self::text($title, 'title');

        return $this;
    }

    /** Alias for title(), matching common notification terminology. */
    public function heading(string $title): self
    {
        return $this->title($title);
    }

    public function body(?string $body): self
    {
        $this->body = $body === null ? null : self::text($body, 'body');

        return $this;
    }

    /** Alias for body(). */
    public function description(?string $description): self
    {
        return $this->body($description);
    }

    public function status(string $status): self
    {
        $status = strtolower(trim($status));

        if (! in_array($status, ['success', 'info', 'warning', 'danger'], true)) {
            throw new InvalidArgumentException("Unsupported notification status [{$status}].");
        }

        $this->status = $status;

        return $this;
    }

    public function success(): self
    {
        return $this->status('success');
    }

    public function info(): self
    {
        return $this->status('info');
    }

    public function warning(): self
    {
        return $this->status('warning');
    }

    public function danger(): self
    {
        return $this->status('danger');
    }

    public function icon(?string $icon): self
    {
        $this->icon = $icon === null ? null : self::text($icon, 'icon');

        return $this;
    }

    /**
     * Set the automatic dismissal time in milliseconds. Null or persistent()
     * keeps the notification visible until the visitor dismisses it.
     */
    public function duration(?int $milliseconds): self
    {
        if ($milliseconds !== null && $milliseconds < 0) {
            throw new InvalidArgumentException('Notification duration must be zero or greater.');
        }

        $this->duration = $milliseconds;
        $this->persistent = $milliseconds === null;

        return $this;
    }

    public function persistent(bool $persistent = true): self
    {
        $this->persistent = $persistent;

        if ($persistent) {
            $this->duration = null;
        } elseif ($this->duration === null) {
            $this->duration = 5000;
        }

        return $this;
    }

    /** Give repeated notifications a stable client-side identity. */
    public function id(string $id): self
    {
        $this->id = self::text($id, 'id');

        return $this;
    }

    /**
     * Add a safe link action. Notifications never accept arbitrary HTML or
     * JavaScript URLs, so community renderers can safely render the label.
     */
    public function action(string $label, string $url): self
    {
        $label = self::text($label, 'action label');
        $this->actions[] = [
            'label' => $label,
            'url' => SafeUrl::from($url)->value(),
        ];

        return $this;
    }

    /** Queue this notification using the application's manager. */
    public function send(): self
    {
        app(NotificationManager::class)->send($this);

        return $this;
    }

    /**
     * Persist this notification for a notifiable model.
     *
     * Database delivery is opt-in and requires the host application to run
     * the published notifications migration. The notification remains the
     * same transport-safe value object used by session delivery.
     */
    public function sendToDatabase(mixed $notifiable, ?string $connection = null): self
    {
        app(NotificationManager::class)->sendToDatabase($this, $notifiable, $connection);

        return $this;
    }

    public function statusValue(): string
    {
        return $this->status;
    }

    /** @return array{contract: string, id: string, title: string, body: string|null, status: string, icon: string|null, duration: int|null, persistent: bool, actions: list<array{label: string, url: string}>} */
    public function toArray(): array
    {
        return [
            'contract' => 'inlay.notifications.v1',
            'id' => $this->id ??= ($this->generatedId ??= self::fingerprint()),
            'title' => self::text($this->title, 'title'),
            'body' => $this->body,
            'status' => $this->status,
            'icon' => $this->icon,
            'duration' => $this->persistent ? null : $this->duration,
            'persistent' => $this->persistent,
            'actions' => $this->actions,
        ];
    }

    /** @return array{contract: string, id: string, title: string, body: string|null, status: string, icon: string|null, duration: int|null, persistent: bool, actions: list<array{label: string, url: string}>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function text(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("Notification {$field} must not be empty.");
        }

        if (preg_match('/[\\x00-\\x1F\\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Notification {$field} cannot contain control characters.");
        }

        return $value;
    }

    private static function fingerprint(): string
    {
        return 'notification-'.bin2hex(random_bytes(8));
    }
}
