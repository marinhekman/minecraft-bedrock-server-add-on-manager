<?php

namespace App\Model;

enum AddonType: string
{
    case Behaviour = 'data';
    case Script    = 'script';
    case Resource  = 'resources';
}

class AddonManifest
{
    public function __construct(
        public readonly string    $uuid,
        public readonly string    $name,
        public readonly array     $version,
        public readonly AddonType $type,
        public readonly array     $dependencies = [],
    ) {}

    public function getVersionString(): string
    {
        return implode('.', $this->version);
    }
}
