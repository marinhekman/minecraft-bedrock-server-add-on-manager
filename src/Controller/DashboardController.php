<?php

namespace App\Controller;

use App\Service\AddonScanner;
use App\Service\ServerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry $serverRegistry,
        private readonly AddonScanner   $addonScanner,
    ) {}

    #[Route('/', name: 'dashboard')]
    public function index(): Response
    {
        $servers = $this->serverRegistry->getAll();

        $serverData = [];
        foreach ($servers as $server) {
            $packs = $this->addonScanner->scan($server);

            $serverData[] = [
                'server' => $server,
                'packs'  => $packs,
                'enabledCount'  => count(array_filter($packs, fn($p) => $p->enabled)),
                'disabledCount' => count(array_filter($packs, fn($p) => !$p->enabled)),
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'serverData' => $serverData,
        ]);
    }
}

