<?php

namespace App\Model;

class AddonPack
{
    public function __construct(
        public readonly AddonManifest $manifest,
        public readonly string        $path,
        public readonly bool          $enabled,
    ) {}

    public function getFolderName(): string
    {
        return basename($this->path);
    }
}

