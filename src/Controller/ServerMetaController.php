<?php

namespace App\Controller;

use App\Service\ServerMetaWriter;
use App\Service\ServerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/server/{serverName}/meta')]
class ServerMetaController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry   $serverRegistry,
        private readonly ServerMetaWriter $metaWriter,
    ) {}

    #[Route('/name', name: 'server_meta_name', methods: ['POST'])]
    public function name(string $serverName, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $server = $this->serverRegistry->get($serverName);
        if ($server === null) {
            return $this->json(['success' => false, 'error' => 'Server not found.'], 404);
        }

        $displayName = trim($request->request->get('display_name', ''));
        if ($displayName === '') {
            return $this->json(['success' => false, 'error' => 'Display name cannot be empty.'], 422);
        }

        try {
            $this->metaWriter->writeName($server->dataPath, $displayName);
            return $this->json(['success' => true, 'display_name' => $displayName]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/description', name: 'server_meta_description', methods: ['POST'])]
    public function description(string $serverName, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $server = $this->serverRegistry->get($serverName);
        if ($server === null) {
            return $this->json(['success' => false, 'error' => 'Server not found.'], 404);
        }

        $description = trim($request->request->get('description', ''));

        try {
            $this->metaWriter->writeDescription($server->dataPath, $description);
            return $this->json(['success' => true, 'description' => $description]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/image', name: 'server_meta_image', methods: ['POST'])]
    public function image(string $serverName, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $server = $this->serverRegistry->get($serverName);
        if ($server === null) {
            return $this->json(['success' => false, 'error' => 'Server not found.'], 404);
        }

        $file = $request->files->get('image');
        if ($file === null) {
            return $this->json(['success' => false, 'error' => 'No image uploaded.'], 422);
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        try {
            $mimeType = $file->getMimeType();
        } catch (\LogicException) {
            // Temporary fallback if symfony/mime is not installed yet in runtime.
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file->getPathname());
        }

        if (!in_array($mimeType, $allowedMimes, true)) {
            return $this->json(['success' => false, 'error' => 'Only JPEG, PNG, GIF or WebP images are allowed.'], 422);
        }

        try {
            $this->metaWriter->writeImage($server->dataPath, $file->getPathname(), $mimeType);
            return $this->json(['success' => true, 'imageUrl' => '/server/' . $serverName . '/image?' . time()]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

