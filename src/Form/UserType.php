<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $constraints = [new Assert\Length(min: 12, max: 128, minMessage: 'Use pelo menos {{ limit }} caracteres.')];
        if ($options['is_new']) {
            $constraints[] = new Assert\NotBlank(message: 'Informe uma senha.');
        }

        $builder
            ->add('name', TextType::class, ['label' => 'Nome'])
            ->add('email', EmailType::class, ['label' => 'E-mail'])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => $options['is_new'],
                'invalid_message' => 'As senhas precisam ser iguais.',
                'constraints' => $constraints,
                'first_options' => ['label' => $options['is_new'] ? 'Senha' : 'Nova senha (opcional)', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmar senha', 'attr' => ['autocomplete' => 'new-password']],
            ])
            ->add('isAdmin', CheckboxType::class, ['label' => 'Administrador', 'required' => false, 'disabled' => $options['is_self']])
            ->add('isActive', CheckboxType::class, ['label' => 'Conta ativa', 'required' => false, 'disabled' => $options['is_self']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class, 'is_new' => false, 'is_self' => false]);
        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('is_self', 'bool');
    }
}
