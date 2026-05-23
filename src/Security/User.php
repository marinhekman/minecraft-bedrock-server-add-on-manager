<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $gamertag,
        private readonly string $xuid,
        private readonly array  $roles,
    ) {}

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getGamertag(): string
    {
        return $this->gamertag;
    }

    public function getXuid(): string
    {
        return $this->xuid;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function getAvatarPath(): string
    {
        $path = '/avatars/' . strtolower($this->username) . '.png';
        $fullPath = __DIR__ . '/../../public' . $path;
        return file_exists($fullPath) ? $path : '/images/avatar_default.png';
    }

    public function eraseCredentials(): void {}
}
