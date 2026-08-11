<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string) $this->configValue('inlay-two-factor.table', 'users');
        $secret = (string) $this->configValue('inlay-two-factor.secret_column', 'two_factor_secret');
        $codes = (string) $this->configValue('inlay-two-factor.recovery_codes_column', 'two_factor_recovery_codes');
        $confirmed = (string) $this->configValue('inlay-two-factor.confirmed_at_column', 'two_factor_confirmed_at');

        Schema::table($table, function (Blueprint $blueprint) use ($secret, $codes, $confirmed): void {
            if (! Schema::hasColumn($blueprint->getTable(), $secret)) {
                $blueprint->text($secret)->nullable();
            }
            if (! Schema::hasColumn($blueprint->getTable(), $codes)) {
                $blueprint->json($codes)->nullable();
            }
            if (! Schema::hasColumn($blueprint->getTable(), $confirmed)) {
                $blueprint->timestamp($confirmed)->nullable();
            }
        });
    }

    public function down(): void
    {
        $table = (string) $this->configValue('inlay-two-factor.table', 'users');
        $columns = array_values(array_filter([
            (string) $this->configValue('inlay-two-factor.secret_column', 'two_factor_secret'),
            (string) $this->configValue('inlay-two-factor.recovery_codes_column', 'two_factor_recovery_codes'),
            (string) $this->configValue('inlay-two-factor.confirmed_at_column', 'two_factor_confirmed_at'),
        ], static fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($columns !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropColumn($columns);
            });
        }
    }

    private function configValue(string $key, mixed $default): mixed
    {
        if (function_exists('app') && app()->bound('config')) {
            return app('config')->get($key, $default);
        }

        return $default;
    }
};
