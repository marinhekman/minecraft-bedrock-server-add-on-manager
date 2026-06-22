<?php

namespace App\Service;

class ServerDefaultsPopulator
{
    public function __construct(
        private readonly UserConfigReader $userConfigReader,
    ) {}

    /**
     * Populate allowlist.json and permissions.json in a newly created server folder.
     * Only populates if the files are empty or contain empty arrays (default state).
     */
    public function populateDefaultsForNewServer(string $hostDataPath): void
    {
        $this->populateAllowlist($hostDataPath);
        $this->populatePermissions($hostDataPath);
    }

    private function populateAllowlist(string $hostDataPath): void
    {
        $allowlistPath = $hostDataPath . '/allowlist.json';

        if (file_exists($allowlistPath)) {
            return; // Already exists — don't overwrite
        }

        $allowlistData = [];
        foreach ($this->userConfigReader->getAllUsersForAllowlist() as $user) {
            if ($user['xuid'] !== '') {
                $allowlistData[] = [
                    'ignoresPlayerLimit' => false,
                    'name'               => $user['name'],
                    'xuid'               => $user['xuid'],
                ];
            }
        }

        $this->writeJsonFile($allowlistPath, $allowlistData);
    }

    private function populatePermissions(string $hostDataPath): void
    {
        $permissionsPath = $hostDataPath . '/permissions.json';

        if (file_exists($permissionsPath)) {
            return; // Already exists — don't overwrite
        }

        $permissionsData = [];
        foreach ($this->userConfigReader->getAdminUsersForPermissions() as $admin) {
            if ($admin['xuid'] !== '') {
                $permissionsData[] = [
                    'permission' => 'operator',
                    'xuid'       => $admin['xuid'],
                ];
            }
        }

        $this->writeJsonFile($permissionsPath, $permissionsData);
    }

    private function writeJsonFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException(sprintf('Failed to encode JSON for %s', basename($path)));
        }

        $written = @file_put_contents($path, $json . "\n");
        if ($written === false) {
            throw new \RuntimeException(sprintf('Failed to write %s', basename($path)));
        }
    }
}

