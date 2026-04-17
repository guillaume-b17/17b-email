# Module Security

Contient l'authentification OTP, les gardes d'acces et les verifications de roles.

## Code admin (prod)

Pour limiter les envois d'emails (ex: quota Brevo), il est possible d'activer un code de connexion **uniquement pour les comptes admin**.

- Definir `EMAIL_LOGIN_ADMIN_CODE` en variable d'environnement (prod).
- Si l'email saisi est admin (`APP_ADMIN_EMAILS` + fallback `AdminAccounts::EMAILS`), l'etape "demande OTP" **n'envoie pas d'email**.
- A l'etape de verification, saisir `EMAIL_LOGIN_ADMIN_CODE` permet de se connecter.
