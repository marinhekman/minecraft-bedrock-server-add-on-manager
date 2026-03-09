<?php

namespace App\Controller;

use App\Service\AddonScanner;
use App\Service\DependencyChecker;
use App\Service\PackLoadChecker;
use App\Service\ServerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry    $serverRegistry,
        private readonly AddonScanner      $addonScanner,
        private readonly DependencyChecker $dependencyChecker,
        private readonly PackLoadChecker   $packLoadChecker,
    ) {}

    #[Route('/', name: 'dashboard')]
    public function index(): Response
    {
        $servers = $this->serverRegistry->getAll();

        $serverData = [];
        foreach ($servers as $server) {
            $packs       = $this->addonScanner->scan($server);
            $userPacks   = array_filter($packs, fn($p) => !$p->isSystem);
            $systemPacks = array_filter($packs, fn($p) => $p->isSystem);
            $loadedUuids = $this->packLoadChecker->getLoadedUuids($server, $packs);

            $packsWithDeps = array_map(function ($pack) use ($packs, $loadedUuids) {
                $loaded = in_array($pack->manifest->uuid, $loadedUuids, true);
                $status = match(true) {
                    $loaded        => 'loaded',
                    $pack->enabled => 'enabled',
                    default        => 'disabled',
                };
                return [
                    'pack'          => $pack,
                    'unmetDeps'     => $this->dependencyChecker->getUnmetDependencies($pack, $packs),
                    'depsSatisfied' => $this->dependencyChecker->isSatisfied($pack, $packs),
                    'status'        => $status,
                ];
            }, $userPacks);

            $serverData[] = [
                'server'        => $server,
                'packs'         => array_values($packsWithDeps),
                'systemPacks'   => array_values($systemPacks),
                'enabledCount'  => count(array_filter($userPacks, fn($p) => $p->enabled)),
                'disabledCount' => count(array_filter($userPacks, fn($p) => !$p->enabled)),
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'serverData' => $serverData,
        ]);
    }
}
