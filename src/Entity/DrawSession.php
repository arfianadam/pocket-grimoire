<?php

namespace App\Entity;

use App\Repository\DrawSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=DrawSessionRepository::class)
 * @ORM\Table(
 *     name="draw_sessions",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="draw_session_public_id_unique", columns={"public_id"})},
 *     indexes={@ORM\Index(name="draw_session_expiry_idx", columns={"expires_at"})}
 * )
 */
class DrawSession
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(name="public_id", type="string", length=64)
     */
    private $publicId;

    /**
     * @ORM\Column(name="host_secret_hash", type="string", length=64)
     */
    private $hostSecretHash;

    /**
     * @ORM\Column(name="role_snapshots", type="json")
     */
    private $roleSnapshots = [];

    /**
     * @ORM\Column(name="next_draw_index", type="integer", options={"default": 0})
     */
    private $nextDrawIndex = 0;

    /**
     * @ORM\Column(type="string", length=16, options={"default": "active"})
     */
    private $status = self::STATUS_ACTIVE;

    /**
     * @ORM\Column(type="integer", options={"default": 1})
     */
    private $version = 1;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private $createdAt;

    /**
     * @ORM\Column(name="expires_at", type="datetime_immutable")
     */
    private $expiresAt;

    /**
     * @ORM\OneToMany(
     *     targetEntity=DrawSlot::class,
     *     mappedBy="session",
     *     cascade={"persist", "remove"},
     *     orphanRemoval=true
     * )
     * @ORM\OrderBy({"number" = "ASC"})
     *
     * @var Collection<int, DrawSlot>
     */
    private $slots;

    public function __construct()
    {
        $this->slots = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function setPublicId(string $publicId): self
    {
        $this->publicId = $publicId;

        return $this;
    }

    public function getHostSecretHash(): string
    {
        return $this->hostSecretHash;
    }

    public function setHostSecretHash(string $hostSecretHash): self
    {
        $this->hostSecretHash = $hostSecretHash;

        return $this;
    }

    public function getRoleSnapshots(): array
    {
        return $this->roleSnapshots;
    }

    public function setRoleSnapshots(array $roleSnapshots): self
    {
        $this->roleSnapshots = array_values($roleSnapshots);

        return $this;
    }

    public function getNextDrawIndex(): int
    {
        return $this->nextDrawIndex;
    }

    public function setNextDrawIndex(int $nextDrawIndex): self
    {
        $this->nextDrawIndex = $nextDrawIndex;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function incrementVersion(): self
    {
        ++$this->version;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /**
     * @return Collection<int, DrawSlot>
     */
    public function getSlots(): Collection
    {
        return $this->slots;
    }

    public function addSlot(DrawSlot $slot): self
    {
        if (!$this->slots->contains($slot)) {
            $this->slots->add($slot);
            $slot->setSession($this);
        }

        return $this;
    }

    public function getSlot(int $number): ?DrawSlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->getNumber() === $number) {
                return $slot;
            }
        }

        return null;
    }
}
