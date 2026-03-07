<?php

namespace App\Controller;

use App\Service\AddonInstaller;
use App\Service\AddonScanner;
use App\Service\ServerRegistry;
use App\Service\WorldPacksManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/server/{serverName}/addon')]
class AddonController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry    $serverRegistry,
        private readonly AddonScanner      $addonScanner,
        private readonly WorldPacksManager $worldPacksManager,
        private readonly AddonInstaller    $addonInstaller,
    ) {}

    #[Route('/install', name: 'addon_install', methods: ['POST'])]
    public function install(string $serverName, Request $request): RedirectResponse
    {
        $server = $this->serverRegistry->get($serverName);
        if ($server === null) {
            $this->addFlash('error', sprintf('Server "%s" not found.', $serverName));
            return $this->redirectToRoute('dashboard');
        }

        $file = $request->files->get('addon_file');
        if ($file === null) {
            $this->addFlash('error', 'No file was uploaded.');
            return $this->redirectToRoute('dashboard');
        }

        try {
            $installed = $this->addonInstaller->install(
                $server,
                $file->getPathname(),
                $file->getClientOriginalName(),
            );

            foreach ($installed as $name) {
                $this->addFlash('success', sprintf('"%s" has been installed.', $name));
            }
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/{uuid}/enable', name: 'addon_enable', methods: ['POST'])]
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

    #[Route('/{uuid}/disable', name: 'addon_disable', methods: ['POST'])]
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

    #[Route('/{uuid}/delete', name: 'addon_delete', methods: ['POST'])]
    public function delete(string $serverName, string $uuid): RedirectResponse
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

        // Disable first to clean up world_*_packs.json
        $this->worldPacksManager->disable($server, $pack->manifest->type, $uuid);

        // Remove the pack folder
        $this->removeDirectory($pack->path);

        $this->addFlash('success', sprintf('"%s" has been removed.', $pack->manifest->name));

        return $this->redirectToRoute('dashboard');
    }

    private function findPack(\App\Model\ServerInstance $server, string $uuid): ?\App\Model\AddonPack
    {
        foreach ($this->addonScanner->scan($server) as $pack) {
            if ($pack->manifest->uuid === $uuid) {
                return $pack;
            }
        }

        return null;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
