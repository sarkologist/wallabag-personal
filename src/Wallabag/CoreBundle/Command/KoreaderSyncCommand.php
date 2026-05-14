<?php

namespace Wallabag\CoreBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wallabag\CoreBundle\Helper\KindleSync;

class KoreaderSyncCommand extends Command
{
    private $kindleSync;

    public function __construct(KindleSync $kindleSync)
    {
        $this->kindleSync = $kindleSync;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('wallabag:koreader:sync')
            ->setDescription('Sync KOReader read progress from the Kindle Syncthing folder back to wallabag')
            ->setHelp('This command scans enabled Kindle sync folders for KOReader sidecar metadata and archives read entries.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $summary = $this->kindleSync->syncReadStatusForEnabledConfigs();

        $io->text(sprintf(
            'Scanned %d KOReader metadata %s, archived %d %s, moved %d %s, skipped %d.',
            $summary['scanned'],
            1 === $summary['scanned'] ? 'file' : 'files',
            $summary['archived'],
            1 === $summary['archived'] ? 'entry' : 'entries',
            $summary['moved'],
            1 === $summary['moved'] ? 'file set' : 'file sets',
            $summary['skipped']
        ));

        foreach ($summary['warnings'] as $warning) {
            $io->warning($warning);
        }

        $io->success('Done.');

        return 0;
    }
}
