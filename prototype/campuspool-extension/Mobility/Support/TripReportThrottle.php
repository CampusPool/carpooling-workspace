<?php

namespace STS\Mobility\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;

/** Use the existing local database cache's atomic increments for report limits. */
final class TripReportThrottle extends ThrottleRequests
{
    public function __construct(RateLimiter $registered)
    {
        $limiter = new RateLimiter(Cache::store('database'));
        $limiter->for('mobility-trip-report-create', $registered->limiter('mobility-trip-report-create'));
        parent::__construct($limiter);
    }
}
