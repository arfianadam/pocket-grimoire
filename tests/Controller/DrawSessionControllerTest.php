<?php

namespace App\Tests\Controller;

use App\Entity\DrawSession;
use App\Repository\DrawSessionRepository;
use App\Service\DrawSessionPublisher;
use App\Tests\Double\TestDrawSessionPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DrawSessionControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\DrawSession session')->execute();
        static::ensureKernelShutdown();
    }

    public function testCompleteLifecycleProtectsPrivateDataAndRetainsReleasedRole(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->request('GET', '/en_GB/');
        self::assertResponseIsSuccessful();
        preg_match(
            '/csrfToken:\s*"([^"]+)"/',
            (string) $client->getResponse()->getContent(),
            $matches
        );
        self::assertNotEmpty($matches[1] ?? null);

        $characters = [
            [
                'id' => 'imp',
                'name' => 'Imp',
                'ability' => 'Each night, choose a player.',
                'team' => 'demon',
                'image' => '/imp.webp',
                'customField' => ['survives' => true],
            ],
            [
                'id' => 'chef',
                'name' => 'Chef',
                'ability' => 'You start knowing evil pairs.',
                'team' => 'townsfolk',
                'image' => '/chef.webp',
            ],
        ];
        $client->jsonRequest('POST', '/en_GB/draw-sessions', [
            '_token' => json_decode('"' . $matches[1] . '"'),
            'characters' => $characters,
            'impDrawOrder' => 1,
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        $publicId = $created['publicId'];
        $hostSecret = $created['hostSecret'];
        self::assertStringNotContainsString($hostSecret, $created['joinUrl']);
        self::assertArrayNotHasKey('mercureUrl', $created);
        self::assertNull($created['hostState']['slots'][0]['role']);

        $client->request('GET', "/en_GB/draw-sessions/{$publicId}");
        self::assertResponseIsSuccessful();
        $publicJson = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Imp', $publicJson);
        self::assertStringNotContainsString('ability', $publicJson);
        self::assertStringNotContainsString('name', $publicJson);

        $client->jsonRequest('POST', "/en_GB/draw-sessions/{$publicId}/claims", [
            'number' => 2,
        ]);
        self::assertResponseStatusCodeSame(201);
        $firstClaim = json_decode((string) $client->getResponse()->getContent(), true);
        $claimSecret = $firstClaim['claimSecret'];
        $assignedRole = $firstClaim['claim']['role'];
        self::assertSame('imp', $assignedRole['id']);

        // A second request for the same number loses the serialized claim.
        $client->jsonRequest('POST', "/en_GB/draw-sessions/{$publicId}/claims", [
            'number' => 2,
        ]);
        self::assertResponseStatusCodeSame(409);

        $client->jsonRequest(
            'PATCH',
            "/en_GB/draw-sessions/{$publicId}/claim",
            ['name' => " \u{2003} "],
            ['HTTP_AUTHORIZATION' => "Bearer {$claimSecret}"]
        );
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest(
            'PATCH',
            "/en_GB/draw-sessions/{$publicId}/claim",
            ['name' => "  Álvaro  "],
            ['HTTP_AUTHORIZATION' => "Bearer {$claimSecret}"]
        );
        self::assertResponseIsSuccessful();
        $completed = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Álvaro', $completed['name']);

        $hostHeaders = ['HTTP_AUTHORIZATION' => "Bearer {$hostSecret}"];
        $client->jsonRequest(
            'PATCH',
            "/en_GB/draw-sessions/{$publicId}/host/slots/2",
            ['name' => 'Renamed'],
            $hostHeaders
        );
        self::assertResponseIsSuccessful();
        self::assertSame(
            'Renamed',
            json_decode((string) $client->getResponse()->getContent(), true)['slots'][1]['name']
        );

        $client->jsonRequest(
            'POST',
            "/en_GB/draw-sessions/{$publicId}/host/slots/2/release",
            [],
            $hostHeaders
        );
        self::assertResponseIsSuccessful();
        $released = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('available', $released['slots'][1]['state']);
        self::assertSame($assignedRole, $released['slots'][1]['role']);

        $client->request(
            'GET',
            "/en_GB/draw-sessions/{$publicId}/claim",
            [],
            [],
            ['HTTP_AUTHORIZATION' => "Bearer {$claimSecret}"]
        );
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest('POST', "/en_GB/draw-sessions/{$publicId}/claims", [
            'number' => 2,
        ]);
        self::assertResponseStatusCodeSame(201);
        $secondClaim = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($assignedRole, $secondClaim['claim']['role']);
        $secondSecret = $secondClaim['claimSecret'];

        $client->jsonRequest(
            'PATCH',
            "/en_GB/draw-sessions/{$publicId}/claim",
            ['name' => 'Second player'],
            ['HTTP_AUTHORIZATION' => "Bearer {$secondSecret}"]
        );
        self::assertResponseIsSuccessful();

        $client->jsonRequest(
            'POST',
            "/en_GB/draw-sessions/{$publicId}/host/end",
            [],
            $hostHeaders
        );
        self::assertResponseIsSuccessful();

        // Ended rooms preserve completed claimant role access until expiry.
        $client->request(
            'GET',
            "/en_GB/draw-sessions/{$publicId}/claim",
            [],
            [],
            ['HTTP_AUTHORIZATION' => "Bearer {$secondSecret}"]
        );
        self::assertResponseIsSuccessful();
        self::assertSame(
            'imp',
            json_decode((string) $client->getResponse()->getContent(), true)['role']['id']
        );

        $publisher = static::getContainer()->get(DrawSessionPublisher::class);
        self::assertInstanceOf(TestDrawSessionPublisher::class, $publisher);
        self::assertCount(8, $publisher->getEvents());
    }

    public function testInvalidCredentialsAndExpiryUseCapabilityStatusCodes(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->request('GET', '/en_GB/');
        preg_match(
            '/csrfToken:\s*"([^"]+)"/',
            (string) $client->getResponse()->getContent(),
            $matches
        );
        $client->jsonRequest('POST', '/en_GB/draw-sessions', [
            '_token' => json_decode('"' . $matches[1] . '"'),
            'characters' => [[
                'id' => 'chef',
                'name' => 'Chef',
                'ability' => 'Ability',
            ]],
            'impDrawOrder' => null,
        ]);
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        $publicId = $created['publicId'];

        $client->request('GET', "/en_GB/draw-sessions/{$publicId}/host");
        self::assertResponseStatusCodeSame(401);
        $client->request(
            'GET',
            "/en_GB/draw-sessions/{$publicId}/host",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('a', 64)]
        );
        self::assertResponseStatusCodeSame(403);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $session = static::getContainer()
            ->get(DrawSessionRepository::class)
            ->findByPublicId($publicId);
        self::assertInstanceOf(DrawSession::class, $session);
        $session->setExpiresAt(new \DateTimeImmutable('-1 second'));
        $entityManager->flush();

        $client->request('GET', "/en_GB/draw-sessions/{$publicId}");
        self::assertResponseStatusCodeSame(410);
    }
}
