<?php

declare(strict_types=1);

namespace App\Tests\Sync\Service;

use App\Sync\Service\DomainMigrationMappingLoader;
use PHPUnit\Framework\TestCase;

final class DomainMigrationMappingLoaderTest extends TestCase
{
    public function testLoadsRowsAndKeepsRenamedLocalPart(): void
    {
        $path = $this->writeMapping(
            "source_email,target_local_part,description\n".
            "jean.dupont@b17.fr,jean.dupont,Jean Dupont\n".
            "marie.ancienne@b17.fr,marie.nouvelle,Marie Nouvelle\n"
        );

        $rows = (new DomainMigrationMappingLoader())->load($path, 'b17.fr');

        self::assertCount(2, $rows);
        self::assertSame('marie.ancienne@b17.fr', $rows[1]['sourceEmail']);
        self::assertSame('marie.nouvelle', $rows[1]['targetLocalPart']);
        self::assertSame('Marie Nouvelle', $rows[1]['description']);
    }

    public function testIgnoresCommentsBeforeHeader(): void
    {
        $path = $this->writeMapping(
            "# commentaire\n".
            "source_email,target_local_part,description\n".
            "jean.dupont@b17.fr,jean.dupont,\n"
        );

        $rows = (new DomainMigrationMappingLoader())->load($path, 'b17.fr');

        self::assertCount(1, $rows);
        self::assertSame('jean.dupont@b17.fr', $rows[0]['sourceEmail']);
    }

    public function testFillsEmptyTargetWithSourceLocalPart(): void
    {
        $path = $this->writeMapping(
            "source_email,target_local_part,description\n".
            "paul.martin@b17.fr,,Paul Martin\n"
        );

        $rows = (new DomainMigrationMappingLoader())->load($path, 'b17.fr');

        self::assertSame('paul.martin', $rows[0]['targetLocalPart']);
    }

    public function testRejectsDuplicateTargets(): void
    {
        $path = $this->writeMapping(
            "source_email,target_local_part,description\n".
            "a@b17.fr,same,\n".
            "b@b17.fr,same,\n"
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Identifiant cible en double');

        (new DomainMigrationMappingLoader())->load($path, 'b17.fr');
    }

    private function writeMapping(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mapping_');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
