<?php

namespace App\Controller;

use App\Entity\Submission;
use App\Form\SubmissionType;
use App\Repository\SubmissionRepository;
use App\Repository\PresentationRepository;
use App\Service\FridayCalendar;
use App\Service\SubmissionManager;
use App\Service\SubmissionPhotoStorage;
use App\Service\PresentationManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapDateTime;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class SubmissionController extends AbstractController
{
    #[Route('/sextas/{friday}', name: 'app_submission', requirements: ['friday' => '\d{4}-\d{2}-\d{2}'], methods: ['GET', 'POST'])]
    public function edit(
        #[MapDateTime(format: '!Y-m-d')] \DateTimeImmutable $friday,
        Request $request,
        FridayCalendar $calendar,
        SubmissionRepository $submissions,
        SubmissionManager $manager,
        PresentationRepository $presentations,
    ): Response {
        if (!$calendar->isFriday($friday)) {
            throw $this->createNotFoundException('Escolha uma sexta-feira válida.');
        }
        $submission = $submissions->findOneBy(['author' => $this->getUser(), 'friday' => $friday]);
        $presentation = $presentations->findOneBy(['friday' => $friday]);
        $isOpen = null === $presentation && $calendar->canSubmit($friday);
        if (!$isOpen && $request->isMethod('POST')) {
            throw $this->createAccessDeniedException(null !== $presentation ? 'A apresentação já começou.' : 'O prazo de envio desta sexta-feira terminou.');
        }

        $form = null;
        if ($isOpen) {
            $submission ??= new Submission($this->getUser(), $friday);
            $form = $this->createForm(SubmissionType::class, $submission, ['has_photo' => null !== $submission->getPhotoFilename()]);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $manager->save($submission, $form->get('photo')->getData());
                    $this->addFlash('success', 'Sua contribuição está salva. A sexta-feira já tem a sua história!');

                    return $this->redirectToRoute('app_submission', ['friday' => $friday->format('Y-m-d')], Response::HTTP_SEE_OTHER);
                } catch (UniqueConstraintViolationException) {
                    $this->addFlash('error', 'Seu envio já foi salvo em outra aba. Confira a versão atual antes de editar.');

                    return $this->redirectToRoute('app_submission', ['friday' => $friday->format('Y-m-d')], Response::HTTP_SEE_OTHER);
                } catch (FileException) {
                    $form->get('photo')->addError(new FormError('Não foi possível salvar a foto. Tente novamente.'));
                } catch (\DomainException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('submission/form.html.twig', [
            'friday' => $friday,
            'month_name' => FridayCalendar::MONTHS[(int) $friday->format('n')],
            'submission' => $submission,
            'is_open' => $isOpen,
            'form' => $form,
            'presentation' => $presentation,
        ]);
    }

    #[Route('/envios/{id}/foto', name: 'app_submission_photo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function photo(Submission $submission, SubmissionPhotoStorage $photos, PresentationManager $presentations): BinaryFileResponse
    {
        if ($submission->getAuthor()->getId() !== $this->getUser()->getId() && !$presentations->canViewPhoto($submission)) {
            throw $this->createNotFoundException();
        }
        $filename = $submission->getPhotoFilename();
        if (null === $filename || !is_file($photos->path($filename))) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($photos->path($filename));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
