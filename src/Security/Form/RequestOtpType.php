<?php

declare(strict_types=1);

namespace App\Security\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

final class RequestOtpType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => [
                    'autocomplete' => 'email',
                    'placeholder' => 'prenom.nom@domaine.fr',
                ],
                'constraints' => [
                    new NotBlank(message: 'Veuillez saisir une adresse e-mail.'),
                    new Email(message: 'Adresse e-mail invalide.'),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Recevoir un code',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}
