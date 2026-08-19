<?php

declare(strict_types=1);

namespace App\Tests\Security\Service;

use App\Entity\EmailAccount;
use App\Entity\User;
use App\Security\Service\AdminRoleResolver;
use App\Security\Service\LoginUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class LoginUserResolverTest extends TestCase
{
    public function testMapsTargetDomainLoginToSourceUser(): void
    {
        $sourceUser = new User('jean.dupont@b17.fr');
        $resolver = $this->createResolver(
            $sourceUser,
            new AdminRoleResolver(['jean.dupont@b17.fr'])
        );

        $resolved = $resolver->resolveOrCreate('jean.dupont@17b.fr');

        self::assertSame($sourceUser, $resolved);
        self::assertContains('ROLE_ADMIN', $resolved->getRoles());
    }

    public function testPrefersSourceUserOverStrayTargetUser(): void
    {
        $sourceUser = new User('jean.dupont@b17.fr');
        $strayTargetUser = new User('jean.dupont@17b.fr');
        $resolver = $this->createResolver(
            $sourceUser,
            new AdminRoleResolver([]),
            new EmailAccount($strayTargetUser, 'jean.dupont@17b.fr', '17b.fr'),
            $strayTargetUser
        );

        self::assertSame($sourceUser, $resolver->resolveOrCreate('jean.dupont@17b.fr'));
        self::assertSame(['ROLE_USER'], $sourceUser->getRoles());
    }

    private function createResolver(
        User $sourceUser,
        AdminRoleResolver $roleResolver,
        ?EmailAccount $targetAccount = null,
        ?User $strayTargetUser = null,
    ): LoginUserResolver {
        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($sourceUser, $strayTargetUser): ?User {
                return match ($criteria['email'] ?? null) {
                    $sourceUser->getEmail() => $sourceUser,
                    $strayTargetUser?->getEmail() => $strayTargetUser,
                    default => null,
                };
            }
        );

        $accountRepository = $this->createMock(EntityRepository::class);
        $accountRepository->method('findOneBy')->willReturn($targetAccount);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static function (string $class) use ($userRepository, $accountRepository): object {
                return User::class === $class ? $userRepository : $accountRepository;
            }
        );
        $entityManager->expects(self::never())->method('persist');

        return new LoginUserResolver($entityManager, $roleResolver, 'b17.fr', '17b.fr');
    }
}
