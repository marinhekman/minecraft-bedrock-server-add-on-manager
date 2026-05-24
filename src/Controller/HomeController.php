<?php

namespace App\Controller;

use App\Service\AddonScanner;
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
    ) {}

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        $servers  = $this->serverRegistry->getAll();
        $ranking  = $this->voteManager->getVoteRanking();
        $rankMap  = array_column($ranking, null, 'name');

        // Current user's gamertag for userHasVoted detection in template
        $myGamertag = null;
        if ($this->getUser()) {
            /** @var \App\Security\User $user */
            $user = $this->getUser();
            $myGamertag = $user->getGamertag();
        }

        $serverData = [];
        foreach ($servers as $server) {
            $packs        = $this->addonScanner->scan($server);
            $enabledPacks = array_filter($packs, fn($p) => !$p->isSystem && $p->enabled);
            $meta         = $this->serverMetaReader->read($server->dataPath);

            $voters    = $this->voteManager->getActiveVoters($server->name);
            $threshold = $this->voteManager->getThreshold($server->name);
            $rank      = $rankMap[$server->name]['activeVotes'] ?? null;

            $serverData[] = [
                'server'         => $server,
                'meta'           => $meta,
                'enabledPacks'   => array_values($enabledPacks),
                'voteCount'      => $this->voteManager->getActiveVoteCount($server->name),
                'voteThreshold'  => $threshold,
                'voters'         => $voters,
                'userHasVoted'   => $myGamertag !== null && in_array(
                    $myGamertag,
                    array_column($voters, 'gamertag'),
                    true,
                ),
                'blockingReason' => $this->voteManager->getBlockingReason($server->name),
                'blockingDetail' => $this->voteManager->getBlockingDetail($server->name),
                'cooldown'       => $this->voteManager->getCooldown($server->name),
                'graceUntil'     => $this->voteManager->getGraceUntil($server->name),
                'graceTotal' => $this->voteManager->getGlobalMeta()->serverEmptyGrace
            ];
        }

        // Sort by active vote count descending, ties alphabetically — same as getVoteRanking
        usort($serverData, function (array $a, array $b): int {
            if ($a['voteCount'] !== $b['voteCount']) {
                return $b['voteCount'] <=> $a['voteCount'];
            }
            return $a['server']->name <=> $b['server']->name;
        });

        return $this->render('home/index.html.twig', [
            'serverData'  => $serverData,
            'myGamertag'  => $myGamertag,
        ]);
    }
}
