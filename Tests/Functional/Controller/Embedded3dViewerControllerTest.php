<?php

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\Tests\Functional\Controller;

use Kitodo\Dlf\Controller\Embedded3dViewerController;
use PHPUnit\Framework\Attributes\Test;

class Embedded3dViewerControllerTest extends AbstractControllerTestCase
{
    #[Test]
    public function determinesModelFormatBeforeBuildingViewerUrl(): void
    {
        $settings = [
            'model' => 'https://example.org/model.glb',
            'useInternalProxy' => 0
        ];
        $templateHtml = '<html><f:format.raw>{embedded3dViewerUrl}</f:format.raw></html>';
        $controller = $this->setUpController(Embedded3dViewerController::class, $settings, $templateHtml);
        $request = $this->setUpTemplateRequest('main');

        $response = $controller->processRequest($request);

        $response->getBody()->rewind();
        $actual = $response->getBody()->getContents();
        $this->assertStringContainsString('modelFormat=glb', $actual);
        $this->assertStringContainsString('model=https%3A%2F%2Fexample.org%2Fmodel.glb', $actual);
    }
}
