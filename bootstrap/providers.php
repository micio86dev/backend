<?php

use App\Providers\AppServiceProvider;
use App\Providers\InterviewServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    InterviewServiceProvider::class,
];
