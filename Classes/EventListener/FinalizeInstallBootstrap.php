<?php

declare(strict_types=1);

namespace Kitodo\Dlf\EventListener;

use Kitodo\Dlf\Service\InstallBootstrapService;
use TYPO3\CMS\Core\Package\Event\AfterPackageActivationEvent;

/**
 * Event listener for finalizing the bootstrap viewer setup after package activation.
 */
final class FinalizeInstallBootstrap
{
    public function __construct(
        private readonly InstallBootstrapService $installBootstrapService,
    ) {}

    public function __invoke(AfterPackageActivationEvent $event): void
    {
        if ($event->getPackageKey() !== 'dlf') {
            return;
        }

        $this->installBootstrapService->finalizeInitialInstallation();
    }
}
