<?php

namespace App\Model;

class ServerMeta
{
    public function __construct(
        public readonly ?string $displayName  = null,
        public readonly ?string $description  = null,
        public readonly ?string $imagePath    = null,
        public readonly ?int    $heartbeatTtl = null,
    ) {}

    public function getDisplayName(string $fallback): string
    {
        return $this->displayName ?? $fallback;
    }

    public function hasImage(): bool
    {
        return $this->imagePath !== null;
    }
}
