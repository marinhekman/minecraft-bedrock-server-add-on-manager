<?php

namespace App\Controller;

use App\Security\User;
use App\Service\VoteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class VoteController extends AbstractController
{
    public function __construct(
        private readonly VoteManager $voteManager,
    ) {}

    #[Route('/server/{serverName}/vote', name: 'server_vote', methods: ['POST'])]
    public function vote(string $serverName): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->voteManager->castVote($user, $serverName);

        return $this->redirectToRoute('home');
    }
}
