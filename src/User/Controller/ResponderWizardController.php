<?php

declare(strict_types=1);

namespace App\User\Controller;

use App\Entity\EmailAccount;
use App\Entity\Redirection;
use App\Entity\Responder;
use App\Entity\User;
use App\Sync\Service\OvhRedirectionManager;
use App\Sync\Service\OvhResponderManager;
use App\User\Service\ResponderMessagePresetProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/compte/assistant')]
final class ResponderWizardController extends AbstractController
{
    private const APP_TIMEZONE = 'Europe/Paris';
    private const SESSION_KEY = 'responder_wizard';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OvhResponderManager $ovhResponderManager,
        private readonly OvhRedirectionManager $ovhRedirectionManager,
        private readonly ResponderMessagePresetProvider $responderMessagePresetProvider,
    ) {
    }

    #[Route('', name: 'app_user_wizard_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        $session = $request->getSession();
        $session->remove(self::SESSION_KEY);

        return $this->redirectToRoute('app_user_wizard_step', [
            ...$this->managedAccountRouteParams($this->resolveManagedEmailAccount($request->query->get('accountId'))),
            'step' => 'start_date',
        ]);
    }

    #[Route(
        '/{step}',
        name: 'app_user_wizard_step',
        requirements: ['step' => 'start_date|end_date|message|add_redirection|destination|same_dates|redirection_dates|review'],
        methods: ['GET', 'POST']
    )]
    public function step(Request $request, string $step): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $emailAccount = $this->resolveManagedEmailAccount($request->query->get('accountId'));
        if (!$emailAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Synchronisez d’abord votre compte e-mail.');

            return $this->redirectToRoute('app_user_account');
        }

        $session = $request->getSession();
        /** @var array<string, mixed> $data */
        $data = (array) $session->get(self::SESSION_KEY, []);

        $step = $this->normalizeStep($step);
        if ('POST' === $request->getMethod()) {
            if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expirée, veuillez réessayer.');

                return $this->redirectToRoute('app_user_wizard_step', [
                    ...$this->managedAccountRouteParams($emailAccount),
                    'step' => $step,
                ]);
            }

            $result = $this->handlePost($request, $emailAccount, $step, $data);
            $session->set(self::SESSION_KEY, $result['data']);

            if (null !== $result['redirectStep']) {
                return $this->redirectToRoute('app_user_wizard_step', [
                    ...$this->managedAccountRouteParams($emailAccount),
                    'step' => $result['redirectStep'],
                ]);
            }

            return $this->redirectToRoute('app_user_account', $this->managedAccountRouteParams($emailAccount));
        }

        if ('start_date' === $step && ('' === trim((string) ($data['startsAt'] ?? '')))) {
            $timeZone = new \DateTimeZone(self::APP_TIMEZONE);
            $default = (new \DateTimeImmutable('now', $timeZone))->setTime(18, 0, 0);
            $data['startsAt'] = $default->format('Y-m-d\TH:i');
        }

        $view = $this->buildViewData($emailAccount, $step, $data);

        return $this->render('user/wizard/responder.html.twig', $view);
    }

    #[Route('/appliquer', name: 'app_user_wizard_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_user_account');
        }

        $emailAccount = $this->resolveManagedEmailAccount($request->request->get('accountId'));
        if (!$emailAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Synchronisez d’abord votre compte e-mail.');

            return $this->redirectToRoute('app_user_account');
        }

        $session = $request->getSession();
        /** @var array<string, mixed> $data */
        $data = (array) $session->get(self::SESSION_KEY, []);

        $startsAt = $this->parseDateTime((string) ($data['startsAt'] ?? ''));
        $endsAt = $this->parseDateTime((string) ($data['endsAt'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));

        if ('' === $message) {
            $this->addFlash('error', 'Le message du répondeur est obligatoire.');

            return $this->redirectToRoute('app_user_wizard_step', [
                ...$this->managedAccountRouteParams($emailAccount),
                'step' => 'message',
            ]);
        }

        $ownerUser = $emailAccount->getOwner();
        $message = $this->responderMessagePresetProvider->applyVariables(
            $message,
            $startsAt,
            $endsAt,
            $ownerUser->displayName()
        );

        try {
            $this->ovhResponderManager->upsert($emailAccount, [
                'enabled' => true,
                'message' => $message,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
            ]);
        } catch (\Throwable $exception) {
            $this->addFlash('error', "Erreur OVH répondeur: {$exception->getMessage()}");

            return $this->redirectToRoute('app_user_wizard_step', [
                ...$this->managedAccountRouteParams($emailAccount),
                'step' => 'review',
            ]);
        }

        /** @var Responder|null $responder */
        $responder = $this->entityManager->getRepository(Responder::class)->findOneBy([
            'owner' => $emailAccount->getOwner(),
            'emailAccount' => $emailAccount,
        ]);
        if (!$responder instanceof Responder) {
            $responder = new Responder($emailAccount->getOwner(), $emailAccount);
            $this->entityManager->persist($responder);
        }
        $responder
            ->setEnabled(true)
            ->setSubject(null)
            ->setMessage($message)
            ->setStartsAt($startsAt)
            ->setEndsAt($endsAt);

        $addRedirection = (bool) ($data['addRedirection'] ?? false);
        if ($addRedirection) {
            $destinationEmail = mb_strtolower(trim((string) ($data['destinationEmail'] ?? '')));
            if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Adresse de destination invalide.');

                return $this->redirectToRoute('app_user_wizard_step', [
                    ...$this->managedAccountRouteParams($emailAccount),
                    'step' => 'destination',
                ]);
            }

            if ($destinationEmail === $emailAccount->getEmail()) {
                $this->addFlash('error', 'La destination doit être différente de votre compte.');

                return $this->redirectToRoute('app_user_wizard_step', [
                    ...$this->managedAccountRouteParams($emailAccount),
                    'step' => 'destination',
                ]);
            }

            $redirectSameDates = (bool) ($data['redirectSameDates'] ?? true);
            $redirectionStartsAt = $redirectSameDates
                ? $startsAt
                : $this->parseDateTime((string) ($data['redirectionStartsAt'] ?? ''));
            $redirectionEndsAt = $redirectSameDates
                ? $endsAt
                : $this->parseDateTime((string) ($data['redirectionEndsAt'] ?? ''));

            if ($redirectionStartsAt instanceof \DateTimeImmutable && $redirectionEndsAt instanceof \DateTimeImmutable && $redirectionStartsAt > $redirectionEndsAt) {
                $this->addFlash('error', 'La date de fin de redirection doit être après la date de début.');

                return $this->redirectToRoute('app_user_wizard_step', [
                    ...$this->managedAccountRouteParams($emailAccount),
                    'step' => 'redirection_dates',
                ]);
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone(self::APP_TIMEZONE));
            $localCopy = true;
            $redirection = new Redirection($emailAccount->getOwner(), $emailAccount, $emailAccount->getEmail(), $destinationEmail);
            $redirection
                ->setLocalCopy($localCopy)
                ->setStartsAt($redirectionStartsAt)
                ->setEndsAt($redirectionEndsAt);

            $shouldBeActiveNow = (null === $redirectionStartsAt || $redirectionStartsAt <= $now) && (null === $redirectionEndsAt || $redirectionEndsAt > $now);
            try {
                if ($shouldBeActiveNow) {
                    $ovhId = $this->ovhRedirectionManager->create($emailAccount, $destinationEmail, $localCopy);
                    $redirection
                        ->setOvhId($ovhId)
                        ->setEnabled(true);
                } else {
                    $redirection->setEnabled(false);
                }
            } catch (\Throwable $exception) {
                $this->addFlash('error', "Erreur création redirection: {$exception->getMessage()}");

                return $this->redirectToRoute('app_user_wizard_step', [
                    ...$this->managedAccountRouteParams($emailAccount),
                    'step' => 'review',
                ]);
            }

            $this->entityManager->persist($redirection);
        }

        $this->entityManager->flush();
        $session->remove(self::SESSION_KEY);

        $this->addFlash('success', 'Assistant appliqué : répondeur mis à jour'.($addRedirection ? ' + redirection.' : '.'));

        return $this->redirectToRoute('app_user_account', $this->managedAccountRouteParams($emailAccount));
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, redirectStep: ?string}
     */
    private function handlePost(Request $request, EmailAccount $emailAccount, string $step, array $data): array
    {
        $timeZone = new \DateTimeZone(self::APP_TIMEZONE);
        $now = new \DateTimeImmutable('now', $timeZone);

        return match ($step) {
            'start_date' => $this->handleStartDateStep($request, $data, $now, $emailAccount),
            'end_date' => $this->handleEndDateStep($request, $data, $now),
            'message' => $this->handleMessageStep($request, $data),
            'add_redirection' => $this->handleAddRedirectionStep($request, $data),
            'destination' => $this->handleDestinationStep($request, $data, $emailAccount),
            'same_dates' => $this->handleSameDatesStep($request, $data),
            'redirection_dates' => $this->handleRedirectionDatesStep($request, $data, $now),
            'review' => ['data' => $data, 'redirectStep' => null],
            default => ['data' => $data, 'redirectStep' => 'start_date'],
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, redirectStep: ?string}
     */
    private function handleStartDateStep(Request $request, array $data, \DateTimeImmutable $now, EmailAccount $emailAccount): array
    {
        $rawStartsAt = (string) $request->request->get('startsAt', '');
        $startsAt = $this->parseDateTime($rawStartsAt);
        $now = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);

        if ('' !== trim($rawStartsAt) && !$startsAt instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'La date de début est invalide.');

            return ['data' => $data, 'redirectStep' => 'start_date'];
        }
        if ($startsAt instanceof \DateTimeImmutable && $startsAt < $now) {
            $startsAt = $now;
            $this->addFlash('success', 'Date de début anté-datée : elle a été ajustée à maintenant.');
        }

        $data['startsAt'] = $startsAt?->format('Y-m-d\TH:i') ?? '';
        $data['accountId'] = $emailAccount->getId();

        return ['data' => $data, 'redirectStep' => 'end_date'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, redirectStep: ?string}
     */
    private function handleEndDateStep(Request $request, array $data, \DateTimeImmutable $now): array
    {
        $rawEndsAt = (string) $request->request->get('endsAt', '');
        $endsAt = $this->parseDateTime($rawEndsAt);

        if ('' !== trim($rawEndsAt) && !$endsAt instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'La date de fin est invalide.');

            return ['data' => $data, 'redirectStep' => 'end_date'];
        }

        $startsAt = $this->parseDateTime((string) ($data['startsAt'] ?? ''));
        if ($startsAt instanceof \DateTimeImmutable && $endsAt instanceof \DateTimeImmutable && $startsAt > $endsAt) {
            $this->addFlash('error', 'La date de fin doit être après la date de début.');

            return ['data' => $data, 'redirectStep' => 'end_date'];
        }

        if ($endsAt instanceof \DateTimeImmutable && $endsAt < $now) {
            $this->addFlash('error', 'La date de fin est déjà passée.');

            return ['data' => $data, 'redirectStep' => 'end_date'];
        }

        $data['endsAt'] = $endsAt?->format('Y-m-d\TH:i') ?? '';

        return ['data' => $data, 'redirectStep' => 'message'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, redirectStep: ?string}
     */
    private function handleMessageStep(Request $request, array $data): array
    {
        $message = trim((string) $request->request->get('message', ''));
        if ('' === $message) {
            $this->addFlash('error', 'Le message du répondeur est obligatoire.');

            return ['data' => $data, 'redirectStep' => 'message'];
        }

        $data['message'] = $message;

        return ['data' => $data, 'redirectStep' => 'add_redirection'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, redirectStep: ?string}
     */
    private function handleAddRedirectionStep(Request $request, array $data): array
    {
        $add = '1' === (string) $request->request->get('addRedirection', '0');
        $data['addRedirection'] = $add;

        return ['data' => $data, 'redirectStep' => $add ? 'destination' : 'review'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, redirectStep: ?string}
     */
    private function handleDestinationStep(Request $request, array $data, EmailAccount $emailAccount): array
    {
        $destinationEmail = mb_strtolower(trim((string) $request->request->get('destinationEmail', '')));
        if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Adresse de destination invalide.');

            return ['data' => $data, 'redirectStep' => 'destination'];
        }
        if ($destinationEmail === $emailAccount->getEmail()) {
            $this->addFlash('error', 'La destination doit être différente de votre compte.');

            return ['data' => $data, 'redirectStep' => 'destination'];
        }

        $data['destinationEmail'] = $destinationEmail;

        return ['data' => $data, 'redirectStep' => 'same_dates'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, redirectStep: ?string}
     */
    private function handleSameDatesStep(Request $request, array $data): array
    {
        $same = '1' === (string) $request->request->get('redirectSameDates', '1');
        $data['redirectSameDates'] = $same;

        return ['data' => $data, 'redirectStep' => $same ? 'review' : 'redirection_dates'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, redirectStep: ?string}
     */
    private function handleRedirectionDatesStep(Request $request, array $data, \DateTimeImmutable $now): array
    {
        $rawStartsAt = (string) $request->request->get('redirectionStartsAt', '');
        $rawEndsAt = (string) $request->request->get('redirectionEndsAt', '');
        $startsAt = $this->parseDateTime($rawStartsAt);
        $endsAt = $this->parseDateTime($rawEndsAt);

        if ('' !== trim($rawStartsAt) && !$startsAt instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'La date de début de redirection est invalide.');

            return ['data' => $data, 'redirectStep' => 'redirection_dates'];
        }
        if ('' !== trim($rawEndsAt) && !$endsAt instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'La date de fin de redirection est invalide.');

            return ['data' => $data, 'redirectStep' => 'redirection_dates'];
        }
        if ($startsAt instanceof \DateTimeImmutable && $endsAt instanceof \DateTimeImmutable && $startsAt > $endsAt) {
            $this->addFlash('error', 'La date de fin de redirection doit être après la date de début.');

            return ['data' => $data, 'redirectStep' => 'redirection_dates'];
        }
        if ($endsAt instanceof \DateTimeImmutable && $endsAt < $now) {
            $this->addFlash('error', 'La date de fin de redirection est déjà passée.');

            return ['data' => $data, 'redirectStep' => 'redirection_dates'];
        }

        $data['redirectionStartsAt'] = $startsAt?->format('Y-m-d\TH:i') ?? '';
        $data['redirectionEndsAt'] = $endsAt?->format('Y-m-d\TH:i') ?? '';

        return ['data' => $data, 'redirectStep' => 'review'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildViewData(EmailAccount $emailAccount, string $step, array $data): array
    {
        $accountParams = $this->managedAccountRouteParams($emailAccount);
        $previousStep = $this->resolvePreviousStep($step, $data);

        return [
            'emailAccount' => $emailAccount,
            'step' => $step,
            'data' => $data,
            'accountParams' => $accountParams,
            'agencyPhone' => $this->responderMessagePresetProvider->phoneNumber(),
            'messagePresets' => $this->responderMessagePresetProvider->all(),
            'ownerDisplayName' => $emailAccount->getOwner()->displayName(),
            'previousStep' => $previousStep,
            'hideAppHeader' => true,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolvePreviousStep(string $step, array $data): ?string
    {
        return match ($step) {
            'start_date' => null,
            'end_date' => 'start_date',
            'message' => 'end_date',
            'add_redirection' => 'message',
            'destination' => 'add_redirection',
            'same_dates' => 'destination',
            'redirection_dates' => 'same_dates',
            'review' => $this->resolvePreviousStepForReview($data),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolvePreviousStepForReview(array $data): string
    {
        $addRedirection = (bool) ($data['addRedirection'] ?? false);
        if (!$addRedirection) {
            return 'add_redirection';
        }

        $sameDates = (bool) ($data['redirectSameDates'] ?? true);
        if ($sameDates) {
            return 'same_dates';
        }

        return 'redirection_dates';
    }

    private function normalizeStep(string $step): string
    {
        $step = mb_strtolower(trim($step));

        return match ($step) {
            'start_date',
            'end_date',
            'message',
            'add_redirection',
            'destination',
            'same_dates',
            'redirection_dates',
            'review' => $step,
            default => 'start_date',
        };
    }

    private function parseDateTime(string $rawDateTime): ?\DateTimeImmutable
    {
        $rawDateTime = trim($rawDateTime);
        if ('' === $rawDateTime) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $rawDateTime,
            new \DateTimeZone(self::APP_TIMEZONE)
        );
        if (!$date instanceof \DateTimeImmutable) {
            return null;
        }

        return $date;
    }

    private function resolveManagedEmailAccount(mixed $rawAccountId): ?EmailAccount
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        if ($this->isGranted('ROLE_ADMIN') && is_scalar($rawAccountId) && ctype_digit((string) $rawAccountId)) {
            /** @var EmailAccount|null $adminTargetEmailAccount */
            $adminTargetEmailAccount = $this->entityManager->getRepository(EmailAccount::class)->find((int) $rawAccountId);
            if ($adminTargetEmailAccount instanceof EmailAccount) {
                return $adminTargetEmailAccount;
            }
        }

        /** @var EmailAccount|null $emailAccount */
        $emailAccount = $this->entityManager->getRepository(EmailAccount::class)->findOneBy([
            'owner' => $user,
            'email' => $user->getEmail(),
        ]);

        return $emailAccount;
    }

    /**
     * @return array<string, int>
     */
    private function managedAccountRouteParams(?EmailAccount $emailAccount): array
    {
        if (!$this->isGranted('ROLE_ADMIN') || !$emailAccount instanceof EmailAccount || null === $emailAccount->getId()) {
            return [];
        }

        return ['accountId' => $emailAccount->getId()];
    }
}

