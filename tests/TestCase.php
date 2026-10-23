<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net against wiping the real database. `RefreshDatabase` runs `migrate:fresh` on the
     * default connection, and a cached config (`bootstrap/cache/config.php`) silently overrides
     * `phpunit.xml`/`.env.testing`, so tests can end up pointed at the real MySQL `graphy_planner`.
     * This boots the app, checks the resolved connection, and aborts the run *before* any migration
     * if it is not clearly a testing database — a SQLite database (it can never be the production
     * MySQL) or any other driver whose database name contains "test".
     *
     * Data loss from exactly this has happened before; the guard makes it impossible rather than a
     * matter of remembering to clear the config cache.
     */
    public function createApplication()
    {
        /** @var Application $app */
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $driver = (string) $app['config']->get("database.connections.{$connection}.driver");
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! self::isTestDatabase($driver, $database)) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against the "%s" connection (database "%s") — it is not a '
                .'testing database. A cached config (bootstrap/cache/config.php) or a missing '
                .'.env.testing is routing tests at the real database. Run `php artisan config:clear` '
                .'and ensure .env.testing points at a testing database, then retry.',
                $connection,
                $database !== '' ? $database : '(none)',
            ));
        }

        return $app;
    }

    /**
     * SQLite can never be the production MySQL, so it is always safe; any other driver is only
     * allowed when its database name is explicitly a testing one (contains "test").
     */
    public static function isTestDatabase(string $driver, string $database): bool
    {
        if ($driver === 'sqlite') {
            return true;
        }

        return str_contains(strtolower($database), 'test');
    }

    /**
     * Step I-16.2 / M-1.2: act as if the user just re-entered their password, for tests of
     * routes behind `password.confirm` (org settings, data exports) that aren't about the gate.
     */
    protected function confirmPassword(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
