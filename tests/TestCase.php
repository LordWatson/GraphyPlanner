<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
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
