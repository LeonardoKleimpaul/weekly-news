<?php

namespace App\Controller;

use App\Service\RankingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class RankingController extends AbstractController
{
    #[Route('/ranking', name: 'app_ranking', methods: ['GET'])]
    public function index(RankingService $ranking): Response
    {
        $response = $this->render('ranking/index.html.twig', ['standings' => $ranking->standings()]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
