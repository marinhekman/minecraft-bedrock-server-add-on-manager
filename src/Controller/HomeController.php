<?php

namespace App\Controller;

use App\Service\AddonScanner;
use App\Service\RedisClient;
use App\Service\ResourceBudgetChecker;
use App\Service\ServerMetaReader;
use App\Service\ServerRegistry;
use App\Service\VoteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry   $serverRegistry,
        private readonly AddonScanner     $addonScanner,
        private readonly ServerMetaReader $serverMetaReader,
        private readonly VoteManager      $voteManager,
        private readonly RedisClient      $redis,
        private readonly ResourceBudgetChecker $budgetChecker
    ) {}

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        $servers = $this->serverRegistry->getAll();

        $myGamertag = null;
        if ($this->getUser()) {
            /** @var \App\Security\User $user */
            $user       = $this->getUser();
            $myGamertag = $user->getGamertag();
        }

        // Register presence so vote state is accurate on page render.
        if ($myGamertag !== null) {
            $this->redis->setHeartbeat($myGamertag);
            $this->redis->setGamertagUsername($myGamertag, $user->getUserIdentifier());
        }

        $serverData = [];
        foreach ($servers as $server) {
            $packs        = $this->addonScanner->scan($server);
            $enabledPacks = array_filter($packs, fn($p) => !$p->isSystem && $p->enabled);
            $meta         = $this->serverMetaReader->read($server->dataPath);
            $voters       = $this->voteManager->getActiveVoters($server->name);

            $serverData[] = [
                'server'       => $server,
                'meta'         => $meta,
                'enabledPacks' => array_values($enabledPacks),
                'voteCount'    => $this->voteManager->getActiveVoteCount($server->name),
                'voters'       => $voters,
                'userHasVoted' => $myGamertag !== null && in_array(
                    $myGamertag,
                    array_column($voters, 'gamertag'),
                    true,
                ),
                'blocked' => $this->getBlockingReason($server->name, $server->memoryProfile),
            ];
        }

        // Sort by active vote count descending, ties alphabetically
        usort($serverData, function (array $a, array $b): int {
            if ($a['voteCount'] !== $b['voteCount']) {
                return $b['voteCount'] <=> $a['voteCount'];
            }
            return $a['server']->name <=> $b['server']->name;
        });

        return $this->render('home/index.html.twig', [
            'serverData' => $serverData,
            'myGamertag' => $myGamertag,
        ]);
    }

    private function getBlockingReason(string $serverName): ?string
    {
        return $this->voteManager->getBlockingReason($serverName);
    }
}
