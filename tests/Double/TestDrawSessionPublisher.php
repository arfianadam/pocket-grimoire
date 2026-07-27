<?php

namespace App\Tests\Double;

use App\Entity\DrawSession;
use App\Service\DrawSessionPublisher;

class TestDrawSessionPublisher extends DrawSessionPublisher
{
    private $events = [];

    public function __construct()
    {
    }

    public function topic(DrawSession $session): string
    {
        return 'https://test.invalid/draw-sessions/' . $session->getPublicId();
    }

    public function publish(DrawSession $session): void
    {
        $this->events[] = [
            'publicId' => $session->getPublicId(),
            'version' => $session->getVersion(),
        ];
    }

    public function getEvents(): array
    {
        return $this->events;
    }
}
