<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\EmailAccount;
use App\User\Service\ManagedMailboxResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MailboxExtension extends AbstractExtension
{
    public function __construct(
        private readonly ManagedMailboxResolver $managedMailboxResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('owned_mailboxes', $this->managedMailboxResolver->listForCurrentContext(...)),
            new TwigFunction('current_mailbox', $this->managedMailboxResolver->resolve(...)),
            new TwigFunction('target_mailbox', $this->managedMailboxResolver->findCurrentTargetAccount(...)),
            new TwigFunction('mailbox_route_params', $this->routeParams(...)),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function routeParams(?EmailAccount $emailAccount = null): array
    {
        if ($emailAccount instanceof EmailAccount) {
            return $this->managedMailboxResolver->routeParams($emailAccount);
        }

        return $this->managedMailboxResolver->currentRouteParams();
    }
}
