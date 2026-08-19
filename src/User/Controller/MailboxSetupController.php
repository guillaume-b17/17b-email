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
    private const CLIENTS = [
        'outlook' => 'Outlook',
        'apple-mac' => 'Mail sur Mac',
        'iphone' => 'iPhone ou iPad',
        'thunderbird' => 'Thunderbird',
        'gmail' => 'Gmail',
        'autre' => 'Autre logiciel',
    ];

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

        $client = $this->normalizeClient((string) $request->query->get('client', ''));
        $step = $this->normalizeStep((string) $request->query->get('step', 'intro'), $client);
        $steps = $this->stepsForClient($client);
        $stepIndex = array_search($step, $steps, true);
        $previousStep = false !== $stepIndex && $stepIndex > 0 ? $steps[$stepIndex - 1] : null;
        $nextStep = false !== $stepIndex && isset($steps[$stepIndex + 1]) ? $steps[$stepIndex + 1] : null;
        $targetEmail = $setupAccount?->getEmail() ?? $migration?->getTargetEmail();
        $canSetup = null !== $targetEmail && '' !== $targetEmail;

        return $this->render('user/mailbox_setup.html.twig', [
            'emailAccount' => $setupAccount,
            'mailboxMigration' => $migration,
            'mailPassword' => $password,
            'passwordError' => $passwordError,
            'mailClientSettings' => $this->mailClientSettings->toArray(),
            'currentAccountId' => $setupAccount?->getId() ?? $currentAccount?->getId(),
            'hideAppHeader' => $canSetup,
            'step' => $step,
            'client' => $client,
            'clients' => self::CLIENTS,
            'clientLabel' => self::CLIENTS[$client] ?? null,
            'previousStep' => $previousStep,
            'nextStep' => $nextStep,
            'stepNumber' => false === $stepIndex ? 1 : $stepIndex + 1,
            'stepCount' => count($steps),
            'targetEmail' => $targetEmail,
            'sourceEmail' => $migration?->getSourceEmail() ?? $user->getEmail(),
            'canSetup' => $canSetup,
            'canChangePassword' => $setupAccount instanceof EmailAccount,
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
        $wizardParams = $this->wizardRouteParams(
            $setupAccount ?? $currentAccount,
            (string) $request->request->get('step', 'identifiants'),
            (string) $request->request->get('client', '')
        );
        if (!$setupAccount instanceof EmailAccount) {
            $this->addFlash('error', 'Aucun compte 17b.fr n’est associé à cet utilisateur.');

            return $this->redirectToRoute('app_user_mailbox_setup', $wizardParams);
        }

        $password = (string) $request->request->get('password', '');
        $confirmation = (string) $request->request->get('passwordConfirmation', '');
        if ($password !== $confirmation) {
            $this->addFlash('error', 'Les deux mots de passe ne correspondent pas.');

            return $this->redirectToRoute('app_user_mailbox_setup', $wizardParams);
        }

        try {
            $this->mailboxPasswordGenerator->assertValid($password);
            $this->domainMigrationProvisioner->changeMailboxPassword($setupAccount, $password);
            $this->addFlash('success', 'Mot de passe du compte 17b.fr mis à jour. Utilise-le dans ton logiciel de messagerie.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->addFlash('error', sprintf('Impossible de changer le mot de passe OVH: %s', $exception->getMessage()));
        }

        return $this->redirectToRoute('app_user_mailbox_setup', $wizardParams);
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

            return $this->redirectToRoute(
                'app_user_mailbox_setup',
                $this->wizardRouteParams($currentAccount, 'identifiants', (string) $request->query->get('client', ''))
            );
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
            $this->addFlash('error', 'Définis d’abord un mot de passe pour télécharger le profil Apple Mail.');

            return $this->redirectToRoute(
                'app_user_mailbox_setup',
                $this->wizardRouteParams($setupAccount, 'identifiants', (string) $request->query->get('client', ''))
            );
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

    /**
     * @return list<string>
     */
    private function stepsForClient(string $client): array
    {
        $steps = ['intro', 'client', 'identifiants'];
        if ('' === $client) {
            return $steps;
        }

        if (\in_array($client, ['apple-mac', 'iphone'], true)) {
            $steps[] = 'profil';
        }

        $steps[] = 'ajout';
        $steps[] = 'parametres';
        $steps[] = 'termine';

        return $steps;
    }

    private function normalizeClient(string $client): string
    {
        return isset(self::CLIENTS[$client]) ? $client : '';
    }

    private function normalizeStep(string $step, string $client): string
    {
        $steps = $this->stepsForClient($client);
        if (\in_array($step, $steps, true)) {
            return $step;
        }

        return 'intro';
    }

    /**
     * @return array<string, int|string>
     */
    private function wizardRouteParams(?EmailAccount $emailAccount, string $step, string $client): array
    {
        $client = $this->normalizeClient($client);
        $step = $this->normalizeStep($step, $client);
        $params = $this->managedMailboxResolver->routeParams($emailAccount);
        $params['step'] = $step;
        if ('' !== $client) {
            $params['client'] = $client;
        }

        return $params;
    }
}
