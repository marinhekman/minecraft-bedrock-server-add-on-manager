<?php

declare(strict_types=1);

namespace App\Service;

class ResourceBudgetChecker
{
    private const HIERARCHY = ['low', 'medium', 'high'];

    private const DEFAULT_LEVEL = 1; // medium

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
     * Returns a detailed, log-friendly decision path for canStart().
     *
     * @return array{
     *   allowed: bool,
     *   candidateProfile: string,
     *   runningProfiles: list<string>,
     *   resourceLimitsConfigured: bool,
     *   matchedSlotSetIndex: int|null,
     *   slotSetEvaluations: list<array{index:int,slotSet:array<string,int>,fits:bool,reason:string}>
     * }
     */
    public function explainCanStart(string $candidateProfile): array
    {
        $runningProfiles = $this->getRunningProfiles();
        $meta            = $this->globalMetaReader->read();

        if ($meta->resourceLimits === []) {
            return [
                'allowed'                => true,
                'candidateProfile'       => $candidateProfile,
                'runningProfiles'        => $runningProfiles,
                'resourceLimitsConfigured' => false,
                'matchedSlotSetIndex'    => null,
                'slotSetEvaluations'     => [],
            ];
        }

        $matchedSlotSetIndex = null;
        $slotSetEvaluations  = [];

        foreach ($meta->resourceLimits as $idx => $slotSet) {
            $fits = $this->fitsInSlotSet($runningProfiles, $candidateProfile, $slotSet);
            $slotSetEvaluations[] = [
                'index'   => $idx,
                'slotSet' => $slotSet,
                'fits'    => $fits,
                'reason'  => $fits
                    ? 'all running profiles and candidate fit this slot set'
                    : 'one or more running profiles or candidate do not fit this slot set',
            ];

            if ($fits) {
                $matchedSlotSetIndex = $idx;
                break;
            }
        }

        return [
            'allowed'                  => $matchedSlotSetIndex !== null,
            'candidateProfile'         => $candidateProfile,
            'runningProfiles'          => $runningProfiles,
            'resourceLimitsConfigured' => true,
            'matchedSlotSetIndex'      => $matchedSlotSetIndex,
            'slotSetEvaluations'       => $slotSetEvaluations,
        ];
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
            $profileLevel = self::DEFAULT_LEVEL;
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
