<?php

namespace ItmoSoft\Invoices\Facades;

use Illuminate\Support\Facades\Facade;

class Invoices extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'itmosoft.invoices';
    }
}