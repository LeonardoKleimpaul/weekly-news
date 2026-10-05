<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Service\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class AccountController extends AbstractController
{
    #[Route('/conta', name: 'app_account', methods: ['GET', 'POST'])]
    public function index(#[CurrentUser] User $user, Request $request, UserManager $manager, Security $security): Response
    {
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->save($user, $form->get('newPassword')->getData());
            $security->logout(false);

            return $this->redirectToRoute('app_login', ['password_changed' => 1], Response::HTTP_SEE_OTHER);
        }

        return $this->render('account/index.html.twig', ['form' => $form]);
    }
}
