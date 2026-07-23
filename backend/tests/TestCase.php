<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sanctum's EnsureFrontendRequestsAreStateful middleware only starts a
     * session for requests it can identify as coming from a trusted SPA
     * frontend (matched via the Origin/Referer header against
     * `sanctum.stateful`). Real browser requests send this automatically;
     * the test client does not, so we simulate it here for every test.
     */
    protected $defaultHeaders = [
        'Referer' => 'http://localhost',
    ];
}
