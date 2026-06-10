<?php

namespace App\Support;

class Environment
{
    /**
     * Determine if the application is running in production based on the HTTP host.
     * Falls back to APP_ENV in CLI context (artisan, queues, cron).
     */
    public static function isProduction(): bool
    {
        $productionDomains = [
            'bridge.cyberia.church',
            'cyberia.church',
        ];

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;

        if ($host !== null) {
            $host = strtok($host, ':');

            return in_array($host, $productionDomains, true);
        }

        return app()->isProduction();
    }
}
