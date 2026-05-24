<?php

declare(strict_types=1);

namespace App\Controller\Dev;

use App\Dev\TestStateSeeder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only controller for seeding test scenarios.
 * Registered via routes.yaml under when@dev only.
 */
class TestStateController extends AbstractController
{
    public function __construct(
        private readonly TestStateSeeder $seeder,
    ) {}

    #[Route('/dev/seed/{scenario}', name: 'dev_seed')]
    public function seed(string $scenario): RedirectResponse
    {
        match ($scenario) {
            'two-servers-voting'    => $this->twoServersVoting(),
            'one-running-one-voted' => $this->oneRunningOneVoted(),
            'grace-period'          => $this->gracePeriod(),
            'grace-expired-stopping'=> $this->graceExpiredStopping(),
            'resource-blocked'      => $this->resourceBlocked(),
            'cooldown'              => $this->cooldown(),
            'multi-server-ranking'  => $this->multiServerRanking(),
            'anonymous'             => $this->anonymous(),
            'reset'                 => $this->seeder->reset(),
            default                 => null,
        };

        return $this->redirectToRoute('home');
    }

    private function twoServersVoting(): void
    {
        $this->seeder->reset();
        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server2');
    }

    private function oneRunningOneVoted(): void
    {
        $this->seeder->reset();
        $this->seeder->seedServer('server1', running: true, memoryProfile: 'medium', players: 3);
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server2');
        $this->seeder->seedVote('Player3', 'server2');
    }

    private function gracePeriod(): void
    {
        $this->seeder->reset();
        $this->seeder->seedServer('server1', running: true, memoryProfile: 'medium', players: 0, graceUntil: time() + 45);
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server2');
        $this->seeder->seedVote('Player3', 'server2');
    }

    private function graceExpiredStopping(): void
    {
        $this->seeder->reset();
        // Grace has expired: no grace key, container still reports running
        $this->seeder->seedServer('server1', running: true, memoryProfile: 'medium', players: 0);
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server2');
        $this->seeder->seedVote('Player3', 'server2');
    }

    private function resourceBlocked(): void
    {
        $this->seeder->reset();
        $this->seeder->seedServer('server1', running: true,  memoryProfile: 'high',   players: 2);
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server2');
    }

    private function cooldown(): void
    {
        $this->seeder->reset();
        $this->seeder->seedServer('server1', running: true,  memoryProfile: 'medium', players: 0, cooldown: true, cooldownTtl: 240);
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedVote('Player1', 'server2');
    }

    private function multiServerRanking(): void
    {
        $this->seeder->reset();
        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedServer('server3', running: false, memoryProfile: 'low');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server2');
        $this->seeder->seedVote('Player3', 'server2');
        $this->seeder->seedHeartbeat('Player4');
        $this->seeder->seedHeartbeat('Player5');
        $this->seeder->seedVote('Player4', 'server3');
        $this->seeder->seedVote('Player5', 'server3');
        $this->seeder->seedHeartbeat('Player6');
        $this->seeder->seedVote('Player6', 'server1');
    }

    private function anonymous(): void
    {
        $this->seeder->reset();
        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        // Votes exist in Redis but no heartbeats — all inactive
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server2');
    }
}
