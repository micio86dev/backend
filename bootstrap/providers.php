<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\InterviewServiceProvider;
use App\Providers\QueueRuntimeServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    InterviewServiceProvider::class,
    EventServiceProvider::class,
    QueueRuntimeServiceProvider::class,
];
