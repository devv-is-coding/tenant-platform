<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    /**
     * Under `--parallel`, give each worker's agency databases a name of their
     * own. On SQLite they are files in one shared folder, so two workers making
     * an agency with the same fixed id (the seeders' "zamboanguita", the
     * onboarding tests' "valencia") would otherwise collide.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $token = ParallelTesting::token();

        if ($token !== false) {
            config(['tenancy.database.suffix' => "_test{$token}"]);
        }
    }
}
