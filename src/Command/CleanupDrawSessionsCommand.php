<?php

namespace App\Command;

use App\Repository\DrawSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupDrawSessionsCommand extends Command
{
    protected static $defaultName = 'app:draw-sessions:cleanup';
    protected static $defaultDescription = 'Delete distributed draw rooms after their fixed 24-hour expiry.';

    private $sessions;
    private $entityManager;

    public function __construct(
        DrawSessionRepository $sessions,
        EntityManagerInterface $entityManager
    ) {
        parent::__construct();
        $this->sessions = $sessions;
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->sessions->deleteExpired(new \DateTimeImmutable());
        $this->entityManager->clear();
        $output->writeln(sprintf('Deleted %d expired draw session(s).', $deleted));

        return Command::SUCCESS;
    }
}
