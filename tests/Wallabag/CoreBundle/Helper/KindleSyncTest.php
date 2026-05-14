<?php

namespace Tests\Wallabag\CoreBundle\Helper;

use Tests\Wallabag\CoreBundle\WallabagCoreTestCase;
use Wallabag\CoreBundle\Entity\Entry;
use Wallabag\CoreBundle\Helper\KindleSync;

class KindleSyncTest extends WallabagCoreTestCase
{
    private $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function testExportEntryWritesEpubAndSkipsExistingExport()
    {
        $this->logInAs('admin');
        $directory = $this->createTemporaryDirectory();
        $entry = $this->createUnreadEntry('A/B: Test Article');
        $config = $this->getLoggedInUser()->getConfig();
        $config->setKindleSyncEnabled(true);
        $config->setKindleSyncDirectory($directory);
        $this->getEntityManager()->flush();

        ob_start();
        $status = $this->getTestClient()->getContainer()->get(KindleSync::class)->exportEntry($entry);
        ob_end_clean();

        $this->assertSame(KindleSync::EXPORTED, $status);

        $files = glob($directory . '/' . sprintf('%06d', $entry->getId()) . ' - *.epub');
        $this->assertCount(1, $files);
        $this->assertSame("PK\x03\x04", file_get_contents($files[0], false, null, 0, 4));

        ob_start();
        $status = $this->getTestClient()->getContainer()->get(KindleSync::class)->exportEntry($entry);
        ob_end_clean();

        $this->assertSame(KindleSync::SKIPPED_EXISTING, $status);
        $this->assertCount(1, glob($directory . '/' . sprintf('%06d', $entry->getId()) . ' - *.epub'));
    }

    public function testExportEntrySkipsArchivedEntries()
    {
        $this->logInAs('admin');
        $directory = $this->createTemporaryDirectory();
        $entry = $this->createUnreadEntry('Archived Article');
        $entry->updateArchived(true);
        $config = $this->getLoggedInUser()->getConfig();
        $config->setKindleSyncEnabled(true);
        $config->setKindleSyncDirectory($directory);
        $this->getEntityManager()->flush();

        $status = $this->getTestClient()->getContainer()->get(KindleSync::class)->exportEntry($entry);

        $this->assertSame(KindleSync::SKIPPED_ARCHIVED, $status);
        $this->assertSame([], glob($directory . '/*.epub'));
    }

    private function createUnreadEntry(string $title): Entry
    {
        $entry = new Entry($this->getLoggedInUser());
        $entry->setUrl('http://0.0.0.0/kindle-sync-' . uniqid('', true));
        $entry->setTitle($title);
        $entry->setContent('<p>This is a Kindle sync test article.</p>');
        $entry->setDomainName('example.com');
        $entry->setLanguage('en');
        $entry->setMimetype('text/html');
        $entry->setReadingTime(1);

        $this->getEntityManager()->persist($entry);
        $this->getEntityManager()->flush();

        return $entry;
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/wallabag-kindle-sync-' . uniqid('', true);
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
