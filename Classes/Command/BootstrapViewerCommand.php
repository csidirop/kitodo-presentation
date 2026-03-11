<?php

declare(strict_types=1);

namespace Kitodo\Dlf\Command;

use Kitodo\Dlf\Service\ViewerBootstrapService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BootstrapViewerCommand extends Command
{
    public function __construct(
        private readonly ViewerBootstrapService $viewerBootstrapService,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->setDescription('Create a minimal Kitodo.Presentation viewer site with root page, viewer page, config folder and site configuration.')
            ->addOption('parent-pid', 'p', InputOption::VALUE_REQUIRED, 'Parent page UID for the new site root.', null)
            ->addOption('pid', null, InputOption::VALUE_REQUIRED, 'Alias for --parent-pid.', null)
            ->addOption('site-title', null, InputOption::VALUE_REQUIRED, 'Site title.', 'Kitodo.Presentation')
            ->addOption('site-identifier', null, InputOption::VALUE_REQUIRED, 'Site identifier. If it already exists, a suffix is added automatically.', 'kitodo-presentation')
            ->addOption('site-base', null, InputOption::VALUE_REQUIRED, 'Site base URL or path.', '/')
            ->addOption('root-title', null, InputOption::VALUE_REQUIRED, 'Root page title.', 'Kitodo.Presentation')
            ->addOption('viewer-title', null, InputOption::VALUE_REQUIRED, 'Viewer page title.', 'Viewer')
            ->addOption('viewer-slug', null, InputOption::VALUE_REQUIRED, 'Viewer page slug.', 'viewer')
            ->addOption('storage-title', null, InputOption::VALUE_REQUIRED, 'Configuration sysfolder title.', 'Kitodo Configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Kitodo.Presentation viewer bootstrap');

        $parentPid = $input->getOption('parent-pid');
        if ($parentPid === null || $parentPid === '') {
            $parentPid = $input->getOption('pid');
        }

        $result = $this->viewerBootstrapService->bootstrap([
            'parentPid' => (int)($parentPid ?? 0),
            'siteTitle' => (string)$input->getOption('site-title'),
            'siteIdentifier' => (string)$input->getOption('site-identifier'),
            'siteBase' => $input->getOption('site-base') !== null ? (string)$input->getOption('site-base') : null,
            'rootTitle' => (string)$input->getOption('root-title'),
            'viewerTitle' => (string)$input->getOption('viewer-title'),
            'viewerSlug' => (string)$input->getOption('viewer-slug'),
            'storageTitle' => (string)$input->getOption('storage-title'),
        ]);

        $io->definitionList(
            ['Root page UID' => (string)$result['rootPageId']],
            ['Viewer page UID' => (string)$result['viewerPageId']],
            ['Storage page UID' => (string)$result['storagePageId']],
            ['Site identifier' => $result['siteIdentifier']],
            ['Data folder' => $result['dataFolder']]
        );
        $io->success('Viewer bootstrap created.');

        return Command::SUCCESS;
    }
}
