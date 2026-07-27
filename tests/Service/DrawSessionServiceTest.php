<?php

namespace App\Tests\Service;

use App\Exception\DrawSessionProblem;
use App\Service\DrawSessionService;
use PHPUnit\Framework\TestCase;

class DrawSessionServiceTest extends TestCase
{
    private function serviceWithoutDependencies(): DrawSessionService
    {
        return (new \ReflectionClass(DrawSessionService::class))
            ->newInstanceWithoutConstructor();
    }

    public function testImpIsPlacedAtConfiguredSuccessfulDrawOrder(): void
    {
        $roles = [
            ['id' => 'washerwoman'],
            ['id' => 'imp'],
            ['id' => 'baron'],
            ['id' => 'chef'],
        ];

        $queue = $this->serviceWithoutDependencies()->buildDrawQueue($roles, 3);

        self::assertSame('imp', $queue[2]['id']);
        self::assertCount(4, $queue);
    }

    public function testDuplicateSnapshotsArePreserved(): void
    {
        $roles = [
            ['id' => 'chef', 'copy' => 1],
            ['id' => 'chef', 'copy' => 2],
            ['id' => 'imp', 'copy' => 1],
        ];

        $queue = $this->serviceWithoutDependencies()->buildDrawQueue($roles, null);

        self::assertCount(3, $queue);
        self::assertEqualsCanonicalizing($roles, $queue);
    }

    public function testInvalidImpOrderIsRejected(): void
    {
        $this->expectException(DrawSessionProblem::class);
        $this->expectExceptionCode(0);

        $this->serviceWithoutDependencies()->buildDrawQueue(
            [['id' => 'imp']],
            2
        );
    }
}
