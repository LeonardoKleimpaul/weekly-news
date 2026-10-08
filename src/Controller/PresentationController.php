<?php

namespace App\Controller;

use App\Entity\Presentation;
use App\Form\VoteType;
use App\Repository\PresentationRepository;
use App\Repository\SubmissionRepository;
use App\Service\FridayCalendar;
use App\Service\PresentationManager;
use App\Service\VotingManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        private VotingManager $voting,
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

    #[Route('/sextas/{friday}/apresentacao/rever/{position}', name: 'app_presentation_review', requirements: ['friday' => '\d{4}-\d{2}-\d{2}', 'position' => '[1-9]\d{0,8}'], methods: ['GET'])]
    public function review(#[MapDateTime(format: '!Y-m-d')] \DateTimeImmutable $friday, int $position = 1): Response
    {
        $this->validateFriday($friday);
        $presentation = $this->presentations->findOneBy(['friday' => $friday]);
        if (null === $presentation || !$presentation->isFinished()) {
            throw $this->createNotFoundException('A apresentação precisa terminar antes de ser revista.');
        }
        $order = $presentation->getSubmissionOrder();
        if ($position < 1 || $position > count($order)) {
            throw $this->createNotFoundException('Esta história não faz parte da apresentação.');
        }

        $response = $this->render('presentation/review.html.twig', [
            'friday' => $friday,
            'position' => $position,
            'total' => count($order),
            'current' => $this->submissions->find($order[$position - 1]),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
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

    #[Route('/admin/sextas/{friday}/votacao/abrir', name: 'admin_presentation_open_voting', requirements: ['friday' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function openVoting(#[MapDateTime(format: '!Y-m-d')] \DateTimeImmutable $friday, Request $request): Response
    {
        $presentation = $this->findPresentation($friday);
        $form = $this->votingActionForm($friday, 'open_voting');
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->roomResponse($friday, actionForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->voting->open($presentation, $this->getUser());
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_presentation', ['friday' => $friday->format('Y-m-d')], Response::HTTP_SEE_OTHER);
    }

    #[Route('/sextas/{friday}/votar', name: 'app_presentation_vote', requirements: ['friday' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    public function vote(#[MapDateTime(format: '!Y-m-d')] \DateTimeImmutable $friday, Request $request): Response
    {
        $presentation = $this->findPresentation($friday);
        $form = $this->voteForm($presentation);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->roomResponse($friday, voteForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->voting->vote($presentation, $this->getUser(), $form->get('candidate')->getData());
            $this->addFlash('success', 'Seu voto foi confirmado. Agora é só esperar a revelação!');
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', 'Seu voto já foi confirmado e não pode ser alterado.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_presentation', ['friday' => $friday->format('Y-m-d')], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/sextas/{friday}/resultado/revelar', name: 'admin_presentation_reveal', requirements: ['friday' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reveal(#[MapDateTime(format: '!Y-m-d')] \DateTimeImmutable $friday, Request $request): Response
    {
        $presentation = $this->findPresentation($friday);
        $form = $this->votingActionForm($friday, 'reveal');
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->roomResponse($friday, actionForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->voting->reveal($presentation, $this->getUser());
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_presentation', ['friday' => $friday->format('Y-m-d')], Response::HTTP_SEE_OTHER);
    }

    private function roomResponse(\DateTimeImmutable $friday, bool $fragment = false, ?FormInterface $actionForm = null, int $status = Response::HTTP_OK, ?FormInterface $voteForm = null): Response
    {
        $this->validateFriday($friday);
        $presentation = $this->presentations->findOneBy(['friday' => $friday]);
        $readiness = null === $presentation ? $this->manager->readiness($friday) : null;
        $current = null !== $presentation?->getCurrentSubmissionId() ? $this->submissions->find($presentation->getCurrentSubmissionId()) : null;
        $canStart = null !== $readiness && $readiness['ready'] && $this->manager->canStartOn($friday);
        $voting = null !== $presentation && $presentation->isFinished() ? $this->voting->status($presentation, $this->getUser()) : null;
        if (null === $actionForm && $this->isGranted('ROLE_ADMIN')) {
            $actionForm = null === $presentation ? $this->startForm($friday) : (!$presentation->isFinished() ? $this->advanceForm($friday, $presentation->getPosition()) : null);
            if (null !== $presentation && $presentation->isFinished() && !$presentation->isClosed()) {
                if (null === $presentation->getVotingOpenedAt()) {
                    $actionForm = $this->votingActionForm($friday, 'open_voting');
                } elseif ($voting['complete']) {
                    $actionForm = $this->votingActionForm($friday, 'reveal');
                }
            }
        }
        if (null === $voteForm && null !== $voting && $voting['eligible'] && !$voting['voted'] && null !== $presentation->getVotingOpenedAt() && !$presentation->isClosed()) {
            $voteForm = $this->voteForm($presentation);
        }
        $participants = array_map(static fn (array $entry) => [$entry['user']->getId(), $entry['user']->getName(), $entry['submitted']], $readiness['participants'] ?? []);
        $revision = hash('sha256', serialize([$presentation?->getId(), $presentation?->getPosition(), $participants, $canStart, $presentation?->getVotingOpenedAt(), $presentation?->getClosedAt(), $voting]));
        $response = $this->render($fragment ? 'presentation/_state.html.twig' : 'presentation/room.html.twig', [
            'friday' => $friday,
            'presentation' => $presentation,
            'readiness' => $readiness,
            'current' => $current,
            'can_start' => $canStart,
            'date_available' => $this->manager->canStartOn($friday),
            'action_form' => $actionForm?->createView(),
            'revision' => $revision,
            'voting' => $voting,
            'vote_form' => $voteForm?->createView(),
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

    private function votingActionForm(\DateTimeImmutable $friday, string $action): FormInterface
    {
        return $this->createForm(FormType::class, null, [
            'action' => $this->generateUrl('admin_presentation_'.$action, ['friday' => $friday->format('Y-m-d')]),
            'csrf_token_id' => 'presentation-'.$action.'-'.$friday->format('Y-m-d'),
        ]);
    }

    private function voteForm(Presentation $presentation): FormInterface
    {
        return $this->createForm(VoteType::class, null, [
            'ballot' => array_values(array_filter($presentation->getBallot(), fn (array $participant) => $participant['user_id'] !== $this->getUser()->getId())),
            'action' => $this->generateUrl('app_presentation_vote', ['friday' => $presentation->getFriday()->format('Y-m-d')]),
            'csrf_token_id' => 'vote-'.$presentation->getId(),
        ]);
    }

    private function findPresentation(\DateTimeImmutable $friday): Presentation
    {
        $this->validateFriday($friday);

        return $this->presentations->findOneBy(['friday' => $friday]) ?? throw $this->createNotFoundException('Esta apresentação ainda não foi iniciada.');
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
