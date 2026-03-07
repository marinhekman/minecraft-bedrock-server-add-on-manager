<?php

namespace App\Controller;

use App\Service\AddonScanner;
use App\Service\ServerRegistry;
use App\Service\WorldPacksManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/server/{serverName}/addon/{uuid}')]
class AddonController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry    $serverRegistry,
        private readonly AddonScanner      $addonScanner,
        private readonly WorldPacksManager $worldPacksManager,
    ) {}

    #[Route('/enable', name: 'addon_enable', methods: ['POST'])]
    public function enable(string $serverName, string $uuid): RedirectResponse
    {
        $server = $this->serverRegistry->get($serverName);
        if ($server === null) {
            $this->addFlash('error', sprintf('Server "%s" not found.', $serverName));
            return $this->redirectToRoute('dashboard');
        }

        $pack = $this->findPack($server, $uuid);
        if ($pack === null) {
            $this->addFlash('error', sprintf('Add-on "%s" not found.', $uuid));
            return $this->redirectToRoute('dashboard');
        }

        $this->worldPacksManager->enable(
            $server,
            $pack->manifest->type,
            $pack->manifest->uuid,
            $pack->manifest->version,
        );

        $this->addFlash('success', sprintf('"%s" has been enabled.', $pack->manifest->name));

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/disable', name: 'addon_disable', methods: ['POST'])]
    public function disable(string $serverName, string $uuid): RedirectResponse
    {
        $server = $this->serverRegistry->get($serverName);
        if ($server === null) {
            $this->addFlash('error', sprintf('Server "%s" not found.', $serverName));
            return $this->redirectToRoute('dashboard');
        }

        $pack = $this->findPack($server, $uuid);
        if ($pack === null) {
            $this->addFlash('error', sprintf('Add-on "%s" not found.', $uuid));
            return $this->redirectToRoute('dashboard');
        }

        $this->worldPacksManager->disable(
            $server,
            $pack->manifest->type,
            $pack->manifest->uuid,
        );

        $this->addFlash('success', sprintf('"%s" has been disabled.', $pack->manifest->name));

        return $this->redirectToRoute('dashboard');
    }

    private function findPack(mixed $server, string $uuid): mixed
    {
        foreach ($this->addonScanner->scan($server) as $pack) {
            if ($pack->manifest->uuid === $uuid) {
                return $pack;
            }
        }

        return null;
    }
}
