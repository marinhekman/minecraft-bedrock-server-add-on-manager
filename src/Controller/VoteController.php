<?php

namespace App\Controller;

use App\Security\User;
use App\Service\RedisClient;
use App\Service\VoteManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class VoteController extends AbstractController
{
    public function __construct(
        private readonly VoteManager       $voteManager,
        private readonly RedisClient       $redis,
        private readonly LoggerInterface   $logger,
    ) {}

    #[Route('/server/{serverName}/vote', name: 'server_vote', methods: ['POST'])]
    public function vote(string $serverName, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('vote-' . $serverName, $request->request->get('_token'))) {
            $this->logger->warning('Invalid CSRF token on vote', ['server' => $serverName, 'user' => $this->getUser()?->getUserIdentifier()]);
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();

        $this->voteManager->castVote($user, $serverName);

        // Check if this vote should trigger a countdown
        $candidate = $this->voteManager->checkAndTrigger();
        if ($candidate !== null && !$this->redis->getCountdown($candidate)) {
            // If a countdown should start and isn't already set, set it now in Redis
            // This ensures the countdown is visible to clients via polling or when the Monitor broadcasts
            // The Monitor will schedule the actual timer on its next evaluation cycle
            $this->redis->setCountdown($candidate);
            $this->logger->info('Countdown triggered immediately by vote, set in Redis', ['server' => $candidate]);
        }

        return $this->redirectToRoute('home');
    }
}
