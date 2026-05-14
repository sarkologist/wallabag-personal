<?php

namespace Wallabag\CoreBundle\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Wallabag\CoreBundle\Entity\Config;
use Wallabag\CoreBundle\Entity\Entry;
use Wallabag\CoreBundle\Repository\ConfigRepository;
use Wallabag\CoreBundle\Repository\EntryRepository;

class KindleSync
{
    public const EXPORTED = 'exported';
    public const SKIPPED_ARCHIVED = 'skipped_archived';
    public const SKIPPED_DISABLED = 'skipped_disabled';
    public const SKIPPED_EXISTING = 'skipped_existing';
    public const SKIPPED_MISSING_ID = 'skipped_missing_id';

    private const READ_PERCENT_THRESHOLD = 0.98;
    private const FINISHED_STATUSES = [
        'complete' => true,
        'completed' => true,
        'done' => true,
        'finished' => true,
        'read' => true,
    ];

    private $entriesExport;
    private $entryRepository;
    private $configRepository;
    private $entityManager;

    public function __construct(EntriesExport $entriesExport, EntryRepository $entryRepository, ConfigRepository $configRepository, EntityManagerInterface $entityManager)
    {
        $this->entriesExport = $entriesExport;
        $this->entryRepository = $entryRepository;
        $this->configRepository = $configRepository;
        $this->entityManager = $entityManager;
    }

    public function exportEntry(Entry $entry, bool $force = false): string
    {
        if (null === $entry->getId()) {
            return self::SKIPPED_MISSING_ID;
        }

        if ($entry->isArchived()) {
            return self::SKIPPED_ARCHIVED;
        }

        $config = $entry->getUser()->getConfig();
        if (!$this->isConfigured($config)) {
            return self::SKIPPED_DISABLED;
        }

        $directory = $this->prepareDirectory($config);
        if (!$force && null !== $this->findExistingEpub($directory, $entry->getId())) {
            return self::SKIPPED_EXISTING;
        }

        $targetPath = $this->getEpubPath($directory, $entry);
        $partPath = $targetPath . '.part';

        $response = $this->entriesExport
            ->setEntries($entry)
            ->updateTitle('entry')
            ->updateAuthor('entry')
            ->exportAs('epub');

        $content = $response->getContent();
        if (0 !== strpos($content, "PK\x03\x04")) {
            throw new \RuntimeException(sprintf('Generated EPUB for entry %d is not a ZIP file.', $entry->getId()));
        }

        if (false === file_put_contents($partPath, $content)) {
            throw new \RuntimeException(sprintf('Unable to write temporary EPUB "%s".', $partPath));
        }

        if (!rename($partPath, $targetPath)) {
            @unlink($partPath);

            throw new \RuntimeException(sprintf('Unable to move temporary EPUB into "%s".', $targetPath));
        }

        return self::EXPORTED;
    }

    public function backfillUnreadForConfig(Config $config): array
    {
        $summary = $this->newExportSummary();

        if (!$this->isConfigured($config)) {
            return $summary;
        }

        $entries = $this->entryRepository
            ->getBuilderForUnreadByUser($config->getUser()->getId())
            ->getQuery()
            ->getResult();

        foreach ($entries as $entry) {
            try {
                $status = $this->exportEntry($entry);
                ++$summary[$status === self::EXPORTED ? 'exported' : 'skipped'];
            } catch (\Throwable $e) {
                ++$summary['failed'];
                $summary['warnings'][] = $e->getMessage();
            }
        }

        return $summary;
    }

    public function syncReadStatusForEnabledConfigs(): array
    {
        $summary = $this->newReadSyncSummary();

        foreach ($this->configRepository->findBy(['kindleSyncEnabled' => true]) as $config) {
            $summary = $this->mergeReadSyncSummary($summary, $this->syncReadStatusForConfig($config));
        }

        return $summary;
    }

    public function syncReadStatusForConfig(Config $config): array
    {
        $summary = $this->newReadSyncSummary();

        if (!$this->isConfigured($config)) {
            return $summary;
        }

        $directory = $this->prepareDirectory($config);
        foreach ($this->findMetadataFiles($directory) as $metadataPath) {
            ++$summary['scanned'];

            try {
                if (!$this->metadataMarksEntryAsRead($metadataPath)) {
                    ++$summary['skipped'];
                    continue;
                }

                $entryId = $this->getEntryIdFromSdrDirectory(\dirname($metadataPath));
                if (null === $entryId) {
                    ++$summary['skipped'];
                    $summary['warnings'][] = sprintf('Unable to determine wallabag entry id from "%s".', $metadataPath);
                    continue;
                }

                $entry = $this->entryRepository->find($entryId);
                if (!$entry instanceof Entry || $entry->getUser()->getId() !== $config->getUser()->getId()) {
                    ++$summary['skipped'];
                    $summary['warnings'][] = sprintf('No matching wallabag entry for "%s".', $metadataPath);
                    continue;
                }

                if ($entry->isArchived()) {
                    ++$summary['skipped'];
                    continue;
                }

                $entry->updateArchived(true);
                $this->entityManager->persist($entry);
                $this->entityManager->flush();
                ++$summary['archived'];

                try {
                    $this->moveEntryFilesToArchive($directory, \dirname($metadataPath), $entryId);
                    ++$summary['moved'];
                } catch (\Throwable $e) {
                    $summary['warnings'][] = $e->getMessage();
                }
            } catch (\Throwable $e) {
                ++$summary['skipped'];
                $summary['warnings'][] = $e->getMessage();
            }
        }

        return $summary;
    }

    private function isConfigured(?Config $config): bool
    {
        return null !== $config
            && $config->isKindleSyncEnabled()
            && null !== $config->getKindleSyncDirectory()
            && '' !== trim($config->getKindleSyncDirectory());
    }

    private function prepareDirectory(Config $config): string
    {
        $directory = rtrim($config->getKindleSyncDirectory(), DIRECTORY_SEPARATOR);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create Kindle sync directory "%s".', $directory));
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException(sprintf('Kindle sync directory "%s" is not writable.', $directory));
        }

        return $directory;
    }

    private function findExistingEpub(string $directory, int $entryId): ?string
    {
        $prefix = $this->getEntryPrefix($entryId) . ' - ';

        foreach ($this->scanDirectory($directory) as $filename) {
            if (0 === strpos($filename, $prefix) && preg_match('/\.epub$/', $filename)) {
                return $this->joinPath($directory, $filename);
            }
        }

        return null;
    }

    private function getEpubPath(string $directory, Entry $entry): string
    {
        return $this->joinPath(
            $directory,
            sprintf('%s - %s.epub', $this->getEntryPrefix($entry->getId()), $this->sanitizeTitle($entry->getTitle()))
        );
    }

    private function sanitizeTitle(?string $title): string
    {
        $title = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', '-', (string) $title);
        $title = preg_replace('/\s+/u', ' ', $title);
        $title = trim($title);
        $title = ltrim($title, '.');

        if ('' === $title) {
            $title = 'untitled';
        }

        return function_exists('mb_substr') ? mb_substr($title, 0, 160) : substr($title, 0, 160);
    }

    private function findMetadataFiles(string $directory): array
    {
        $metadataFiles = [];

        foreach ($this->scanDirectory($directory) as $filename) {
            if (!preg_match('/\.sdr$/', $filename)) {
                continue;
            }

            $metadataPath = $this->joinPath($this->joinPath($directory, $filename), 'metadata.epub.lua');
            if (is_file($metadataPath)) {
                $metadataFiles[] = $metadataPath;
            }
        }

        return $metadataFiles;
    }

    private function metadataMarksEntryAsRead(string $metadataPath): bool
    {
        $metadata = file_get_contents($metadataPath);
        if (false === $metadata) {
            throw new \RuntimeException(sprintf('Unable to read KOReader metadata "%s".', $metadataPath));
        }

        if (preg_match('/\["percent_finished"\]\s*=\s*([0-9]+(?:\.[0-9]+)?)/', $metadata, $match)
            && (float) $match[1] >= self::READ_PERCENT_THRESHOLD) {
            return true;
        }

        if (preg_match('/\["summary"\]\s*=\s*\{.*?\["status"\]\s*=\s*"([^"]+)"/s', $metadata, $match)) {
            return isset(self::FINISHED_STATUSES[strtolower($match[1])]);
        }

        return false;
    }

    private function getEntryIdFromSdrDirectory(string $sdrDirectory): ?int
    {
        if (preg_match('/^(\d{6,}) - .+\.sdr$/', basename($sdrDirectory), $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private function moveEntryFilesToArchive(string $directory, string $sdrDirectory, int $entryId): void
    {
        $archiveDirectory = $this->joinPath($directory, 'archive');
        if (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0775, true) && !is_dir($archiveDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create Kindle sync archive directory "%s".', $archiveDirectory));
        }

        $epubPath = $this->getEpubPathForSdrDirectory($directory, $sdrDirectory, $entryId);
        if (null === $epubPath) {
            throw new \RuntimeException(sprintf('No EPUB found for KOReader sidecar "%s".', $sdrDirectory));
        }

        $archiveEpubPath = $this->joinPath($archiveDirectory, basename($epubPath));
        $archiveSdrPath = $this->joinPath($archiveDirectory, basename($sdrDirectory));

        if (file_exists($archiveEpubPath) || file_exists($archiveSdrPath)) {
            throw new \RuntimeException(sprintf('Archive target already exists for entry %d; leaving Kindle files in place.', $entryId));
        }

        if (!rename($epubPath, $archiveEpubPath)) {
            throw new \RuntimeException(sprintf('Unable to move "%s" to "%s".', $epubPath, $archiveEpubPath));
        }

        if (!rename($sdrDirectory, $archiveSdrPath)) {
            throw new \RuntimeException(sprintf('Unable to move "%s" to "%s".', $sdrDirectory, $archiveSdrPath));
        }
    }

    private function getEpubPathForSdrDirectory(string $directory, string $sdrDirectory, int $entryId): ?string
    {
        $epubPath = $this->joinPath($directory, preg_replace('/\.sdr$/', '.epub', basename($sdrDirectory)));
        if (is_file($epubPath)) {
            return $epubPath;
        }

        return $this->findExistingEpub($directory, $entryId);
    }

    private function scanDirectory(string $directory): array
    {
        $files = scandir($directory);

        if (false === $files) {
            throw new \RuntimeException(sprintf('Unable to scan directory "%s".', $directory));
        }

        return array_values(array_filter($files, static function ($filename) {
            return '.' !== $filename && '..' !== $filename;
        }));
    }

    private function getEntryPrefix(int $entryId): string
    {
        return sprintf('%06d', $entryId);
    }

    private function joinPath(string $directory, string $filename): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    }

    private function newExportSummary(): array
    {
        return [
            'exported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'warnings' => [],
        ];
    }

    private function newReadSyncSummary(): array
    {
        return [
            'scanned' => 0,
            'archived' => 0,
            'moved' => 0,
            'skipped' => 0,
            'warnings' => [],
        ];
    }

    private function mergeReadSyncSummary(array $summary, array $other): array
    {
        foreach (['scanned', 'archived', 'moved', 'skipped'] as $key) {
            $summary[$key] += $other[$key];
        }

        $summary['warnings'] = array_merge($summary['warnings'], $other['warnings']);

        return $summary;
    }
}
