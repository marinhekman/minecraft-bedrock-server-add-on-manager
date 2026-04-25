<?php

namespace App\Service;

use App\Model\AddonPack;

class DependencyChecker
{
    /**
     * Returns unmet UUID-based dependencies for a given pack.
     * Module-based dependencies (e.g. @minecraft/server) are ignored
     * as they are provided by Minecraft itself.
     *
     * @param AddonPack   $pack     The pack to check
     * @param AddonPack[] $allPacks All installed packs on the same server
     * @return array[]              Unmet dependency entries from manifest
     */
    public function getUnmetDependencies(AddonPack $pack, array $allPacks): array
    {
        $installedUuids = array_map(
            fn(AddonPack $p) => $p->manifest->uuid,
            $allPacks
        );

        return array_values(array_filter(
            $pack->manifest->dependencies,
            fn(array $dep) => isset($dep['uuid'])
                && !in_array($dep['uuid'], $installedUuids, true)
        ));
    }

    /**
     * Returns true if all UUID-based dependencies are met.
     */
    public function isSatisfied(AddonPack $pack, array $allPacks): bool
    {
        return empty($this->getUnmetDependencies($pack, $allPacks));
    }

    /**
     * Check dependencies after an install and return warning messages.
     * Ignores dependencies satisfied by packs installed in the same batch.
     *
     * @param AddonPack[] $newPacks        Newly installed packs
     * @param AddonPack[] $allPacks        All installed packs on the server
     * @param string[]    $newPackUuids    UUIDs of packs installed in this batch (excluded from warnings)
     * @return string[]
     */
    public function getInstallWarnings(array $newPacks, array $allPacks, array $newPackUuids = []): array
    {
        $warnings = [];

        foreach ($newPacks as $pack) {
            $unmet = $this->getUnmetDependencies($pack, $allPacks);
            foreach ($unmet as $dep) {
                // Skip if the missing dependency was installed in the same batch
                if (in_array($dep['uuid'], $newPackUuids, true)) {
                    continue;
                }
                $warnings[] = sprintf(
                    '"%s" requires dependency UUID %s which is not installed.',
                    $pack->manifest->name,
                    $dep['uuid']
                );
            }
        }

        return $warnings;
    }
}

