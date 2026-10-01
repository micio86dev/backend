<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reusable interview links
|--------------------------------------------------------------------------
|
| `POST /api/reusable-links/redeem` is PUBLIC: anyone who holds a link token can
| call it, and every successful call creates a participant and mints a candidate
| credential. These limits are the throttle on that endpoint (the named limiter
| `reusable-link-redeem`, registered in `AppServiceProvider::boot()`), and the
| only cost control a leaked link has until an admin disables it.
|
| `per_ip_per_minute` is keyed on `$request->ip()`, exactly like
| `embed-exchange`. Behind a proxy that does not forward the client address that
| bucket degenerates to one shared bucket for everyone (see the release gate on
| trusted proxies); `per_link_per_hour` does not depend on the client address at
| all, which is why it is the primary brake.
|
| Every attempt counts, not only the successful ones: counting successes alone
| would make the rate-limit headers differ between a real token and an unknown
| one, which is an existence oracle.
*/

return [

    'redeem' => [

        /*
         * Attempts per client IP per minute, whatever their outcome. An
         * interview lasts many minutes and a fair NAT starts far fewer than
         * ten, while a guesser is held to 14,400 tries a day against a
         * 256-bit secret.
         */
        'per_ip_per_minute' => (int) env('REUSABLE_LINK_REDEEM_PER_IP_PER_MINUTE', 10),

        /*
         * Attempts per link per hour (keyed by a hash of the presented token,
         * never the token), from any number of clients. This is the ceiling on
         * what a leaked link can cost before it is disabled. It applies
         * identically to tokens that exist and tokens that do not.
         */
        'per_link_per_hour' => (int) env('REUSABLE_LINK_REDEEM_PER_LINK_PER_HOUR', 100),

    ],

];
