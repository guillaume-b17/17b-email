# OVH Mail Manager (Symfony 7)

Application de gestion OVH e-mail, reconstruite from scratch avec une base maintenable.

## Stack

- Symfony 7 / PHP 8.2+
- Doctrine ORM + Migrations
- Twig + Tailwind CSS
- Interface en francais
- Admin full Twig (sans EasyAdmin)

## Arborescence initiale

```text
.
├── assets/
│   ├── app.js
│   └── styles/app.css
├── config/
│   ├── packages/
│   │   ├── doctrine.yaml
│   │   ├── rate_limiter.yaml
│   │   ├── security.yaml
│   │   ├── symfonycasts_tailwind.yaml
│   │   └── twig.yaml
│   └── routes.yaml
├── src/
│   ├── Admin/
│   │   ├── Application/
│   │   ├── Controller/
│   │   ├── Domain/
│   │   └── Infrastructure/
│   ├── Domain/
│   │   ├── Application/
│   │   ├── Controller/
│   │   ├── Domain/
│   │   └── Infrastructure/
│   ├── Security/
│   │   ├── Application/
│   │   ├── Controller/
│   │   ├── Domain/
│   │   └── Infrastructure/
│   ├── Shared/
│   │   ├── Application/
│   │   ├── Controller/
│   │   ├── Domain/
│   │   └── Infrastructure/
│   ├── Sync/
│   │   ├── Application/
│   │   ├── Controller/
│   │   ├── Domain/
│   │   └── Infrastructure/
│   └── User/
│       ├── Application/
│       ├── Controller/
│       ├── Domain/
│       └── Infrastructure/
└── templates/
    ├── admin/
    ├── auth/
    ├── components/ui/
    ├── shared/
    ├── sync/
    └── user/
```

## Variables d'environnement

Variables deja preparees dans `.env`:

- `APP_ALLOWED_DOMAINS`
- `APP_ADMIN_EMAILS`
- `OVH_APP_KEY`
- `OVH_APP_SECRET`
- `OVH_CONSUMER_KEY`
- `OVH_ENDPOINT`
- `MAILER_DSN`
- `EMAIL_LOGIN_DEV_CODE`

## Plan d'implementation detaille

1. **Socle projet (fait)**
   - Initialisation Symfony, Doctrine, Twig, Security, Tailwind
   - Base UI dark responsive
   - Structure modulaire `Security`, `Sync`, `Admin`, `Domain`

2. **Modele de donnees + migrations (fait)**
   - Creer les entites `User`, `EmailLoginChallenge`, `EmailAccount`, `Redirection`, `Responder`, `ResponderMessageTemplate`, `OrganizationSetting`
   - Ajouter contraintes DB, index, unicites et relations
   - Generer migration initiale

3. **Authentification OTP (en cours)**
   - Form demande OTP
   - Service generation + hash OTP + expiration + usage unique
   - Envoi mail et verification OTP
   - Login neutre et rate limit
   - Provisioning utilisateur si email autorise

4. **Synchronisation OVH**
   - Client OVH central
   - Orchestrateur sync globale
   - Sync au login + ecran `/sync` avec progression simulee
   - Actions admin de resynchronisation

5. **Portail utilisateur**
   - Liste boites email du user
   - CRUD redirections/repondeurs avec ownership strict backend

6. **Backoffice admin full Twig**
   - Dashboard + listes globales
   - Gestion templates repondeur
   - Reglage telephone agence

7. **Cron nocturne**
   - Commande `app:sync:nightly`
   - Logs lisibles succes/erreurs
   - Exemple cron dans ce README

8. **Qualite**
   - Validation serveur + CSRF
   - Logs metier
   - Tests OTP + ownership + sync

## Commandes utiles

```bash
composer install
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate -n
php bin/console tailwind:build
symfony server:start -d
```

## Tests rapides

```bash
php bin/console lint:container
php bin/console lint:twig templates
php bin/phpunit
```
