<?php

namespace App\Form;

use App\Entity\Submission;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class SubmissionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $photoConstraints = [new Assert\Image(
            maxSize: '5Mi',
            extensions: ['jpg', 'jpeg', 'png', 'webp'],
            maxSizeMessage: 'A foto deve ter no máximo 5 MB.',
            extensionsMessage: 'Escolha uma foto JPG, PNG ou WebP.',
            mimeTypesMessage: 'Escolha uma foto JPG, PNG ou WebP válida.',
            sizeNotDetectedMessage: 'Não foi possível ler esta imagem. Escolha outra foto.',
        )];
        if (!$options['has_photo']) {
            $photoConstraints[] = new Assert\NotBlank(message: 'Escolha uma foto para a sua notícia.');
        }

        $builder
            ->add('photo', FileType::class, [
                'label' => $options['has_photo'] ? 'Trocar foto (opcional)' : 'Sua foto',
                'mapped' => false,
                'required' => !$options['has_photo'],
                'constraints' => $photoConstraints,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp', 'data-submission-photo' => ''],
                'help' => 'JPG, PNG ou WebP, até 5 MB. Uma imagem que conte a sua história.',
            ])
            ->add('text', TextareaType::class, [
                'label' => 'O que aconteceu?',
                'empty_data' => '',
                'attr' => ['rows' => 8, 'maxlength' => 10000, 'placeholder' => 'A notícia, o meme ou aquela história que merece entrar na resenha…', 'data-submission-text' => ''],
                'help' => 'Escreva do seu jeito. Você pode ajustar o envio até o fim desta sexta-feira.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Submission::class,
            'has_photo' => false,
            'post_max_size_message' => 'O envio ficou muito grande. Escolha uma foto de até 5 MB.',
        ]);
        $resolver->setAllowedTypes('has_photo', 'bool');
    }
}
