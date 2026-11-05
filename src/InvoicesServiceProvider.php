<?php

namespace ItmoSoft\Invoices;

use Illuminate\Support\ServiceProvider;
use ItmoSoft\Invoices\Services\InvoiceManager;

class InvoicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/invoices.php', 'itmosoft-invoices');
        $this->app->singleton(InvoiceManager::class);
        $this->app->alias(InvoiceManager::class, 'itmosoft.invoices');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/invoices.php' => config_path('itmosoft-invoices.php')], 'itmosoft-invoices-config');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}