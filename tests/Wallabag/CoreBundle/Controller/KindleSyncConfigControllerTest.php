<?php

namespace Tests\Wallabag\CoreBundle\Controller;

use Tests\Wallabag\CoreBundle\WallabagCoreTestCase;
use Wallabag\CoreBundle\Entity\Config;

class KindleSyncConfigControllerTest extends WallabagCoreTestCase
{
    private $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function testConfigFormPersistsKindleSyncSettingsAndBackfillsUnreadEntries()
    {
        $this->logInAs('admin');
        $client = $this->getTestClient();
        $directory = $this->createTemporaryDirectory();
        $configId = $this->getLoggedInUser()->getConfig()->getId();

        $crawler = $client->request('GET', '/config');
        $this->assertCount(1, $crawler->filter('input[id=config_kindle_sync_enabled]'));
        $this->assertCount(1, $crawler->filter('input[id=config_kindle_sync_directory]'));

        $form = $crawler->filter('button[id=config_save]')->form([
            'config[items_per_page]' => '30',
            'config[reading_speed]' => '200',
            'config[action_mark_as_read]' => '0',
            'config[language]' => 'en',
            'config[kindle_sync_directory]' => $directory,
        ]);
        $form['config[kindle_sync_enabled]']->tick();

        ob_start();
        $client->submit($form);
        ob_end_clean();

        $this->assertSame(302, $client->getResponse()->getStatusCode());

        $this->getEntityManager()->clear();
        $config = $this->getEntityManager()
            ->getRepository(Config::class)
            ->find($configId);

        $this->assertTrue($config->isKindleSyncEnabled());
        $this->assertSame($directory, $config->getKindleSyncDirectory());
        $this->assertNotEmpty(glob($directory . '/000* - *.epub'));
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/wallabag-kindle-config-' . uniqid('', true);
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
