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
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly DomainMigrationProvisioner $domainMigrationProvisioner,
        private readonly AppleMailProfileGenerator $appleMailProfileGenerator,
        private readonly MailClientSettings $mailClientSettings,
        private readonly MailboxPasswordGenerator $mailboxPasswordGenerator,
    ) {
    }

    #[Route('', name: 'app_user_mailbox_setup', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        $emailAccount = $this->resolveManagedEmailAccount($user, $request->query->get('accountId'));
        $migration = $this->resolveMigration($user, $emailAccount);
        $password = null;
        $passwordError = null;

        if ($migration instanceof MailboxMigration && $migration->isReadyForClientSetup()) {
            try {
                $password = $this->domainMigrationProvisioner->decryptPassword($migration);
            } catch (\Throwable $exception) {
                $passwordError = $exception->getMessage();
            }
        }

        return $this->render('user/mailbox_setup.html.twig', [
            'emailAccount' => $emailAccount,
            'mailboxMigration' => $migration,
            'mailPassword' => $password,
            'passwordError' => $passwordError,
            'mailClientSettings' => $this->mailClientSettings->toArray(),
            'currentAccountId' => $emailAccount?->getId(),
            'isAdminContext' => $this->isGranted('ROLE_ADMIN'),
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

        $emailAccount = $this->resolveManagedEmailAccount($user, $request->request->get('accountId'));
        $migration = $this->resolveMigration($user, $emailAccount);
        if (!$migration instanceof MailboxMigration) {
            $this->addFlash('error', 'Aucun nouveau compte 17b.fr n’est associé à cet utilisateur.');

            return $this->redirectToRoute('app_user_mailbox_setup', $this->managedAccountRouteParams($emailAccount));
        }

        $password = (string) $request->request->get('password', '');
        $confirmation = (string) $request->request->get('passwordConfirmation', '');
        if ($password !== $confirmation) {
            $this->addFlash('error', 'Les deux mots de passe ne correspondent pas.');

            return $this->redirectToRoute('app_user_mailbox_setup', $this->managedAccountRouteParams($emailAccount));
        }

        try {
            $this->mailboxPasswordGenerator->assertValid($password);
            $this->domainMigrationProvisioner->changeClientPassword($migration, $password);
            $this->addFlash('success', 'Mot de passe du compte 17b.fr mis à jour. Utilisez-le dans votre logiciel de messagerie.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->addFlash('error', sprintf('Impossible de changer le mot de passe OVH: %s', $exception->getMessage()));
        }

        return $this->redirectToRoute('app_user_mailbox_setup', $this->managedAccountRouteParams($emailAccount));
    }

    #[Route('/apple-mail', name: 'app_user_mailbox_setup_apple', methods: ['GET'])]
    public function appleProfile(Request $request): Response
    {
        $user = $this->requireUser();
        $emailAccount = $this->resolveManagedEmailAccount($user, $request->query->get('accountId'));
        $migration = $this->resolveMigration($user, $emailAccount);
        if (!$migration instanceof MailboxMigration || !$migration->isReadyForClientSetup()) {
            $this->addFlash('error', 'Le profil Apple Mail n’est pas encore disponible.');

            return $this->redirectToRoute('app_user_mailbox_setup', $this->managedAccountRouteParams($emailAccount));
        }

        try {
            $password = $this->domainMigrationProvisioner->decryptPassword($migration);
        } catch (\Throwable) {
            $password = null;
        }

        if (null === $password || '' === $password) {
            $this->addFlash('error', 'Le mot de passe du nouveau compte est indisponible. Définissez-en un depuis cette page.');

            return $this->redirectToRoute('app_user_mailbox_setup', $this->managedAccountRouteParams($emailAccount));
        }

        $profile = $this->appleMailProfileGenerator->generate(
            $migration->getTargetEmail(),
            $password,
            $user->displayName() ?? $migration->getTargetEmail(),
            $this->mailClientSettings
        );

        $filename = sprintf('%s.mobileconfig', str_replace('@', '-at-', $migration->getTargetEmail()));

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

    private function resolveMigration(User $user, ?EmailAccount $emailAccount): ?MailboxMigration
    {
        $sourceEmail = $emailAccount instanceof EmailAccount ? $emailAccount->getEmail() : $user->getEmail();
        $migration = $this->domainMigrationProvisioner->findForEmail($sourceEmail);
        if ($migration instanceof MailboxMigration) {
            return $migration;
        }

        return $this->domainMigrationProvisioner->findForEmail($user->getEmail());
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
