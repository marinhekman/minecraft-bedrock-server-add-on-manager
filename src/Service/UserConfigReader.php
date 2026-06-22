<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class UserConfigReader
{
    public function __construct(
        private readonly string $mcDataPath,
    ) {}

    /**
     * Read users from config/users.yaml and return as array.
     *
     * @return array<string, array{password?: string, gamertag: string, xuid: string, roles: array<string>}>
     */
    public function getUsers(): array
    {
        $usersFile = $this->mcDataPath . '/config/users.yaml';

        if (!file_exists($usersFile)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($usersFile) ?? [];
        } catch (\Exception) {
            return [];
        }

        return $data['users'] ?? [];
    }

    /**
     * Get all users with their xuid and gamertag.
     *
     * @return array<array{name: string, gamertag: string, xuid: string}>
     */
    public function getAllUsersForAllowlist(): array
    {
        $result = [];
        foreach ($this->getUsers() as $username => $userConfig) {
            $result[] = [
                'name'     => $userConfig['gamertag'] ?? $username,
                'xuid'     => (string) ($userConfig['xuid'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Get admin users for permissions.
     *
     * @return array<array{xuid: string}>
     */
    public function getAdminUsersForPermissions(): array
    {
        $result = [];
        foreach ($this->getUsers() as $userConfig) {
            $roles = $userConfig['roles'] ?? [];
            if (in_array('ROLE_ADMIN', $roles, true)) {
                $result[] = [
                    'xuid' => (string) ($userConfig['xuid'] ?? ''),
                ];
            }
        }
        return $result;
    }
}

