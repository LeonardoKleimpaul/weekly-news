<?php

namespace App\Controller;

use App\Repository\PresentationRepository;
use App\Repository\SubmissionRepository;
use App\Service\FridayCalendar;
use App\Service\PresentationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapDateTime;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[IsGranted('ROLE_USER')]
class PresentationController extends AbstractController
{
    public function __construct(
        private FridayCalendar $calendar,
        private PresentationManager $manager,
        private PresentationRepository $presentations,
        private SubmissionRepository $submissions,
    ) {
    }

    #[Route('/sextas/{friday}/apresentacao', name: 'app_presentation', requirements: ['friday' => '\d{4}-\d{2}-\d{2}'], methods: ['GET'])]
    public function room(#[MapDateTime(format: '!Y-m-d')] \DateTimeImmutable $friday): Response
    {
        return $this->roomResponse($friday);
    }

    #[Route('/sextas/{friday}/apresentacao/estado', name: 'app_presentation_state', requirements: ['friday' => '\d{4}-\d{2}-\d{2}'], methods: ['GET'])]
    public function state(#[MapDateTime(format: '!Y-m-d')] \DateTimeImmutable $friday): Response
    {
        return $this->roomResponse($friday, fragment: true);
    }

    #[Route('/admin/sextas/{friday}/iniciar', name: 'admin_presentation_start', requirements: ['friday' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function start(#[MapDateTime(format: '!Y-m-d')] \DateTimeImmutable $friday, Request $request): Response
    {
        $this->validateFriday($friday);
        $form = $this->startForm($friday);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->roomResponse($friday, actionForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->manager->start($friday, $this->getUser());
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_presentation', ['friday' => $friday->format('Y-m-d')], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/sextas/{friday}/avancar', name: 'admin_presentation_advance', requirements: ['friday' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function advance(#[MapDateTime(format: '!Y-m-d')] \DateTimeImmutable $friday, Request $request): Response
    {
        $this->validateFriday($friday);
        $form = $this->advanceForm($friday, 0);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->roomResponse($friday, actionForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->manager->advance($friday, (int) $form->get('position')->getData(), $this->getUser());
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_presentation', ['friday' => $friday->format('Y-m-d')], Response::HTTP_SEE_OTHER);
    }

    private function roomResponse(\DateTimeImmutable $friday, bool $fragment = false, ?FormInterface $actionForm = null, int $status = Response::HTTP_OK): Response
    {
        $this->validateFriday($friday);
        $presentation = $this->presentations->findOneBy(['friday' => $friday]);
        $readiness = null === $presentation ? $this->manager->readiness($friday) : null;
        $current = null !== $presentation?->getCurrentSubmissionId() ? $this->submissions->find($presentation->getCurrentSubmissionId()) : null;
        $canStart = null !== $readiness && $readiness['ready'] && $this->manager->canStartOn($friday);
        if (null === $actionForm && $this->isGranted('ROLE_ADMIN')) {
            $actionForm = null === $presentation ? $this->startForm($friday) : (!$presentation->isFinished() ? $this->advanceForm($friday, $presentation->getPosition()) : null);
        }
        $participants = array_map(static fn (array $entry) => [$entry['user']->getId(), $entry['user']->getName(), $entry['submitted']], $readiness['participants'] ?? []);
        $revision = hash('sha256', serialize([$presentation?->getId(), $presentation?->getPosition(), $participants, $canStart]));
        $response = $this->render($fragment ? 'presentation/_state.html.twig' : 'presentation/room.html.twig', [
            'friday' => $friday,
            'presentation' => $presentation,
            'readiness' => $readiness,
            'current' => $current,
            'can_start' => $canStart,
            'date_available' => $this->manager->canStartOn($friday),
            'action_form' => $actionForm?->createView(),
            'revision' => $revision,
        ], new Response(status: $status));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function startForm(\DateTimeImmutable $friday): FormInterface
    {
        return $this->createForm(FormType::class, null, [
            'action' => $this->generateUrl('admin_presentation_start', ['friday' => $friday->format('Y-m-d')]),
            'csrf_token_id' => 'presentation-start-'.$friday->format('Y-m-d'),
        ]);
    }

    private function advanceForm(\DateTimeImmutable $friday, int $position): FormInterface
    {
        return $this->createFormBuilder(null, [
            'action' => $this->generateUrl('admin_presentation_advance', ['friday' => $friday->format('Y-m-d')]),
            'csrf_token_id' => 'presentation-advance-'.$friday->format('Y-m-d'),
        ])->add('position', HiddenType::class, [
            'data' => (string) $position,
            'mapped' => false,
            'constraints' => [new Assert\NotBlank(), new Assert\Regex('/^\d+$/'), new Assert\Length(max: 10)],
        ])->getForm();
    }

    private function validateFriday(\DateTimeImmutable $friday): void
    {
        if (!$this->calendar->isFriday($friday)) {
            throw $this->createNotFoundException('Escolha uma sexta-feira válida.');
        }
    }
}
