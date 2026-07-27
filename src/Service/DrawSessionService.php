<?php

namespace App\Service;

use App\Entity\DrawSession;
use App\Entity\DrawSlot;
use App\Exception\DrawSessionProblem;
use App\Repository\DrawSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

class DrawSessionService
{
    private const MAX_CHARACTERS = 100;

    private $entityManager;
    private $sessions;
    private $publisher;

    public function __construct(
        EntityManagerInterface $entityManager,
        DrawSessionRepository $sessions,
        DrawSessionPublisher $publisher
    ) {
        $this->entityManager = $entityManager;
        $this->sessions = $sessions;
        $this->publisher = $publisher;
    }

    /**
     * @return array{session: DrawSession, hostSecret: string}
     */
    public function create(array $characters, ?int $impDrawOrder): array
    {
        $characters = $this->validateCharacters($characters);
        $roles = $this->buildDrawQueue($characters, $impDrawOrder);
        $now = new \DateTimeImmutable();
        $hostSecret = $this->newToken();
        $session = (new DrawSession())
            ->setPublicId($this->newToken())
            ->setHostSecretHash($this->hashSecret($hostSecret))
            ->setRoleSnapshots($roles)
            ->setCreatedAt($now)
            ->setExpiresAt($now->modify('+24 hours'));

        foreach ($roles as $index => $ignored) {
            $session->addSlot(
                (new DrawSlot())
                    ->setNumber($index + 1)
                    ->setUpdatedAt($now)
            );
        }

        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $this->publisher->publish($session);

        return [
            'session' => $session,
            'hostSecret' => $hostSecret,
        ];
    }

    public function find(string $publicId): DrawSession
    {
        $session = $this->sessions->findByPublicId($publicId);

        if (!$session) {
            throw new DrawSessionProblem(404, 'not_found', 'Draw room not found.');
        }

        $this->assertNotExpired($session);

        return $session;
    }

    /**
     * @return array{session: DrawSession, slot: DrawSlot, claimSecret: string}
     */
    public function claim(string $publicId, int $number): array
    {
        $claimSecret = $this->newToken();
        $result = $this->mutate($publicId, function (DrawSession $session) use (
            $number,
            $claimSecret
        ): DrawSlot {
            $this->assertActive($session);
            $slot = $session->getSlot($number);

            if (!$slot) {
                throw new DrawSessionProblem(422, 'invalid_number', 'Choose a valid number.');
            }

            if ($slot->getState() !== DrawSlot::STATE_AVAILABLE) {
                throw new DrawSessionProblem(409, 'number_unavailable', 'That number has already been claimed.');
            }

            if ($slot->getRoleIndex() === null) {
                $roleIndex = $session->getNextDrawIndex();

                if (!array_key_exists($roleIndex, $session->getRoleSnapshots())) {
                    throw new DrawSessionProblem(409, 'draw_complete', 'Every role has already been assigned.');
                }

                $slot->setRoleIndex($roleIndex);
                $session->setNextDrawIndex($roleIndex + 1);
            }

            $now = new \DateTimeImmutable();
            $slot
                ->setState(DrawSlot::STATE_NAMING)
                ->setClaimSecretHash($this->hashSecret($claimSecret))
                ->setPlayerName(null)
                ->setClaimedAt($now)
                ->setCompletedAt(null)
                ->setUpdatedAt($now);

            return $slot;
        });

        return [
            'session' => $result['session'],
            'slot' => $result['result'],
            'claimSecret' => $claimSecret,
        ];
    }

    public function getClaim(string $publicId, string $claimSecret): array
    {
        $session = $this->find($publicId);
        $slot = $this->findClaimSlot($session, $claimSecret);

        return $this->serializeClaim($session, $slot);
    }

    public function completeClaim(
        string $publicId,
        string $claimSecret,
        $rawName
    ): array {
        $name = $this->validateName($rawName);
        $result = $this->mutate($publicId, function (DrawSession $session) use (
            $claimSecret,
            $name
        ): DrawSlot {
            $this->assertActive($session);
            $slot = $this->findClaimSlot($session, $claimSecret);

            if ($slot->getState() === DrawSlot::STATE_COMPLETED) {
                throw new DrawSessionProblem(409, 'already_completed', 'This draw is already complete.');
            }

            if ($slot->getState() !== DrawSlot::STATE_NAMING) {
                throw new DrawSessionProblem(409, 'claim_released', 'This number has been released.');
            }

            $now = new \DateTimeImmutable();
            $slot
                ->setPlayerName($name)
                ->setState(DrawSlot::STATE_COMPLETED)
                ->setCompletedAt($now)
                ->setUpdatedAt($now);

            return $slot;
        });

        return $this->serializeClaim($result['session'], $result['result']);
    }

    public function hostState(string $publicId, string $hostSecret): array
    {
        $session = $this->find($publicId);
        $this->assertHost($session, $hostSecret);

        return $this->serializeHost($session);
    }

    public function editName(
        string $publicId,
        string $hostSecret,
        int $number,
        $rawName
    ): array {
        $name = $this->validateName($rawName);
        $result = $this->mutate($publicId, function (DrawSession $session) use (
            $hostSecret,
            $number,
            $name
        ): void {
            $this->assertHost($session, $hostSecret);
            $this->assertActive($session);
            $slot = $session->getSlot($number);

            if (!$slot || $slot->getState() !== DrawSlot::STATE_COMPLETED) {
                throw new DrawSessionProblem(409, 'not_completed', 'Only a completed player can be renamed.');
            }

            $slot
                ->setPlayerName($name)
                ->setUpdatedAt(new \DateTimeImmutable());
        });

        return $this->serializeHost($result['session']);
    }

    public function release(
        string $publicId,
        string $hostSecret,
        int $number
    ): array {
        $result = $this->mutate($publicId, function (DrawSession $session) use (
            $hostSecret,
            $number
        ): void {
            $this->assertHost($session, $hostSecret);
            $this->assertActive($session);
            $slot = $session->getSlot($number);

            if (!$slot || $slot->getState() === DrawSlot::STATE_AVAILABLE) {
                throw new DrawSessionProblem(409, 'not_claimed', 'That number is not currently claimed.');
            }

            $slot
                ->setState(DrawSlot::STATE_AVAILABLE)
                ->setClaimSecretHash(null)
                ->setPlayerName(null)
                ->setClaimedAt(null)
                ->setCompletedAt(null)
                ->setUpdatedAt(new \DateTimeImmutable());
        });

        return $this->serializeHost($result['session']);
    }

    public function end(string $publicId, string $hostSecret): array
    {
        $result = $this->mutate($publicId, function (DrawSession $session) use (
            $hostSecret
        ): void {
            $this->assertHost($session, $hostSecret);

            if (!$session->isActive()) {
                throw new DrawSessionProblem(409, 'already_ended', 'This draw room has already ended.');
            }

            $session->setStatus(DrawSession::STATUS_ENDED);
        });

        return $this->serializeHost($result['session']);
    }

    public function serializePublic(DrawSession $session): array
    {
        return [
            'publicId' => $session->getPublicId(),
            'status' => $session->getStatus(),
            'version' => $session->getVersion(),
            'expiresAt' => $session->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'slots' => array_map(static function (DrawSlot $slot): array {
                return [
                    'number' => $slot->getNumber(),
                    'state' => $slot->getState(),
                ];
            }, $session->getSlots()->toArray()),
        ];
    }

    public function serializeHost(DrawSession $session): array
    {
        $roles = $session->getRoleSnapshots();
        $slots = array_map(static function (DrawSlot $slot) use ($roles): array {
            $roleIndex = $slot->getRoleIndex();

            return [
                'number' => $slot->getNumber(),
                'state' => $slot->getState(),
                'name' => $slot->getPlayerName(),
                'role' => $roleIndex === null ? null : $roles[$roleIndex],
                'completedAt' => $slot->getCompletedAt()
                    ? $slot->getCompletedAt()->format(\DateTimeInterface::ATOM)
                    : null,
            ];
        }, $session->getSlots()->toArray());

        return [
            'publicId' => $session->getPublicId(),
            'status' => $session->getStatus(),
            'version' => $session->getVersion(),
            'createdAt' => $session->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $session->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'progress' => [
                'completed' => count(array_filter($slots, static function (array $slot): bool {
                    return $slot['state'] === DrawSlot::STATE_COMPLETED;
                })),
                'total' => count($slots),
            ],
            'slots' => $slots,
        ];
    }

    public function serializeClaim(DrawSession $session, DrawSlot $slot): array
    {
        $roleIndex = $slot->getRoleIndex();

        return [
            'publicId' => $session->getPublicId(),
            'sessionStatus' => $session->getStatus(),
            'version' => $session->getVersion(),
            'number' => $slot->getNumber(),
            'state' => $slot->getState(),
            'name' => $slot->getPlayerName(),
            'role' => $roleIndex === null ? null : $session->getRoleSnapshots()[$roleIndex],
            'expiresAt' => $session->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * The queue is shuffled first. If requested, the first Imp is removed and
     * reinserted at the configured successful-draw order.
     */
    public function buildDrawQueue(array $characters, ?int $impDrawOrder): array
    {
        $count = count($characters);

        if ($impDrawOrder !== null && ($impDrawOrder < 1 || $impDrawOrder > $count)) {
            throw new DrawSessionProblem(422, 'invalid_imp_draw_order', 'Imp draw order must match a player draw.');
        }

        $impIndex = null;

        if ($impDrawOrder !== null) {
            foreach ($characters as $index => $character) {
                $id = strtolower(preg_replace('/[-_]/', '', (string) ($character['id'] ?? '')));

                if ($id === 'imp') {
                    $impIndex = $index;
                    break;
                }
            }
        }

        if ($impIndex === null) {
            return $this->shuffle($characters);
        }

        $imp = $characters[$impIndex];
        array_splice($characters, $impIndex, 1);
        $characters = $this->shuffle($characters);
        array_splice($characters, $impDrawOrder - 1, 0, [$imp]);

        return array_values($characters);
    }

    private function validateCharacters(array $characters): array
    {
        $characters = array_values($characters);
        $count = count($characters);

        if ($count < 1 || $count > self::MAX_CHARACTERS) {
            throw new DrawSessionProblem(422, 'invalid_characters', 'Choose between 1 and 100 characters.');
        }

        foreach ($characters as $character) {
            if (
                !is_array($character)
                || !isset($character['id'], $character['name'], $character['ability'])
                || !is_string($character['id'])
                || !is_string($character['name'])
                || !is_string($character['ability'])
                || $character['id'] === ''
                || mb_strlen($character['id']) > 255
                || mb_strlen($character['name']) > 255
                || mb_strlen($character['ability']) > 10000
            ) {
                throw new DrawSessionProblem(422, 'invalid_characters', 'Every character snapshot must contain valid id, name, and ability fields.');
            }
        }

        return $characters;
    }

    private function validateName($rawName): string
    {
        if (!is_string($rawName)) {
            throw new DrawSessionProblem(422, 'invalid_name', 'Enter a player name.');
        }

        $name = preg_replace('/^\s+|\s+$/u', '', $rawName);

        if ($name === null || mb_strlen($name) < 1 || mb_strlen($name) > 80) {
            throw new DrawSessionProblem(422, 'invalid_name', 'Player names must be between 1 and 80 characters.');
        }

        return $name;
    }

    private function findClaimSlot(DrawSession $session, string $claimSecret): DrawSlot
    {
        $hash = $this->hashSecret($claimSecret);

        foreach ($session->getSlots() as $slot) {
            $storedHash = $slot->getClaimSecretHash();

            if ($storedHash !== null && hash_equals($storedHash, $hash)) {
                return $slot;
            }
        }

        throw new DrawSessionProblem(403, 'invalid_claim', 'This claim is no longer valid.');
    }

    private function assertHost(DrawSession $session, string $hostSecret): void
    {
        if (!hash_equals($session->getHostSecretHash(), $this->hashSecret($hostSecret))) {
            throw new DrawSessionProblem(403, 'invalid_host', 'The storyteller credential is invalid.');
        }
    }

    private function assertNotExpired(DrawSession $session): void
    {
        if ($session->isExpired()) {
            throw new DrawSessionProblem(410, 'expired', 'This draw room has expired.');
        }
    }

    private function assertActive(DrawSession $session): void
    {
        if (!$session->isActive()) {
            throw new DrawSessionProblem(409, 'ended', 'This draw room has ended.');
        }
    }

    /**
     * @return array{session: DrawSession, result: mixed}
     */
    private function mutate(string $publicId, callable $mutation): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $session = $this->sessions->findByPublicIdForUpdate($publicId);

            if (!$session) {
                throw new DrawSessionProblem(404, 'not_found', 'Draw room not found.');
            }

            $this->assertNotExpired($session);
            $result = $mutation($session);
            $session->incrementVersion();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        $this->publisher->publish($session);

        return [
            'session' => $session,
            'result' => $result,
        ];
    }

    private function shuffle(array $values): array
    {
        for ($index = count($values) - 1; $index > 0; --$index) {
            $swap = random_int(0, $index);
            [$values[$index], $values[$swap]] = [$values[$swap], $values[$index]];
        }

        return array_values($values);
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
