<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'set_locale', methods: ['GET'])]
    public function setLocale(string $locale, Request $request): Response
    {
        if (!in_array($locale, ['en', 'nl'], true)) {
            $locale = 'en';
        }

        $request->getSession()->set('_locale', $locale);

        $referer = $request->headers->get('referer', $this->generateUrl('home'));
        return $this->redirect($referer);
    }
}
