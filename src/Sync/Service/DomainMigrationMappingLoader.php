<?php

declare(strict_types=1);

namespace App\Sync\Service;

final class DomainMigrationMappingLoader
{
    /**
     * @return list<array{sourceEmail: string, targetLocalPart: string, description: ?string}>
     */
    public function load(string $path, string $sourceDomain): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Fichier de mapping introuvable: %s', $path));
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Impossible de lire le mapping: %s', $path));
        }

        try {
            $header = null;
            while (($rawHeader = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($this->isEmptyOrComment($rawHeader)) {
                    continue;
                }

                $header = $rawHeader;
                break;
            }

            if (!is_array($header)) {
                throw new \RuntimeException('Le fichier de mapping est vide.');
            }

            $columns = $this->normalizeHeader($header);
            $rows = [];
            $lineNumber = 1;

            while (($raw = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                ++$lineNumber;
                if ($this->isEmptyOrComment($raw)) {
                    continue;
                }

                $rows[] = $this->parseRow($raw, $columns, $sourceDomain, $lineNumber);
            }
        } finally {
            fclose($handle);
        }

        if ([] === $rows) {
            throw new \RuntimeException('Aucune ligne de mapping exploitable.');
        }

        $this->assertUniqueTargets($rows);

        return $rows;
    }

    /**
     * @param list<string|null> $header
     * @return array{source: int, target: int, description: ?int}
     */
    private function normalizeHeader(array $header): array
    {
        $normalized = [];
        foreach ($header as $index => $column) {
            $normalized[mb_strtolower(trim((string) $column))] = $index;
        }

        if (!isset($normalized['source_email'])) {
            throw new \RuntimeException('Colonne obligatoire manquante: source_email');
        }

        if (!isset($normalized['target_local_part'])) {
            throw new \RuntimeException('Colonne obligatoire manquante: target_local_part');
        }

        return [
            'source' => $normalized['source_email'],
            'target' => $normalized['target_local_part'],
            'description' => $normalized['description'] ?? null,
        ];
    }

    /**
     * @param list<string|null> $raw
     */
    private function isEmptyOrComment(array $raw): bool
    {
        $joined = trim(implode('', array_map(static fn ($value): string => (string) $value, $raw)));
        if ('' === $joined) {
            return true;
        }

        $first = trim((string) ($raw[0] ?? ''));

        return str_starts_with($first, '#');
    }

    /**
     * @param list<string|null> $raw
     * @param array{source: int, target: int, description: ?int} $columns
     * @return array{sourceEmail: string, targetLocalPart: string, description: ?string}
     */
    private function parseRow(array $raw, array $columns, string $sourceDomain, int $lineNumber): array
    {
        $sourceEmail = mb_strtolower(trim((string) ($raw[$columns['source']] ?? '')));
        $targetLocalPart = mb_strtolower(trim((string) ($raw[$columns['target']] ?? '')));
        $description = null;
        if (null !== $columns['description']) {
            $descriptionValue = trim((string) ($raw[$columns['description']] ?? ''));
            $description = '' !== $descriptionValue ? $descriptionValue : null;
        }

        if (!filter_var($sourceEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException(sprintf('Ligne %d: adresse source invalide (%s).', $lineNumber, $sourceEmail));
        }

        [, $domain] = explode('@', $sourceEmail, 2);
        if ($domain !== mb_strtolower($sourceDomain)) {
            throw new \RuntimeException(sprintf(
                'Ligne %d: %s n’appartient pas au domaine source %s.',
                $lineNumber,
                $sourceEmail,
                $sourceDomain
            ));
        }

        [$sourceLocalPart] = explode('@', $sourceEmail, 2);
        if ('' === $targetLocalPart) {
            $targetLocalPart = $sourceLocalPart;
        }

        if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $targetLocalPart)) {
            throw new \RuntimeException(sprintf('Ligne %d: identifiant cible invalide (%s).', $lineNumber, $targetLocalPart));
        }

        return [
            'sourceEmail' => $sourceEmail,
            'targetLocalPart' => $targetLocalPart,
            'description' => $description,
        ];
    }

    /**
     * @param list<array{sourceEmail: string, targetLocalPart: string, description: ?string}> $rows
     */
    private function assertUniqueTargets(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row['targetLocalPart']])) {
                throw new \RuntimeException(sprintf(
                    'Identifiant cible en double dans le mapping: %s',
                    $row['targetLocalPart']
                ));
            }

            $seen[$row['targetLocalPart']] = true;
        }
    }
}
