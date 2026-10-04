<?php

namespace Bizzsol\EmployeeSync;

use Bizzsol\EmployeeSync\Services\EmployeeAccessSync;
use Illuminate\Support\ServiceProvider;

class EmployeeSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/employee-sync.php', 'employee-sync');
        $this->app->singleton(EmployeeAccessSync::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/employee-sync.php' => config_path('employee-sync.php')], 'employee-sync-config');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ReportDrift::class]);
        }
    }
}
