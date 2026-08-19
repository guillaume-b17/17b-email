<?php

declare(strict_types=1);

namespace App\Sync\Service;

use Symfony\Component\Uid\Uuid;

final class AppleMailProfileGenerator
{
    public function generate(
        string $email,
        string $password,
        string $displayName,
        MailClientSettings $settings,
    ): string {
        $incomingUuid = $this->uuid();
        $profileUuid = $this->uuid();
        $safeEmail = $this->escape($email);
        $safePassword = $this->escape($password);
        $safeName = $this->escape('' !== trim($displayName) ? $displayName : $email);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>PayloadContent</key>
    <array>
        <dict>
            <key>EmailAccountDescription</key>
            <string>{$safeEmail}</string>
            <key>EmailAccountName</key>
            <string>{$safeName}</string>
            <key>EmailAccountType</key>
            <string>EmailTypeIMAP</string>
            <key>EmailAddress</key>
            <string>{$safeEmail}</string>
            <key>IncomingMailServerAuthentication</key>
            <string>EmailAuthPassword</string>
            <key>IncomingMailServerHostName</key>
            <string>{$this->escape($settings->imapHost)}</string>
            <key>IncomingMailServerPortNumber</key>
            <integer>{$settings->imapPort}</integer>
            <key>IncomingMailServerUseSSL</key>
            <true/>
            <key>IncomingMailServerUsername</key>
            <string>{$safeEmail}</string>
            <key>IncomingPassword</key>
            <string>{$safePassword}</string>
            <key>OutgoingMailServerAuthentication</key>
            <string>EmailAuthPassword</string>
            <key>OutgoingMailServerHostName</key>
            <string>{$this->escape($settings->smtpHost)}</string>
            <key>OutgoingMailServerPortNumber</key>
            <integer>{$settings->smtpPort}</integer>
            <key>OutgoingMailServerUseSSL</key>
            <true/>
            <key>OutgoingMailServerUsername</key>
            <string>{$safeEmail}</string>
            <key>OutgoingPasswordSameAsIncomingPassword</key>
            <true/>
            <key>PayloadDisplayName</key>
            <string>{$safeEmail}</string>
            <key>PayloadIdentifier</key>
            <string>fr.17b.mail.account.{$incomingUuid}</string>
            <key>PayloadType</key>
            <string>com.apple.mail.managed</string>
            <key>PayloadUUID</key>
            <string>{$incomingUuid}</string>
            <key>PayloadVersion</key>
            <integer>1</integer>
        </dict>
    </array>
    <key>PayloadDisplayName</key>
    <string>Compte {$safeEmail}</string>
    <key>PayloadIdentifier</key>
    <string>fr.17b.mail.profile.{$profileUuid}</string>
    <key>PayloadOrganization</key>
    <string>17b</string>
    <key>PayloadRemovalDisallowed</key>
    <false/>
    <key>PayloadType</key>
    <string>Configuration</string>
    <key>PayloadUUID</key>
    <string>{$profileUuid}</string>
    <key>PayloadVersion</key>
    <integer>1</integer>
</dict>
</plist>

XML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function uuid(): string
    {
        return strtoupper(Uuid::v4()->toRfc4122());
    }
}
