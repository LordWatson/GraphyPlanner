<?php

use App\Providers\AppServiceProvider;
use App\Providers\CampaignServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ObservabilityServiceProvider;

return [
    AppServiceProvider::class,
    CampaignServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    ObservabilityServiceProvider::class,
];
