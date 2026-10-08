<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class VoteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $names = array_column($options['ballot'], 'name', 'user_id');
        $builder->add('candidate', ChoiceType::class, [
            'label' => 'Quem marcou esta sexta?',
            'choices' => array_column($options['ballot'], 'user_id'),
            'choice_label' => static fn (int $id) => $names[$id],
            'choice_value' => static fn (?int $id) => null === $id ? '' : (string) $id,
            'expanded' => true,
            'multiple' => false,
            'constraints' => [new Assert\NotBlank(message: 'Selecione um participante antes de confirmar.')],
            'invalid_message' => 'Escolha um participante desta edição.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['ballot' => []]);
        $resolver->setAllowedTypes('ballot', 'array');
    }
}
