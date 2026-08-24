<?php

namespace App\Services\Monitoring;

/**
 * config/monitoring.php, read back as objects.
 *
 * The one place that knows what a service entry looks like, so a malformed or
 * half-written entry fails here rather than four layers down inside a probe.
 */
class ServiceRegistry
{
    /** @var array<string, ServiceDefinition>|null */
    private ?array $services = null;

    /** @return array<string, ServiceDefinition> */
    public function all(): array
    {
        if ($this->services !== null) {
            return $this->services;
        }

        $services = [];

        /** @var array<string, array<string, mixed>> $configured */
        $configured = config('monitoring.services', []);

        foreach ($configured as $key => $entry) {
            $services[$key] = ServiceDefinition::fromConfig((string) $key, $entry);
        }

        return $this->services = $services;
    }

    public function find(string $key): ?ServiceDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Definitions in the order the board draws them: by group, then by the
     * order they were written in the config. Group order is fixed here rather
     * than alphabetically, because it reads as a stack — the chain at the
     * bottom, the things people actually use at the top.
     *
     * @return array<string, array<int, ServiceDefinition>>
     */
    public function grouped(): array
    {
        $order = ['chain', 'web', 'infra', 'daemon', 'onchain', 'product'];
        $groups = [];

        foreach ($this->all() as $definition) {
            $groups[$definition->group][] = $definition;
        }

        uksort($groups, function (string $a, string $b) use ($order): int {
            $ai = array_search($a, $order, true);
            $bi = array_search($b, $order, true);

            return ($ai === false ? PHP_INT_MAX : $ai) <=> ($bi === false ? PHP_INT_MAX : $bi);
        });

        return $groups;
    }
}
