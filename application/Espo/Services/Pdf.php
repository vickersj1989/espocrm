<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2020 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Services;

use Espo\Core\Exceptions\{
    Forbidden,
    NotFound,
    Error,
};

use Espo\Core\{
    ServiceFactory,
    Acl,
    Utils\Config,
    Utils\Metadata,
    Utils\Language,
    Utils\Util,
    Htmlizer\Htmlizer,
    Htmlizer\Factory as HtmlizerFactory,
    ORM\EntityManager,
    ORM\Entity,
    Pdf\Tcpdf,
};

class Pdf
{
    protected $fontFace = 'freesans';

    protected $fontSize = 12;

    protected $removeMassFilePeriod = '1 hour';

    protected $config;
    protected $serviceFactory;
    protected $metadata;
    protected $entityManager;
    protected $acl;
    protected $defaultLanguage;
    protected $htmlizerFactory;

    public function __construct(
        Config $config,
        ServiceFactory $serviceFactory,
        Metadata $metadata,
        EntityManager $entityManager,
        Acl $acl,
        Language $defaultLanguage,
        HtmlizerFactory $htmlizerFactory
    ) {
        $this->config = $config;
        $this->serviceFactory = $serviceFactory;
        $this->metadata = $metadata;
        $this->entityManager = $entityManager;
        $this->acl = $acl;
        $this->defaultLanguage = $defaultLanguage;
        $this->htmlizerFactory = $htmlizerFactory;
    }

    protected function printEntity(
        Entity $entity, Entity $template, Htmlizer $htmlizer, Tcpdf $pdf,
        ?array $additionalData = null
    ) {
        $fontFace = $this->config->get('pdfFontFace', $this->fontFace);
        if ($template->get('fontFace')) {
            $fontFace = $template->get('fontFace');
        }

        $pdf->setFont($fontFace, '', $this->fontSize, '', true);

        $pdf->setAutoPageBreak(true, $template->get('bottomMargin'));
        $pdf->setMargins($template->get('leftMargin'), $template->get('topMargin'), $template->get('rightMargin'));

        if ($template->get('printFooter')) {
            $htmlFooter = $htmlizer->render($entity, $template->get('footer') ?? '', null, $additionalData);
            $pdf->setFooterFont([$fontFace, '', $this->fontSize]);
            $pdf->setFooterPosition($template->get('footerPosition'));
            $pdf->setFooterHtml($htmlFooter);
        } else {
            $pdf->setPrintFooter(false);
        }

        $pageOrientation = 'Portrait';
        if ($template->get('pageOrientation')) {
            $pageOrientation = $template->get('pageOrientation');
        }
        $pageFormat = 'A4';
        if ($template->get('pageFormat')) {
            $pageFormat = $template->get('pageFormat');
        }
        if ($pageFormat === 'Custom') {
            $pageFormat = [$template->get('pageWidth'), $template->get('pageHeight')];
        }
        $pageOrientationCode = 'P';
        if ($pageOrientation === 'Landscape') {
            $pageOrientationCode = 'L';
        }

        $htmlHeader = $htmlizer->render($entity, $template->get('header') ?? '', null, $additionalData);

        if ($template->get('printHeader')) {
            $pdf->setHeaderFont([$fontFace, '', $this->fontSize]);
            $pdf->setHeaderPosition($template->get('headerPosition'));
            $pdf->setHeaderHtml($htmlHeader);

            $pdf->addPage($pageOrientationCode, $pageFormat);
        } else {
            $pdf->addPage($pageOrientationCode, $pageFormat);
            $pdf->setPrintHeader(false);
            $pdf->writeHTML($htmlHeader, true, false, true, false, '');
        }


        $htmlBody = $htmlizer->render($entity, $template->get('body') ?? '', null, $additionalData);
        $pdf->writeHTML($htmlBody, true, false, true, false, '');
    }

    public function generateMailMerge($entityType, $entityList, Entity $template, $name, $campaignId = null)
    {
        $htmlizer = $this->createHtmlizer();
        $pdf = new Tcpdf();
        $pdf->setUseGroupNumbers(true);

        if ($this->serviceFactory->checkExists($entityType)) {
            $service = $this->serviceFactory->create($entityType);
        } else {
            $service = $this->serviceFactory->create('Record');
        }

        foreach ($entityList as $entity) {
            $service->loadAdditionalFields($entity);
            if (method_exists($service, 'loadAdditionalFieldsForPdf')) {
                $service->loadAdditionalFieldsForPdf($entity);
            }
            $pdf->startPageGroup();
            $this->printEntity($entity, $template, $htmlizer, $pdf);
        }

        $filename = Util::sanitizeFileName($name) . '.pdf';

        $attachment = $this->entityManager->getEntity('Attachment');

        $content = $pdf->output('', 'S');

        $attachment->set([
            'name' => $filename,
            'relatedType' => 'Campaign',
            'type' => 'application/pdf',
            'relatedId' => $campaignId,
            'role' => 'Mail Merge',
            'contents' => $content
        ]);

        $this->entityManager->saveEntity($attachment);

        return $attachment->id;
    }

    public function massGenerate($entityType, $idList, $templateId, $checkAcl = false)
    {
        if ($this->serviceFactory->checkExists($entityType)) {
            $service = $this->serviceFactory->create($entityType);
        } else {
            $service = $this->serviceFactory->create('Record');
        }

        $maxCount = $this->config->get('massPrintPdfMaxCount');
        if ($maxCount) {
            if (count($idList) > $maxCount) {
                throw new Error("Mass print to PDF max count exceeded.");
            }
        }

        $template = $this->entityManager->getEntity('Template', $templateId);

        if (!$template) {
            throw new NotFound();
        }

        if ($checkAcl) {
            if (!$this->acl->check($template)) {
                throw new Forbidden();
            }
            if (!$this->acl->checkScope($entityType)) {
                throw new Forbidden();
            }
        }

        $htmlizer = $this->createHtmlizer();
        $pdf = new Tcpdf();
        $pdf->setUseGroupNumbers(true);

        $entityList = $this->entityManager->getRepository($entityType)->where([
            'id' => $idList
        ])->find();

        foreach ($entityList as $entity) {
            if ($checkAcl) {
                if (!$this->acl->check($entity)) continue;
            }
            $service->loadAdditionalFields($entity);
            if (method_exists($service, 'loadAdditionalFieldsForPdf')) {
                $service->loadAdditionalFieldsForPdf($entity);
            }
            $pdf->startPageGroup();
            $this->printEntity($entity, $template, $htmlizer, $pdf);
        }

        $content = $pdf->output('', 'S');

        $entityTypeTranslated = $this->defaultLanguage->translate($entityType, 'scopeNamesPlural');
        $filename = Util::sanitizeFileName($entityTypeTranslated) . '.pdf';

        $attachment = $this->entityManager->getEntity('Attachment');
        $attachment->set([
            'name' => $filename,
            'type' => 'application/pdf',
            'role' => 'Mass Pdf',
            'contents' => $content
        ]);
        $this->entityManager->saveEntity($attachment);

        $job = $this->entityManager->getEntity('Job');
        $job->set([
            'serviceName' => 'Pdf',
            'methodName' => 'removeMassFileJob',
            'data' => [
                'id' => $attachment->id
            ],
            'executeTime' => (new \DateTime())->modify('+' . $this->removeMassFilePeriod)->format('Y-m-d H:i:s'),
            'queue' => 'q1'
        ]);
        $this->entityManager->saveEntity($job);

        return $attachment->id;
    }

    public function removeMassFileJob($data)
    {
        if (empty($data->id)) {
            return;
        }
        $attachment = $this->entityManager->getEntity('Attachment', $data->id);
        if (!$attachment) return;
        if ($attachment->get('role') !== 'Mass Pdf') return;
        $this->entityManager->removeEntity($attachment);
    }

    public function buildFromTemplate(Entity $entity, Entity $template, $displayInline = false, ?array $additionalData = null)
    {
        $entityType = $entity->getEntityType();

        if ($this->serviceFactory->checkExists($entityType)) {
            $service = $this->serviceFactory->create($entityType);
        } else {
            $service = $this->serviceFactory->create('Record');
        }

        $service->loadAdditionalFields($entity);

        if (method_exists($service, 'loadAdditionalFieldsForPdf')) {
            $service->loadAdditionalFieldsForPdf($entity);
        }

        if ($template->get('entityType') !== $entityType) {
            throw new Forbidden();
        }

        if (!$this->acl->check($entity, 'read') || !$this->acl->check($template, 'read')) {
            throw new Forbidden();
        }

        $htmlizer = $this->createHtmlizer();
        $pdf = new Tcpdf();

        $this->printEntity($entity, $template, $htmlizer, $pdf, $additionalData);

        if ($displayInline) {
            $name = $entity->get('name');
            $name = Util::sanitizeFileName($name);
            $fileName = $name . '.pdf';

            $pdf->output($fileName, 'I');
            return;
        }

        return $pdf->output('', 'S');
    }

    protected function createHtmlizer()
    {
        return $this->htmlizerFactory->create();
    }
}
