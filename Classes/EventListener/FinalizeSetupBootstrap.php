<?php

declare(strict_types=1);

namespace Kitodo\Dlf\EventListener;

use Kitodo\Dlf\Service\InstallBootstrapService;
use TYPO3\CMS\Extensionmanager\Event\AfterExtensionDatabaseContentHasBeenImportedEvent;

/**
 * Event listener for finalizing the bootstrap viewer setup after extension data import.
 */
final class FinalizeSetupBootstrap
{
    public function __construct(
        private readonly InstallBootstrapService $installBootstrapService,
    ) {}

    public function __invoke(AfterExtensionDatabaseContentHasBeenImportedEvent $event): void
    {
        if ($event->getPackageKey() !== 'dlf') {
            return;
        }

        $this->installBootstrapService->finalizeInitialInstallation();
    }
}
