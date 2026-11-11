<?php

namespace Ernestdefoe\Curio\Badges;

use Flarum\Foundation\AbstractServiceProvider;
use FoF\Badges\Trigger\MetricManager;

/** Adds Curio's metrics to FoF Badges' triggers. Only registered while FoF Badges is enabled. */
class BadgesServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        if (! class_exists(MetricManager::class)) {
            return;
        }

        $this->container->extend(MetricManager::class, function (MetricManager $metrics) {
            $metrics->register(new ItemsMetric());
            $metrics->register(new CollectionsMetric());

            return $metrics;
        });
    }
}
