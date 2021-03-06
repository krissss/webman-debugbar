<?php

namespace Kriss\WebmanDebugBar\Bootstrap;

use Illuminate\Database\Capsule\Manager as Capsule;
use Kriss\WebmanDebugBar\DataCollector\LaravelQueryCollector;
use Kriss\WebmanDebugBar\DebugBar;
use support\Db;
use Webman\Bootstrap;

class LaravelQuery implements Bootstrap
{
    /**
     * @inheritDoc
     */
    public static function start($worker)
    {
        if (!class_exists(Capsule::class)) {
            return;
        }
        $connections = array_keys(config('database.connections'));
        if (!$connections) {
            return;
        }

        $collectorName = (new LaravelQueryCollector())->getName();
        $debugBar = DebugBar::instance();
        $debugBar->boot();
        if (!$debugBar->hasCollector($collectorName)) {
            return;
        }
        /** @var LaravelQueryCollector $collector */
        $collector = $debugBar->getCollector($collectorName);

        foreach ($connections as $connection) {
            $collector->addListener(Db::connection($connection));
        }
    }
}