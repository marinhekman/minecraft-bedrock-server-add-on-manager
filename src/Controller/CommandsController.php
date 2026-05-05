<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class CommandsController extends AbstractController
{
    private const COMMANDS_FILE = '/mc-data/commands.txt';

    #[Route('/commands', name: 'commands', methods: ['GET'])]
    public function index(): JsonResponse
    {
        if (!file_exists(self::COMMANDS_FILE)) {
            return $this->json([]);
        }

        $lines = file(self::COMMANDS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $commands = array_values(array_filter(
            array_map('trim', $lines),
            fn($line) => !str_starts_with($line, '#') // allow # comments in the file
        ));

        return $this->json($commands);
    }
}
