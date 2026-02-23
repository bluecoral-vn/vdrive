<?php

namespace Tests;

use App\Services\PermissionContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Override actingAs to flush the PermissionContext scoped singleton.
     * This ensures each HTTP call in tests resolves a fresh context
     * for the current user, matching production behavior.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        $this->app->forgetScopedInstances();

        return parent::actingAs($user, $guard);
    }
}
