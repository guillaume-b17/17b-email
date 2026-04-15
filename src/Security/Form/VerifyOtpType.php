<?php

declare(strict_types=1);

namespace App\Security\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class VerifyOtpType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code OTP',
                'attr' => [
                    'autocomplete' => 'one-time-code',
                    'inputmode' => 'numeric',
                    'placeholder' => '123456',
                ],
                'constraints' => [
                    new NotBlank(message: 'Veuillez saisir le code OTP.'),
                    new Length(min: 6, max: 6, exactMessage: 'Le code doit contenir 6 chiffres.'),
                    new Regex(pattern: '/^\d{6}$/', message: 'Le code doit contenir 6 chiffres.'),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Se connecter',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}
