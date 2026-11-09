<?php

return [
    // Fixed 60-second windows, starting at the first authenticated request.
    'resolve_per_minute' => (int) env('PLATFORM_RESOLVE_PER_MINUTE', 120),
    // Explicit deployment configuration only; never populated from request input.
    'resolve_platform_limits' => [],
    'prune_scheduled' => (bool) env('PLATFORM_IDEMPOTENCY_PRUNE_ENABLED', false),
];
