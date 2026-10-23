<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Locks the test-database safety net ({@see TestCase::isTestDatabase()}): SQLite is always allowed
 * (it can never be the production MySQL), every other driver only when its database name is a
 * testing one. Regression guard for the data-loss incident where a cached config pointed the suite
 * at the real `graphy_planner` database.
 */
class TestDatabaseGuardTest extends TestCase
{
    public function test_sqlite_is_always_a_safe_test_database(): void
    {
        $this->assertTrue(TestCase::isTestDatabase('sqlite', ':memory:'));
        $this->assertTrue(TestCase::isTestDatabase('sqlite', 'database/testing.sqlite'));
        $this->assertTrue(TestCase::isTestDatabase('sqlite', ''));
    }

    public function test_the_real_mysql_database_is_refused(): void
    {
        $this->assertFalse(TestCase::isTestDatabase('mysql', 'graphy_planner'));
        $this->assertFalse(TestCase::isTestDatabase('mysql', ''));
        $this->assertFalse(TestCase::isTestDatabase('pgsql', 'graphy_planner'));
    }

    public function test_an_explicitly_named_testing_mysql_database_is_allowed(): void
    {
        $this->assertTrue(TestCase::isTestDatabase('mysql', 'graphy_planner_testing'));
        $this->assertTrue(TestCase::isTestDatabase('mysql', 'TEST_graphy'));
    }

    public function test_the_running_suite_itself_is_on_a_safe_connection(): void
    {
        $connection = (string) config('database.default');

        $this->assertTrue(TestCase::isTestDatabase(
            (string) config("database.connections.{$connection}.driver"),
            (string) config("database.connections.{$connection}.database"),
        ), 'The test suite is not running against a testing database.');
    }
}
