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
