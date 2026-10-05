<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Senha atual',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new Assert\NotBlank(message: 'Informe a senha atual.'), new UserPassword(message: 'A senha atual está incorreta.')],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'As senhas precisam ser iguais.',
                'first_options' => ['label' => 'Nova senha', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmar nova senha', 'attr' => ['autocomplete' => 'new-password']],
                'constraints' => [new Assert\NotBlank(message: 'Informe uma nova senha.'), new Assert\Length(min: 12, max: 128, minMessage: 'Use pelo menos {{ limit }} caracteres.')],
            ]);
    }
}
