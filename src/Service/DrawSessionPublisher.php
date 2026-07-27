<?php

namespace App\Service;

use App\Entity\DrawSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class DrawSessionPublisher
{
    private $hub;
    private $logger;

    public function __construct(HubInterface $hub, LoggerInterface $logger)
    {
        $this->hub = $hub;
        $this->logger = $logger;
    }

    public function topic(DrawSession $session): string
    {
        return 'https://pocket-grimoire.local/draw-sessions/' . $session->getPublicId();
    }

    public function publish(DrawSession $session): void
    {
        try {
            $this->hub->publish(new Update(
                $this->topic($session),
                json_encode(
                    ['version' => $session->getVersion()],
                    JSON_THROW_ON_ERROR
                )
            ));
        } catch (\Throwable $exception) {
            // A committed draw must remain successful if the optional realtime
            // transport is temporarily unavailable. Clients also recover by
            // polling and on visibility/reconnection.
            $this->logger->error('Unable to publish draw-session update.', [
                'publicId' => $session->getPublicId(),
                'version' => $session->getVersion(),
                'exception' => $exception,
            ]);
        }
    }
}
