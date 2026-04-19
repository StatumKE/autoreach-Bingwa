<?php

namespace Statum\NativeScheduler\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed execute(array $options = [])
 * @method static object|null getStatus()
 *
 * @see \Statum\NativeScheduler\NativeScheduler
 */
class NativeScheduler extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Statum\NativeScheduler\NativeScheduler::class;
    }
}
