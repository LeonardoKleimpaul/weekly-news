<?php

namespace App\Controller;

use App\Repository\SubmissionRepository;
use App\Repository\PresentationRepository;
use App\Service\FridayCalendar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/ano/{year}', name: 'app_home_year', requirements: ['year' => '\d{4}'], methods: ['GET'])]
    public function index(FridayCalendar $calendar, SubmissionRepository $submissions, PresentationRepository $presentations, ?int $year = null): Response
    {
        $year ??= (int) $calendar->today()->format('Y');
        if ($year < FridayCalendar::MIN_YEAR || $year > FridayCalendar::MAX_YEAR) {
            throw $this->createNotFoundException();
        }
        $fridays = $calendar->fridays($year);
        $months = [];
        foreach ($fridays as $friday) {
            $months[(int) $friday->format('n')][] = $friday;
        }
        $mySubmissions = $submissions->findForYear($this->getUser(), $year);
        $upcoming = $calendar->upcomingFriday();
        $upcomingSubmission = $mySubmissions[$upcoming->format('Y-m-d')] ?? null;
        if ($year === (int) $calendar->today()->format('Y') && $year !== (int) $upcoming->format('Y')) {
            $upcomingSubmission = $submissions->findOneBy(['author' => $this->getUser(), 'friday' => $upcoming]);
        }

        return $this->render('home/index.html.twig', [
            'year' => $year,
            'months' => $months,
            'month_names' => FridayCalendar::MONTHS,
            'submissions' => $mySubmissions,
            'presentations' => $presentations->findForYear($year),
            'friday_count' => count($fridays),
            'today' => $calendar->today(),
            'upcoming' => $upcoming,
            'upcoming_submission' => $upcomingSubmission,
            'min_year' => FridayCalendar::MIN_YEAR,
            'max_year' => FridayCalendar::MAX_YEAR,
        ]);
    }
}
