<?php

namespace Tests\Wallabag\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Wallabag\CoreBundle\WallabagCoreTestCase;
use Wallabag\CoreBundle\Entity\Entry;

class KoreaderSyncCommandTest extends WallabagCoreTestCase
{
    private $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function testKoreaderSyncArchivesReadEntryAndMovesFiles()
    {
        $this->logInAs('admin');
        $directory = $this->enableKindleSync();
        $entry = $this->createUnreadEntry('Read Test Article');
        $baseName = $this->writeKoreaderFiles($directory, $entry, '["percent_finished"] = 0.99,');

        $tester = $this->executeCommand();

        $this->assertStringContainsString('archived 1 entry', $tester->getDisplay());

        $this->getEntityManager()->clear();
        $freshEntry = $this->getEntityManager()->getRepository(Entry::class)->find($entry->getId());
        $this->assertTrue($freshEntry->isArchived());
        $this->assertFileExists($directory . '/archive/' . $baseName . '.epub');
        $this->assertFileExists($directory . '/archive/' . $baseName . '.sdr/metadata.epub.lua');
    }

    public function testKoreaderSyncReportsExistingArchiveTargetsWithoutOverwriting()
    {
        $this->logInAs('admin');
        $directory = $this->enableKindleSync();
        $entry = $this->createUnreadEntry('Already Archived Target');
        $baseName = $this->writeKoreaderFiles($directory, $entry, '["percent_finished"] = 0.99,');

        mkdir($directory . '/archive', 0775, true);
        file_put_contents($directory . '/archive/' . $baseName . '.epub', 'existing archive');

        $tester = $this->executeCommand();

        $this->assertStringContainsString('Archive target already exists', $tester->getDisplay());
        $this->assertSame('existing archive', file_get_contents($directory . '/archive/' . $baseName . '.epub'));
        $this->assertFileExists($directory . '/' . $baseName . '.epub');
        $this->assertFileExists($directory . '/' . $baseName . '.sdr/metadata.epub.lua');
    }

    public function testKoreaderSyncKeepsUnreadEntryBelowReadThreshold()
    {
        $this->logInAs('admin');
        $directory = $this->enableKindleSync();
        $entry = $this->createUnreadEntry('Unread Test Article');
        $baseName = $this->writeKoreaderFiles($directory, $entry, '["percent_finished"] = 0.42,');

        $tester = $this->executeCommand();

        $this->assertStringContainsString('archived 0 entries', $tester->getDisplay());

        $this->getEntityManager()->clear();
        $freshEntry = $this->getEntityManager()->getRepository(Entry::class)->find($entry->getId());
        $this->assertFalse($freshEntry->isArchived());
        $this->assertFileExists($directory . '/' . $baseName . '.epub');
        $this->assertFileExists($directory . '/' . $baseName . '.sdr/metadata.epub.lua');
    }

    public function testKoreaderSyncReportsMissingEntryWithoutCrashing()
    {
        $this->logInAs('admin');
        $directory = $this->enableKindleSync();
        $baseName = '999999 - Missing Article';
        mkdir($directory . '/' . $baseName . '.sdr', 0775, true);
        file_put_contents($directory . '/' . $baseName . '.sdr/metadata.epub.lua', $this->metadataLua('["percent_finished"] = 0.99,'));

        $tester = $this->executeCommand();

        $this->assertStringContainsString('No matching wallabag entry', $tester->getDisplay());
        $this->assertStringContainsString('Done', $tester->getDisplay());
    }

    private function executeCommand(): CommandTester
    {
        $application = new Application($this->getTestClient()->getKernel());
        $command = $application->find('wallabag:koreader:sync');
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function enableKindleSync(): string
    {
        $directory = $this->createTemporaryDirectory();
        $config = $this->getLoggedInUser()->getConfig();
        $config->setKindleSyncEnabled(true);
        $config->setKindleSyncDirectory($directory);
        $this->getEntityManager()->flush();

        return $directory;
    }

    private function createUnreadEntry(string $title): Entry
    {
        $entry = new Entry($this->getLoggedInUser());
        $entry->setUrl('http://0.0.0.0/koreader-sync-' . uniqid('', true));
        $entry->setTitle($title);
        $entry->setContent('<p>This is a KOReader sync test article.</p>');
        $entry->setDomainName('example.com');
        $entry->setLanguage('en');
        $entry->setMimetype('text/html');
        $entry->setReadingTime(1);

        $this->getEntityManager()->persist($entry);
        $this->getEntityManager()->flush();

        return $entry;
    }

    private function writeKoreaderFiles(string $directory, Entry $entry, string $readSignal): string
    {
        $baseName = sprintf('%06d - %s', $entry->getId(), $entry->getTitle());
        file_put_contents($directory . '/' . $baseName . '.epub', "PK\x03\x04test");
        mkdir($directory . '/' . $baseName . '.sdr', 0775, true);
        file_put_contents($directory . '/' . $baseName . '.sdr/metadata.epub.lua', $this->metadataLua($readSignal));

        return $baseName;
    }

    private function metadataLua(string $readSignal): string
    {
        return <<<LUA
return {
    {$readSignal}
    ["summary"] = {
        ["status"] = "reading",
    },
}
LUA;
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/wallabag-koreader-sync-' . uniqid('', true);
        mkdir($directory, 0775, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }

        rmdir($directory);
    }
}
