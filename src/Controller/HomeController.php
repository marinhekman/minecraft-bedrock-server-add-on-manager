<?php

namespace App\Controller;

use App\Service\AddonScanner;
use App\Service\ServerMetaReader;
use App\Service\ServerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry  $serverRegistry,
        private readonly AddonScanner    $addonScanner,
        private readonly ServerMetaReader $serverMetaReader,
    ) {}

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        $servers = $this->serverRegistry->getAll();

        $serverData = [];
        foreach ($servers as $server) {
            $packs       = $this->addonScanner->scan($server);
            $enabledPacks = array_filter($packs, fn($p) => !$p->isSystem && $p->enabled);
            $meta        = $this->serverMetaReader->read($server->dataPath);

            $serverData[] = [
                'server'       => $server,
                'meta'         => $meta,
                'enabledPacks' => array_values($enabledPacks),
            ];
        }

        return $this->render('home/index.html.twig', [
            'serverData' => $serverData,
        ]);
    }
}
