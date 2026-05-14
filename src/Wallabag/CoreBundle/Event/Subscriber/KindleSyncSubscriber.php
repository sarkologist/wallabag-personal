<?php

namespace Wallabag\CoreBundle\Event\Subscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wallabag\CoreBundle\Event\EntrySavedEvent;
use Wallabag\CoreBundle\Helper\KindleSync;

class KindleSyncSubscriber implements EventSubscriberInterface
{
    private $kindleSync;
    private $logger;

    public function __construct(KindleSync $kindleSync, LoggerInterface $logger)
    {
        $this->kindleSync = $kindleSync;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return [
            EntrySavedEvent::NAME => ['onEntrySaved', -20],
        ];
    }

    public function onEntrySaved(EntrySavedEvent $event): void
    {
        try {
            $this->kindleSync->exportEntry($event->getEntry());
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to export entry to Kindle sync directory.', [
                'entry_id' => $event->getEntry()->getId(),
                'exception' => $e,
            ]);
        }
    }
}
