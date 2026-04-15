<?php

declare(strict_types=1);

namespace App\Security\Controller;

use App\Entity\EmailLoginChallenge;
use App\Entity\User;
use App\Security\Authenticator\OtpLoginAuthenticator;
use App\Security\Form\RequestOtpType;
use App\Security\Form\VerifyOtpType;
use App\Security\Service\AdminRoleResolver;
use App\Security\Service\AllowedEmailChecker;
use App\Security\Service\OtpCodeManager;
use App\Sync\Service\UserMailboxSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class OtpLoginController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly OtpCodeManager $otpCodeManager,
        private readonly AllowedEmailChecker $allowedEmailChecker,
        private readonly AdminRoleResolver $adminRoleResolver,
        private readonly UserMailboxSynchronizer $userMailboxSynchronizer,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.otp_request')]
        private readonly RateLimiterFactory $otpRequestLimiter,
        #[Autowire(service: 'limiter.otp_verify')]
        private readonly RateLimiterFactory $otpVerifyLimiter,
        #[Autowire('%env(string:EMAIL_LOGIN_DEV_CODE)%')]
        private readonly string $devOtpCode,
    ) {
    }

    #[Route('/', name: 'app_auth_request_home', methods: ['GET', 'POST'])]
    #[Route('/connexion', name: 'app_auth_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_user_account');
        }

        $form = $this->createForm(RequestOtpType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $form->get('email')->getData()));
            $limiter = $this->otpRequestLimiter->create($email.'|'.$request->getClientIp());
            $rateLimit = $limiter->consume();

            if ($rateLimit->isAccepted() && $this->allowedEmailChecker->isAllowed($email)) {
                $code = '' !== trim($this->devOtpCode) ? trim($this->devOtpCode) : $this->otpCodeManager->generateCode();
                $challenge = new EmailLoginChallenge(
                    $email,
                    $this->otpCodeManager->hashCode($email, $code),
                    new \DateTimeImmutable('+10 minutes')
                );
                $challenge->setRequestIp($request->getClientIp());
                $challenge->setUserAgent($request->headers->get('User-Agent'));

                $this->entityManager->persist($challenge);
                $this->entityManager->flush();
                $this->sendOtpEmail($email, $code);
            }

            $request->getSession()->set('pending_login_email', $email);
            $this->addFlash('success', 'Si cette adresse est autorisee, un code a ete envoye.');

            return $this->redirectToRoute('app_auth_verify');
        }

        return $this->render('auth/request_otp.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/connexion/verification', name: 'app_auth_verify', methods: ['GET', 'POST'])]
    public function verify(Request $request): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_user_account');
        }

        $email = (string) $request->getSession()->get('pending_login_email', '');
        if ('' === $email) {
            return $this->redirectToRoute('app_auth_request');
        }

        $form = $this->createForm(VerifyOtpType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->otpVerifyLimiter->create($email.'|'.$request->getClientIp());
            $rateLimit = $limiter->consume();
            $providedCode = trim((string) $form->get('code')->getData());
            $challenge = $this->entityManager->getRepository(EmailLoginChallenge::class)->findOneBy(
                ['email' => $email, 'consumedAt' => null],
                ['id' => 'DESC']
            );

            if (!$rateLimit->isAccepted() || !$challenge instanceof EmailLoginChallenge || $challenge->getExpiresAt() < new \DateTimeImmutable()) {
                $this->addFlash('error', 'Code invalide ou expire.');

                return $this->redirectToRoute('app_auth_verify');
            }

            $challenge->incrementAttemptCount();

            if ($challenge->getAttemptCount() > 5) {
                $this->entityManager->flush();
                $this->addFlash('error', 'Code invalide ou expire.');

                return $this->redirectToRoute('app_auth_verify');
            }

            $isValidCode = $this->otpCodeManager->verify($email, $providedCode, $challenge->getCodeHash());
            if (!$isValidCode) {
                $this->entityManager->flush();
                $this->addFlash('error', 'Code invalide ou expire.');

                return $this->redirectToRoute('app_auth_verify');
            }

            $challenge->markAsConsumed();
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

            if (!$user instanceof User) {
                if (!$this->allowedEmailChecker->isAllowed($email)) {
                    $this->entityManager->flush();
                    $this->addFlash('error', 'Code invalide ou expire.');

                    return $this->redirectToRoute('app_auth_verify');
                }

                $user = new User($email);
                $this->entityManager->persist($user);
            }

            $user->setRoles($this->adminRoleResolver->resolveRoles($email));
            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            try {
                $this->userMailboxSynchronizer->synchronize($user);
            } catch (\Throwable $exception) {
                $this->logger->warning('Synchronisation OVH au login impossible', [
                    'email' => $user->getEmail(),
                    'exception' => $exception->getMessage(),
                ]);
            }

            $request->getSession()->remove('pending_login_email');
            $response = $this->security->login($user, OtpLoginAuthenticator::class, 'main');

            return $response ?? $this->redirectToRoute('app_user_account');
        }

        return $this->render('auth/verify_otp.html.twig', [
            'email' => $email,
            'form' => $form->createView(),
        ]);
    }

    private function sendOtpEmail(string $email, string $code): void
    {
        $mail = (new TemplatedEmail())
            ->from(new Address('no-reply@b17.fr', 'OVH Mail Manager'))
            ->to($email)
            ->subject('Votre code de connexion')
            ->htmlTemplate('auth/email/otp_code.html.twig')
            ->context([
                'code' => $code,
            ]);

        try {
            $this->mailer->send($mail);
        } catch (\Throwable $exception) {
            $this->logger->error('Echec envoi OTP', [
                'email' => $email,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
