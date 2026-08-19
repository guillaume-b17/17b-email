<?php

declare(strict_types=1);

namespace App\User\Controller;

use App\Entity\EmailAccount;
use App\Entity\MailboxMigration;
use App\Entity\User;
use App\Sync\Service\AppleMailProfileGenerator;
use App\Sync\Service\DomainMigrationProvisioner;
use App\Sync\Service\MailClientSettings;
use App\Sync\Service\MailboxPasswordGenerator;
use App\User\Service\ManagedMailboxResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/compte/messagerie')]
final class MailboxSetupController extends AbstractController
{
    public function __construct(
        private readonly DomainMigrationProvisioner $domainMigrationProvisioner,
        private readonly AppleMailProfileGenerator $appleMailProfileGenerator,
        private readonly MailClientSettings $mailClientSettings,
        private readonly MailboxPasswordGenerator $mailboxPasswordGenerator,
        private readonly ManagedMailboxResolver $managedMailboxResolver,
    ) {
    }

    #[Route('', name: 'app_user_mailbox_setup', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        $currentAccount = $this->managedMailboxResolver->resolve($user, $request->query->get('accountId'));
        $setupAccount = $this->managedMailboxResolver->findOwnedTargetAccount(
            $currentAccount instanceof EmailAccount ? $currentAccount->getOwner() : $user,
            $currentAccount
        );
        $migration = $setupAccount instanceof EmailAccount
            ? $this->domainMigrationProvisioner->findForEmail($setupAccount->getEmail())
            : $this->domainMigrationProvisioner->findForEmail($user->getEmail());
        $password = null;
        $passwordError = null;

        if ($migration instanceof MailboxMigration) {
            try {
                $password = $this->domainMigrationProvisioner->decryptPassword($migration);
            } catch (\Throwable $exception) {
                $passwordError = $exception->getMessage();
            }
        }

        return $this->render('user/mailbox_setup.html.twig', [
            'emailAccount' => $setupAccount,
            'mailboxMigration' => $migration,
            'mailPassword' => $password,
            'passwordError' => $passwordError,
            'mailClientSettings' => $this->mailClientSettings->toArray(),
            'currentAccountId' => $setupAccount?->getId() ?? $currentAccount?->getId(),
        ]);
    }

    #[Route('/mot-de-passe', name: 'app_user_mailbox_setup_password', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$this->isCsrfTokenValid('mailbox_setup_password', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_user_mailbox_setup');
        }

        $currentAccount = $this->managedMailboxResolver->resolve($user, $request->request->get('accountId'));
        $setupAccount = $this->managedMailboxResolver->findOwnedTargetAccount(
            $currentAccount instanceof EmailAccount ? $currentAccount->getOwner() : $user,
            $currentAccount
        );
        if (!$setupAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Aucun compte 17b.fr n’est associé à cet utilisateur.');

            return $this->redirectToRoute('app_user_mailbox_setup', $this->managedMailboxResolver->routeParams($currentAccount));
        }

        $password = (string) $request->request->get('password', '');
        $confirmation = (string) $request->request->get('passwordConfirmation', '');
        if ($password !== $confirmation) {
            $this->addFlash('error', 'Les deux mots de passe ne correspondent pas.');

            return $this->redirectToRoute('app_user_mailbox_setup', $this->managedMailboxResolver->routeParams($setupAccount));
        }

        try {
            $this->mailboxPasswordGenerator->assertValid($password);
            $this->domainMigrationProvisioner->changeMailboxPassword($setupAccount, $password);
            $this->addFlash('success', 'Mot de passe du compte 17b.fr mis à jour. Utilisez-le dans votre logiciel de messagerie.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->addFlash('error', sprintf('Impossible de changer le mot de passe OVH: %s', $exception->getMessage()));
        }

        return $this->redirectToRoute('app_user_mailbox_setup', $this->managedMailboxResolver->routeParams($setupAccount));
    }

    #[Route('/apple-mail', name: 'app_user_mailbox_setup_apple', methods: ['GET'])]
    public function appleProfile(Request $request): Response
    {
        $user = $this->requireUser();
        $currentAccount = $this->managedMailboxResolver->resolve($user, $request->query->get('accountId'));
        $setupAccount = $this->managedMailboxResolver->findOwnedTargetAccount(
            $currentAccount instanceof EmailAccount ? $currentAccount->getOwner() : $user,
            $currentAccount
        );
        if (!$setupAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Aucun compte 17b.fr n’est associé à cet utilisateur.');

            return $this->redirectToRoute('app_user_mailbox_setup', $this->managedMailboxResolver->routeParams($currentAccount));
        }

        $migration = $this->domainMigrationProvisioner->findForEmail($setupAccount->getEmail());
        $password = null;
        if ($migration instanceof MailboxMigration) {
            try {
                $password = $this->domainMigrationProvisioner->decryptPassword($migration);
            } catch (\Throwable) {
                $password = null;
            }
        }

        if (null === $password || '' === $password) {
            $this->addFlash('error', 'Définissez d’abord un mot de passe pour télécharger le profil Apple Mail.');

            return $this->redirectToRoute('app_user_mailbox_setup', $this->managedMailboxResolver->routeParams($setupAccount));
        }

        $profile = $this->appleMailProfileGenerator->generate(
            $setupAccount->getEmail(),
            $password,
            $setupAccount->getOwner()->displayName() ?? $setupAccount->getEmail(),
            $this->mailClientSettings
        );

        $filename = sprintf('%s.mobileconfig', str_replace('@', '-at-', $setupAccount->getEmail()));

        return new Response($profile, Response::HTTP_OK, [
            'Content-Type' => 'application/x-apple-aspen-config; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
