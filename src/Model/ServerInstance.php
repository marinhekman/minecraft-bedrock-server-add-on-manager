<?php

namespace App\Model;

class ServerInstance
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $dataPath,
        public readonly ?string $containerId     = null,
        public readonly ?string $containerName   = null,
        public readonly ?string $containerStatus = null,
        public readonly ?int    $port            = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->containerStatus === 'running';
    }

    public function getBehaviourPacksPath(): string
    {
        return $this->dataPath . '/behavior_packs';
    }

    public function getResourcePacksPath(): string
    {
        return $this->dataPath . '/resource_packs';
    }

    public function getWorldBehaviourPacksFile(): string
    {
        return $this->dataPath . '/worlds/Bedrock level/world_behavior_packs.json';
    }

    public function getWorldResourcePacksFile(): string
    {
        return $this->dataPath . '/worlds/Bedrock level/world_resource_packs.json';
    }
}

