<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\GlobalMeta;

class ResourceBudgetChecker
{
    public function __construct(
        private readonly RedisClient      $redis,
        private readonly GlobalMetaReader $globalMetaReader,
    ) {}

    /**
     * Returns true if starting a server with $candidateProfile would fit
     * within at least one of the configured resource_limits combinations,
     * given the profiles of all currently running containers.
     */
    public function canStart(string $candidateProfile): bool
    {
        $meta = $this->globalMetaReader->read();

        if ($meta->resourceLimits === []) {
            // No limits configured — always allow.
            return true;
        }

        $running = $this->getRunningProfiles();

        foreach ($meta->resourceLimits as $combination) {
            if ($this->isValidCombination([...$running, $candidateProfile], $combination)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the memory profile of every server whose Redis data reports
     * running = true. Servers with no memoryProfile default to 'medium'.
     *
     * @return list<string>
     */
    public function getRunningProfiles(): array
    {
        $profiles = [];

        foreach ($this->redis->getAllServerNames() as $name) {
            $data = $this->redis->getServer($name);

            if ($data === null) {
                continue;
            }

            if (!($data['running'] ?? false)) {
                continue;
            }

            $profiles[] = $data['memoryProfile'] ?? 'medium';
        }

        return $profiles;
    }

    /**
     * Returns true if $profiles (running + candidate combined) fits within
     * $combination — i.e. the count of each profile does not exceed the
     * allowed maximum, and no profiles are present that the combination
     * does not account for.
     *
     * @param list<string>         $profiles    Combined list including candidate.
     * @param array<string, int>   $combination Allowed maxima, e.g. ['high' => 1, 'low' => 1].
     */
    public function isValidCombination(array $profiles, array $combination): bool
    {
        // Count how many of each profile are in the combined list.
        $counts = array_count_values($profiles);

        // Every profile in the combined list must appear in the combination
        // and must not exceed its allowed maximum.
        foreach ($counts as $profile => $count) {
            if (!isset($combination[$profile])) {
                // This profile is not mentioned in the combination at all.
                return false;
            }

            if ($count > $combination[$profile]) {
                // Exceeds the allowed maximum for this profile.
                return false;
            }
        }

        return true;
    }
}
