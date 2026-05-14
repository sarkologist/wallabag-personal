<?php

namespace Application\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Wallabag\CoreBundle\Doctrine\WallabagMigration;

/**
 * Add personal KOReader/Syncthing export settings.
 */
final class Version20260514120000 extends WallabagMigration
{
    public function up(Schema $schema): void
    {
        $configTable = $schema->getTable($this->getTable('config'));

        if (!$configTable->hasColumn('kindle_sync_enabled')) {
            $configTable->addColumn('kindle_sync_enabled', 'boolean', [
                'default' => false,
                'notnull' => true,
            ]);
        }

        if (!$configTable->hasColumn('kindle_sync_directory')) {
            $configTable->addColumn('kindle_sync_directory', 'text', [
                'notnull' => false,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $configTable = $schema->getTable($this->getTable('config'));

        if ($configTable->hasColumn('kindle_sync_directory')) {
            $configTable->dropColumn('kindle_sync_directory');
        }

        if ($configTable->hasColumn('kindle_sync_enabled')) {
            $configTable->dropColumn('kindle_sync_enabled');
        }
    }
}
