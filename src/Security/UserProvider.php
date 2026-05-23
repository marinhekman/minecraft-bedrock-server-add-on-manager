<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Yaml\Yaml;

class UserProvider implements UserProviderInterface
{
    private const USERS_FILE = '/mc-data/config/users.yaml';

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $users = $this->loadUsers();

        if (!isset($users[$identifier])) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return $this->buildUser($identifier, $users[$identifier]);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', get_class($user)));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }

    private function loadUsers(): array
    {
        if (!file_exists(self::USERS_FILE)) {
            return [];
        }

        $data = Yaml::parseFile(self::USERS_FILE);

        return $data['users'] ?? [];
    }

    private function buildUser(string $username, array $data): User
    {
        return new User(
            username: $username,
            password: $data['password'],
            gamertag: $data['gamertag'] ?? $username,
            xuid:     $data['xuid'] ?? '',
            roles:    $data['roles'] ?? ['ROLE_USER'],
        );
    }
}
