<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Provides query count assertions for N+1 detection.
 */
trait QueryCountHelper
{
    /**
     * Assert that a callback executes within a maximum number of DB queries.
     *
     * @param  \Closure  $callback  The code to execute
     * @param  int  $maxQueries  Maximum allowed query count
     * @param  string  $message  Failure message
     */
    protected function assertQueryCount(\Closure $callback, int $maxQueries, string $message = ''): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $count = count($queries);
        $msg = $message ?: "Expected at most {$maxQueries} queries, but {$count} were executed.";

        $this->assertLessThanOrEqual($maxQueries, $count, $msg);
    }

    /**
     * Assert no N+1 on an API endpoint.
     *
     * @param  string  $method  HTTP method (GET, POST, etc.)
     * @param  string  $uri  The endpoint URI
     * @param  int  $maxQueries  Maximum allowed query count
     */
    protected function assertNoNPlusOne(string $method, string $uri, int $maxQueries, string $message = ''): void
    {
        $this->assertQueryCount(function () use ($method, $uri) {
            $this->{strtolower($method).'Json'}($uri);
        }, $maxQueries, $message);
    }
}
