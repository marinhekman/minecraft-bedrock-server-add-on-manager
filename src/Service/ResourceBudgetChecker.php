<?php

declare(strict_types=1);

namespace App\Service;

class ResourceBudgetChecker
{
    /**
     * Profile hierarchy — lower index = lower resource requirement.
     * A server with profile X can occupy any slot at level X or higher.
     */
    private const HIERARCHY = ['low', 'medium', 'high'];

    public function __construct(
        private readonly RedisClient      $redis,
        private readonly GlobalMetaReader $globalMetaReader,
    ) {}

    /**
     * Returns true if a server with $candidateProfile can be started
     * given the currently running servers and the configured slot sets.
     */
    public function canStart(string $candidateProfile): bool
    {
        $meta = $this->globalMetaReader->read();

        if ($meta->resourceLimits === []) {
            return true;
        }

        $running = $this->getRunningProfiles();

        foreach ($meta->resourceLimits as $slotSet) {
            if ($this->fitsInSlotSet($running, $candidateProfile, $slotSet)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the memory profiles of all currently running servers.
     *
     * @return list<string>
     */
    public function getRunningProfiles(): array
    {
        $profiles = [];

        foreach ($this->redis->getAllServerNames() as $name) {
            $data = $this->redis->getServer($name);

            if ($data === null || !($data['running'] ?? false)) {
                continue;
            }

            $profiles[] = $data['memoryProfile'] ?? 'medium';
        }

        return $profiles;
    }

    /**
     * Returns true if all running servers plus the candidate can be assigned
     * to slots in the given slot set.
     *
     * Algorithm:
     * 1. Build the available slots pool from the slot set definition.
     * 2. Assign running servers first (lowest-fit-first: prefer the lowest
     *    slot type that can accommodate the server's profile).
     * 3. If all running servers are assigned, try to assign the candidate.
     * 4. Return true only if both steps succeed.
     *
     * A server with profile X can occupy a slot of type X or any higher type
     * (low < medium < high). It prefers the lowest available matching slot.
     *
     * @param list<string>       $runningProfiles
     * @param array<string, int> $slotSet  e.g. ['high' => 1, 'low' => 1]
     */
    public function fitsInSlotSet(
        array  $runningProfiles,
        string $candidateProfile,
        array  $slotSet,
    ): bool {
        // Build flat list of available slots, sorted low→high so lowest-fit
        // assignment naturally picks the lowest available slot.
        $slots = $this->buildSlots($slotSet);

        // Assign running servers first
        foreach ($runningProfiles as $profile) {
            $slotIndex = $this->findSlot($slots, $profile);
            if ($slotIndex === null) {
                return false; // running servers don't fit — slot set invalid
            }
            unset($slots[$slotIndex]);
        }

        // Try to assign the candidate
        $slotIndex = $this->findSlot($slots, $candidateProfile);
        return $slotIndex !== null;
    }

    /**
     * Builds a flat sorted list of slot type names from a slot set definition.
     * e.g. ['high' => 1, 'low' => 2] → ['low', 'low', 'high']
     *
     * @param array<string, int> $slotSet
     * @return array<int, string>
     */
    private function buildSlots(array $slotSet): array
    {
        $slots = [];

        // Add slots in hierarchy order so low slots come first
        foreach (self::HIERARCHY as $type) {
            if (isset($slotSet[$type])) {
                for ($i = 0; $i < $slotSet[$type]; $i++) {
                    $slots[] = $type;
                }
            }
        }

        return $slots;
    }

    /**
     * Finds the index of the lowest available slot that can accommodate
     * a server with the given profile.
     *
     * A slot can accommodate a server if the slot type is >= the server profile
     * in the hierarchy (low can go into medium or high slots too).
     *
     * @param array<int, string> $slots
     */
    private function findSlot(array $slots, string $profile): ?int
    {
        $profileLevel = array_search($profile, self::HIERARCHY, true);

        if ($profileLevel === false) {
            // Unknown profile — treat as medium
            $profileLevel = array_search('medium', self::HIERARCHY, true);
        }

        // Slots are already sorted low→high, so the first match is the lowest fit
        foreach ($slots as $index => $slotType) {
            $slotLevel = array_search($slotType, self::HIERARCHY, true);
            if ($slotLevel >= $profileLevel) {
                return $index;
            }
        }

        return null;
    }
}
