<?php

declare(strict_types=1);

namespace App\Security\Service;

final class AdminAccounts
{
    /**
     * Comptes admin en dur (fallback, vide par defaut : tout passe par APP_ADMIN_EMAILS).
     *
     * @var list<string>
     */
    public const EMAILS = [];
}
