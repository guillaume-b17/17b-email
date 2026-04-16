<?php

declare(strict_types=1);

namespace App\User\Controller;

use App\Entity\EmailAccount;
use App\Entity\Redirection;
use App\Entity\Responder;
use App\Entity\User;
use App\Sync\Service\OvhRedirectionManager;
use App\Sync\Service\OvhResponderManager;
use App\Sync\Service\UserRedirectionSynchronizer;
use App\Sync\Service\UserMailboxSynchronizer;
use App\User\Service\ResponderMessagePresetProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/compte')]
final class AccountController extends AbstractController
{
    private const APP_TIMEZONE = 'Europe/Paris';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserMailboxSynchronizer $userMailboxSynchronizer,
        private readonly OvhResponderManager $ovhResponderManager,
        private readonly OvhRedirectionManager $ovhRedirectionManager,
        private readonly UserRedirectionSynchronizer $userRedirectionSynchronizer,
        private readonly ResponderMessagePresetProvider $responderMessagePresetProvider,
    ) {
    }

    #[Route('', name: 'app_user_account', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $emailAccount = $this->resolveManagedEmailAccount($user, $request->query->get('accountId'));
        $syncError = null;
        if ('1' === $request->query->get('sync') && $emailAccount instanceof EmailAccount) {
            try {
                $this->userMailboxSynchronizer->synchronize($emailAccount->getOwner());
                $this->addFlash('success', 'Synchronisation OVH terminée.');
            } catch (\Throwable $exception) {
                $syncError = $exception->getMessage();
                $this->addFlash('error', 'La synchronisation OVH a échoué.');
            }
        }
        $responder = null;
        $responderSyncReport = null;
        $mailboxCount = $user->getEmailAccounts()->count();
        $redirectionSummary = [
            'outgoing' => 0,
            'incoming' => 0,
            'localCopy' => 0,
        ];
        if ($emailAccount instanceof EmailAccount) {
            /** @var Responder|null $responder */
            $responder = $this->entityManager->getRepository(Responder::class)->findOneBy([
                'owner' => $emailAccount->getOwner(),
                'emailAccount' => $emailAccount,
            ]);

            // Récupère le répondeur OVH s'il existe, notamment pour les cas expirés.
            if ('1' === $request->query->get('syncResponder') || !$responder instanceof Responder) {
                try {
                    $responderSyncReport = $this->ovhResponderManager->fetchWithDiagnostics($emailAccount);
                    if ($responderSyncReport['found'] && null !== $responderSyncReport['data']) {
                        $responder = $this->upsertLocalResponderFromSnapshot($emailAccount->getOwner(), $emailAccount, $responderSyncReport['data']);
                    }

                    if ('1' === $request->query->get('syncResponder')) {
                        if ($responderSyncReport['found']) {
                            $this->addFlash('success', 'Récupération du répondeur OVH terminée.');
                        } else {
                            $this->addFlash('error', 'Aucun répondeur trouvé sur OVH pour ce compte.');
                        }
                    }
                } catch (\Throwable $exception) {
                    if ('1' === $request->query->get('syncResponder')) {
                        $this->addFlash('error', "Impossible de récupérer le répondeur OVH: {$exception->getMessage()}");
                    }
                }
            }

            /** @var list<Redirection> $redirections */
            $redirections = $this->entityManager->getRepository(Redirection::class)->findBy([
                'owner' => $emailAccount->getOwner(),
                'emailAccount' => $emailAccount,
            ]);
            foreach ($redirections as $redirection) {
                if ($redirection->getSourceEmail() === $emailAccount->getEmail()) {
                    ++$redirectionSummary['outgoing'];
                } else {
                    ++$redirectionSummary['incoming'];
                }

                if ($redirection->isLocalCopy()) {
                    ++$redirectionSummary['localCopy'];
                }
            }
        }

        return $this->render('user/account.html.twig', [
            'emailAccount' => $emailAccount,
            'mailboxCount' => $mailboxCount,
            'responder' => $responder,
            'responderSyncReport' => $responderSyncReport,
            'redirectionSummary' => $redirectionSummary,
            'currentAccountId' => $emailAccount?->getId(),
            'isAdminContext' => $this->isGranted('ROLE_ADMIN'),
            'agencyPhone' => $this->responderMessagePresetProvider->phoneNumber(),
            'syncError' => $syncError,
        ]);
    }

    #[Route('/redirections', name: 'app_user_redirections', methods: ['GET'])]
    public function redirections(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $emailAccount = $this->resolveManagedEmailAccount($user, $request->query->get('accountId'));
        if (!$emailAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Synchronisez d’abord votre compte e-mail.');

            return $this->redirectToRoute('app_user_account');
        }

        if ('1' === $request->query->get('syncRedirections')) {
            try {
                $redirectionResult = $this->userRedirectionSynchronizer->synchronize($emailAccount->getOwner(), $emailAccount);
                $this->addFlash(
                    'success',
                    sprintf(
                        'Synchronisation redirections terminée (%d créées, %d mises à jour, %d supprimées).',
                        $redirectionResult['created'],
                        $redirectionResult['updated'],
                        $redirectionResult['removed']
                    )
                );
            } catch (\Throwable $exception) {
                $this->addFlash('error', "Synchronisation redirections échouée: {$exception->getMessage()}");
            }
        }

        $outgoingRedirections = [];
        $incomingRedirections = [];

        /** @var list<Redirection> $redirections */
        $redirections = $this->entityManager->getRepository(Redirection::class)->findBy([
            'owner' => $emailAccount->getOwner(),
            'emailAccount' => $emailAccount,
        ]);

        foreach ($redirections as $redirection) {
            if ($redirection->getSourceEmail() === $emailAccount->getEmail()) {
                $outgoingRedirections[] = $redirection;
            } else {
                $incomingRedirections[] = $redirection;
            }
        }

        return $this->render('user/redirections.html.twig', [
            'emailAccount' => $emailAccount,
            'outgoingRedirections' => $outgoingRedirections,
            'incomingRedirections' => $incomingRedirections,
            'currentAccountId' => $emailAccount->getId(),
            'isAdminContext' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route('/repondeur', name: 'app_user_responder_edit', methods: ['GET'])]
    public function editResponder(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $emailAccount = $this->resolveManagedEmailAccount($user, $request->query->get('accountId'));
        if (!$emailAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Synchronisez d’abord votre compte e-mail.');

            return $this->redirectToRoute('app_user_account');
        }

        /** @var Responder|null $responder */
        $responder = $this->entityManager->getRepository(Responder::class)->findOneBy([
            'owner' => $emailAccount->getOwner(),
            'emailAccount' => $emailAccount,
        ]);

        return $this->render('user/responder_form.html.twig', [
            'emailAccount' => $emailAccount,
            'responder' => $responder,
            'messagePresets' => $this->responderMessagePresetProvider->all(),
            'agencyPhone' => $this->responderMessagePresetProvider->phoneNumber(),
            'currentAccountId' => $emailAccount->getId(),
            'isAdminContext' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route('/repondeur/enregistrer', name: 'app_user_responder_save', methods: ['POST'])]
    public function saveResponder(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('save_responder', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_user_responder_edit');
        }

        $emailAccount = $this->resolveManagedEmailAccount($user, $request->request->get('accountId'));
        if (!$emailAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Synchronisez d’abord votre compte e-mail.');

            return $this->redirectToRoute('app_user_responder_edit');
        }

        $presetKey = trim((string) $request->request->get('presetKey', ''));
        $message = trim((string) $request->request->get('message', ''));
        // L'activation explicite n'est plus exposée dans l'UI utilisateur.
        $enabled = true;
        $rawStartsAt = (string) $request->request->get('startsAt', '');
        $rawEndsAt = (string) $request->request->get('endsAt', '');
        $startsAt = $this->parseDateTime($rawStartsAt);
        $endsAt = $this->parseDateTime($rawEndsAt);

        if ('' !== trim($rawStartsAt) && !$startsAt instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'La date de début est invalide.');

            return $this->redirectToRoute('app_user_responder_edit', $this->managedAccountRouteParams($emailAccount));
        }

        if ('' !== trim($rawEndsAt) && !$endsAt instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'La date de fin est invalide.');

            return $this->redirectToRoute('app_user_responder_edit', $this->managedAccountRouteParams($emailAccount));
        }

        if ('' === $message) {
            $this->addFlash('error', 'Le message du répondeur est obligatoire.');

            return $this->redirectToRoute('app_user_responder_edit', $this->managedAccountRouteParams($emailAccount));
        }

        if ('' !== $presetKey && !$this->responderMessagePresetProvider->has($presetKey)) {
            $this->addFlash('error', 'Le format de message sélectionné est invalide.');

            return $this->redirectToRoute('app_user_responder_edit', $this->managedAccountRouteParams($emailAccount));
        }

        if ($startsAt instanceof \DateTimeImmutable && $endsAt instanceof \DateTimeImmutable && $startsAt > $endsAt) {
            $this->addFlash('error', 'La date de fin doit être après la date de début.');

            return $this->redirectToRoute('app_user_responder_edit', $this->managedAccountRouteParams($emailAccount));
        }

        $message = $this->responderMessagePresetProvider->applyVariables($message, $startsAt, $endsAt);

        try {
            $this->ovhResponderManager->upsert($emailAccount, [
                'enabled' => $enabled,
                'message' => $message,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
            ]);
        } catch (\Throwable $exception) {
            $this->addFlash('error', "Erreur OVH répondeur: {$exception->getMessage()}");

            return $this->redirectToRoute('app_user_responder_edit', $this->managedAccountRouteParams($emailAccount));
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
            ->setEnabled($enabled)
            ->setSubject(null)
            ->setMessage($message)
            ->setStartsAt($startsAt)
            ->setEndsAt($endsAt);

        $this->entityManager->flush();
        $this->addFlash('success', 'Répondeur enregistré.');

        return $this->redirectToRoute('app_user_responder_edit', $this->managedAccountRouteParams($emailAccount));
    }

    #[Route('/repondeur/supprimer', name: 'app_user_responder_delete', methods: ['POST'])]
    public function deleteResponder(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('delete_responder', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_user_account');
        }

        $emailAccount = $this->resolveManagedEmailAccount($user, $request->request->get('accountId'));
        if (!$emailAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Aucun compte e-mail trouvé pour la suppression.');

            return $this->redirectToRoute('app_user_account');
        }

        try {
            $this->ovhResponderManager->delete($emailAccount);
        } catch (\Throwable $exception) {
            $this->addFlash('error', "Erreur OVH suppression: {$exception->getMessage()}");

            return $this->redirectToRoute('app_user_responder_edit', $this->managedAccountRouteParams($emailAccount));
        }

        /** @var Responder|null $responder */
        $responder = $this->entityManager->getRepository(Responder::class)->findOneBy([
            'owner' => $emailAccount->getOwner(),
            'emailAccount' => $emailAccount,
        ]);
        if ($responder instanceof Responder) {
            $this->entityManager->remove($responder);
            $this->entityManager->flush();
        }

        $this->addFlash('success', 'Répondeur supprimé.');

        return $this->redirectToRoute('app_user_responder_edit', $this->managedAccountRouteParams($emailAccount));
    }

    #[Route('/redirections/creer', name: 'app_user_redirection_create', methods: ['POST'])]
    public function createRedirection(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('create_redirection', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_user_redirections');
        }

        $emailAccount = $this->resolveManagedEmailAccount($user, $request->request->get('accountId'));
        if (!$emailAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Synchronisez d’abord votre compte e-mail.');

            return $this->redirectToRoute('app_user_redirections');
        }

        $destinationEmail = mb_strtolower(trim((string) $request->request->get('destinationEmail', '')));
        $localCopy = '1' === (string) $request->request->get('localCopy', '0');
        $startsAt = $this->parseDateTime((string) $request->request->get('startsAt', ''));
        $endsAt = $this->parseDateTime((string) $request->request->get('endsAt', ''));

        if ($startsAt instanceof \DateTimeImmutable && $endsAt instanceof \DateTimeImmutable && $startsAt > $endsAt) {
            $this->addFlash('error', 'La date de fin doit être après la date de début.');

            return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::APP_TIMEZONE));
        if ($endsAt instanceof \DateTimeImmutable && $endsAt < $now) {
            $this->addFlash('error', 'La date de fin est déjà passée.');

            return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
        }
        if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Adresse de destination invalide.');

            return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
        }

        if ($destinationEmail === $emailAccount->getEmail()) {
            $this->addFlash('error', 'La destination doit être différente de votre compte.');

            return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
        }

        try {
            $redirection = new Redirection($emailAccount->getOwner(), $emailAccount, $emailAccount->getEmail(), $destinationEmail);
            $redirection
                ->setLocalCopy($localCopy)
                ->setStartsAt($startsAt)
                ->setEndsAt($endsAt);

            $shouldBeActiveNow = (null === $startsAt || $startsAt <= $now) && (null === $endsAt || $endsAt > $now);
            if ($shouldBeActiveNow) {
                $ovhId = $this->ovhRedirectionManager->create($emailAccount, $destinationEmail, $localCopy);
                $redirection
                    ->setOvhId($ovhId)
                    ->setEnabled(true);
                $this->addFlash('success', 'Redirection créée.');
            } else {
                $redirection->setEnabled(false);
                $this->addFlash('success', 'Redirection programmée. Elle sera appliquée automatiquement.');
            }

            $this->entityManager->persist($redirection);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->addFlash('error', "Erreur création redirection: {$exception->getMessage()}");
        }

        return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
    }

    #[Route('/redirections/{id}/modifier', name: 'app_user_redirection_update', methods: ['POST'])]
    public function updateRedirection(Request $request, int $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('update_redirection_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_user_redirections');
        }

        $emailAccount = $this->resolveManagedEmailAccount($user, $request->request->get('accountId'));
        if (!$emailAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Synchronisez d’abord votre compte e-mail.');

            return $this->redirectToRoute('app_user_redirections');
        }

        /** @var Redirection|null $redirection */
        $redirection = $this->entityManager->getRepository(Redirection::class)->findOneBy([
            'id' => $id,
            'owner' => $emailAccount->getOwner(),
            'emailAccount' => $emailAccount,
        ]);

        if (!$redirection instanceof Redirection || $redirection->getSourceEmail() !== $emailAccount->getEmail()) {
            throw $this->createAccessDeniedException('Modification non autorisée.');
        }

        $destinationEmail = mb_strtolower(trim((string) $request->request->get('destinationEmail', '')));
        $startsAt = $this->parseDateTime((string) $request->request->get('startsAt', ''));
        $endsAt = $this->parseDateTime((string) $request->request->get('endsAt', ''));
        if ($startsAt instanceof \DateTimeImmutable && $endsAt instanceof \DateTimeImmutable && $startsAt > $endsAt) {
            $this->addFlash('error', 'La date de fin doit être après la date de début.');

            return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
        }
        if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Adresse de destination invalide.');

            return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
        }

        if ($destinationEmail === $emailAccount->getEmail()) {
            $this->addFlash('error', 'La destination doit être différente de votre compte.');

            return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
        }

        try {
            if (null !== $redirection->getOvhId()) {
                $this->ovhRedirectionManager->update($redirection, $emailAccount, $destinationEmail);
            }

            $redirection
                ->setDestinationEmail($destinationEmail)
                ->setStartsAt($startsAt)
                ->setEndsAt($endsAt);
            $this->entityManager->flush();

            $this->addFlash('success', 'Redirection modifiée.');
        } catch (\Throwable $exception) {
            $this->addFlash('error', "Erreur modification redirection: {$exception->getMessage()}");
        }

        return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
    }

    #[Route('/redirections/{id}/supprimer', name: 'app_user_redirection_delete', methods: ['POST'])]
    public function deleteRedirection(Request $request, int $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('delete_redirection_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_user_redirections');
        }

        $emailAccount = $this->resolveManagedEmailAccount($user, $request->request->get('accountId'));
        if (!$emailAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Synchronisez d’abord votre compte e-mail.');

            return $this->redirectToRoute('app_user_redirections');
        }

        /** @var Redirection|null $redirection */
        $redirection = $this->entityManager->getRepository(Redirection::class)->findOneBy([
            'id' => $id,
            'owner' => $emailAccount->getOwner(),
            'emailAccount' => $emailAccount,
        ]);

        if (!$redirection instanceof Redirection || $redirection->getSourceEmail() !== $emailAccount->getEmail()) {
            throw $this->createAccessDeniedException('Suppression non autorisée.');
        }

        try {
            $this->ovhRedirectionManager->delete($redirection, $emailAccount);
            $this->entityManager->remove($redirection);
            $this->entityManager->flush();

            $this->addFlash('success', 'Redirection supprimée.');
        } catch (\Throwable $exception) {
            $this->addFlash('error', "Erreur suppression redirection: {$exception->getMessage()}");
        }

        return $this->redirectToRoute('app_user_redirections', $this->managedAccountRouteParams($emailAccount));
    }

    private function resolveManagedEmailAccount(User $user, mixed $rawAccountId): ?EmailAccount
    {
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

    /**
     * @param array{
     *     enabled: bool,
     *     subject: ?string,
     *     message: ?string,
     *     startsAt: ?\DateTimeImmutable,
     *     endsAt: ?\DateTimeImmutable
     * } $snapshot
     */
    private function upsertLocalResponderFromSnapshot(User $user, EmailAccount $emailAccount, array $snapshot): Responder
    {
        /** @var Responder|null $responder */
        $responder = $this->entityManager->getRepository(Responder::class)->findOneBy([
            'owner' => $user,
            'emailAccount' => $emailAccount,
        ]);

        if (!$responder instanceof Responder) {
            $responder = new Responder($user, $emailAccount);
            $this->entityManager->persist($responder);
        }

        $responder
            ->setEnabled($snapshot['enabled'])
            ->setSubject($snapshot['subject'])
            ->setMessage($snapshot['message'])
            ->setStartsAt($snapshot['startsAt'])
            ->setEndsAt($snapshot['endsAt']);

        $this->entityManager->flush();

        return $responder;
    }
}
