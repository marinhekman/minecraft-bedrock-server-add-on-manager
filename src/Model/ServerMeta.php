<?php

namespace App\Model;

class ServerMeta
{
    public function __construct(
        public readonly ?string $displayName    = null,
        public readonly ?string $description    = null,
        public readonly ?string $imagePath      = null,
        public readonly ?int    $voteThreshold  = null,
        public readonly ?int    $voteCooldown   = null,
    ) {}

    /**
     * Returns the display name, falling back to the server folder name.
     */
    public function getDisplayName(string $fallback): string
    {
        return $this->displayName ?? $fallback;
    }

    /**
     * Returns true if a server image exists.
     */
    public function hasImage(): bool
    {
        return $this->imagePath !== null;
    }
}
