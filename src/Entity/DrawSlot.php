<?php

namespace App\Entity;

use App\Repository\DrawSlotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=DrawSlotRepository::class)
 * @ORM\Table(
 *     name="draw_slots",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="draw_slot_session_number_unique", columns={"session_id", "number"})}
 * )
 */
class DrawSlot
{
    public const STATE_AVAILABLE = 'available';
    public const STATE_NAMING = 'naming';
    public const STATE_COMPLETED = 'completed';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=DrawSession::class, inversedBy="slots")
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private $session;

    /**
     * @ORM\Column(type="integer")
     */
    private $number;

    /**
     * @ORM\Column(type="string", length=16, options={"default": "available"})
     */
    private $state = self::STATE_AVAILABLE;

    /**
     * @ORM\Column(name="role_index", type="integer", nullable=true)
     */
    private $roleIndex;

    /**
     * @ORM\Column(name="claim_secret_hash", type="string", length=64, nullable=true)
     */
    private $claimSecretHash;

    /**
     * @ORM\Column(name="player_name", type="string", length=80, nullable=true)
     */
    private $playerName;

    /**
     * @ORM\Column(name="claimed_at", type="datetime_immutable", nullable=true)
     */
    private $claimedAt;

    /**
     * @ORM\Column(name="completed_at", type="datetime_immutable", nullable=true)
     */
    private $completedAt;

    /**
     * @ORM\Column(name="updated_at", type="datetime_immutable")
     */
    private $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): DrawSession
    {
        return $this->session;
    }

    public function setSession(DrawSession $session): self
    {
        $this->session = $session;

        return $this;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function setNumber(int $number): self
    {
        $this->number = $number;

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function getRoleIndex(): ?int
    {
        return $this->roleIndex;
    }

    public function setRoleIndex(?int $roleIndex): self
    {
        $this->roleIndex = $roleIndex;

        return $this;
    }

    public function getClaimSecretHash(): ?string
    {
        return $this->claimSecretHash;
    }

    public function setClaimSecretHash(?string $claimSecretHash): self
    {
        $this->claimSecretHash = $claimSecretHash;

        return $this;
    }

    public function getPlayerName(): ?string
    {
        return $this->playerName;
    }

    public function setPlayerName(?string $playerName): self
    {
        $this->playerName = $playerName;

        return $this;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?\DateTimeImmutable $claimedAt): self
    {
        $this->claimedAt = $claimedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
