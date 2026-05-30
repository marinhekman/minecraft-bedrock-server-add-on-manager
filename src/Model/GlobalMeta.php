<?php

declare(strict_types=1);

namespace App\Model;

final readonly class GlobalMeta
{
    /**
     * @param list<array<string, int>> $resourceLimits
     *   Each entry is a map of profile name → max count for that combination.
     *   Example: [['high' => 1, 'low' => 1], ['medium' => 2]]
     */
    public function __construct(
        public array $resourceLimits,
        public int   $heartbeatTtl,
    ) {}

    /**
     * Build from raw parsed YAML. Missing keys fall back to defaults.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            resourceLimits: self::parseResourceLimits($data['resource_limits'] ?? []),
            heartbeatTtl:   (int) ($data['heartbeat_ttl'] ?? 120),
        );
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param mixed $raw
     * @return list<array<string, int>>
     */
    private static function parseResourceLimits(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $limits = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Each entry is a map like ['high' => 1, 'low' => 1].
            // YAML may parse it as a list of single-key maps; flatten those.
            $combination = [];
            foreach ($entry as $key => $value) {
                if (is_array($value)) {
                    // Handles [['high' => 1], ['low' => 1]] nesting
                    foreach ($value as $k => $v) {
                        $combination[(string) $k] = (int) $v;
                    }
                } else {
                    $combination[(string) $key] = (int) $value;
                }
            }

            if ($combination !== []) {
                $limits[] = $combination;
            }
        }

        return $limits;
    }
}
