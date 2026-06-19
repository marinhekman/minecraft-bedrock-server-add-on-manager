<?php

declare(strict_types=1);

namespace App\Service;

class ResourceBudgetChecker
{
    private const HIERARCHY = ['low', 'medium', 'high'];

    public function __construct(
        private readonly RedisClient      $redis,
        private readonly GlobalMetaReader $globalMetaReader,
    ) {}

    public function canStart(string $candidateProfile): bool
    {
        return $this->canStartWithProfiles(
            $candidateProfile,
            $this->getRunningProfiles(),
        );
    }

    /**
     * Same as canStart() but with an explicit running profiles list,
     * used for simulation (e.g. after hypothetically stopping servers).
     *
     * @param list<string> $runningProfiles
     */
    public function canStartWithProfiles(string $candidateProfile, array $runningProfiles): bool
    {
        $meta = $this->globalMetaReader->read();

        if ($meta->resourceLimits === []) {
            return true;
        }

        foreach ($meta->resourceLimits as $slotSet) {
            if ($this->fitsInSlotSet($runningProfiles, $candidateProfile, $slotSet)) {
                return true;
            }
        }

        return false;
    }

    /**
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
     * @param list<string>       $runningProfiles
     * @param array<string, int> $slotSet
     */
    public function fitsInSlotSet(
        array  $runningProfiles,
        string $candidateProfile,
        array  $slotSet,
    ): bool {
        $slots = $this->buildSlots($slotSet);

        foreach ($runningProfiles as $profile) {
            $slotIndex = $this->findSlot($slots, $profile);
            if ($slotIndex === null) {
                return false;
            }
            unset($slots[$slotIndex]);
        }

        return $this->findSlot($slots, $candidateProfile) !== null;
    }

    /**
     * @param array<string, int> $slotSet
     * @return array<int, string>
     */
    private function buildSlots(array $slotSet): array
    {
        $slots = [];

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
     * @param array<int, string> $slots
     */
    private function findSlot(array $slots, string $profile): ?int
    {
        $profileLevel = array_search($profile, self::HIERARCHY, true);

        if ($profileLevel === false) {
            $profileLevel = array_search('medium', self::HIERARCHY, true);
        }

        foreach ($slots as $index => $slotType) {
            $slotLevel = array_search($slotType, self::HIERARCHY, true);
            if ($slotLevel >= $profileLevel) {
                return $index;
            }
        }

        return null;
    }
}
