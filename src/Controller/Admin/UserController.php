<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/usuarios', name: 'admin_user_')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', ['users' => $users->findBy([], ['name' => 'ASC'])]);
    }

    #[Route('/novo', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserManager $manager): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->save($user, $form->get('plainPassword')->getData());
            $this->addFlash('success', 'Usuário criado. Compartilhe os dados de acesso com o participante.');

            return $this->redirectToRoute('admin_user_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/form.html.twig', ['form' => $form, 'is_new' => true, 'is_self' => false]);
    }

    #[Route('/{id}/editar', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request, UserManager $manager): Response
    {
        $isSelf = $user->getId() === $this->getUser()->getId();
        $form = $this->createForm(UserType::class, $user, ['is_self' => $isSelf]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->save($user, $form->get('plainPassword')->getData());
            $this->addFlash('success', 'Usuário atualizado.');

            return $this->redirectToRoute('admin_user_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/form.html.twig', ['form' => $form, 'is_new' => false, 'is_self' => $isSelf]);
    }
}
