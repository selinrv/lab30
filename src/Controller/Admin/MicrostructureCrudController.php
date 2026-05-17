<?php

namespace App\Controller\Admin;

use App\Entity\Microstructure;
use App\Repository\MicrostructureRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class MicrostructureCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminContextProvider $contextProvider,
        private readonly MicrostructureRepository $microstructures,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Microstructure::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Microstructure')
            ->setEntityLabelInPlural('Microstructures')
            ->setDefaultSort(['date' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action
                ->setLabel('Add Microstructure')
                ->linkToRoute('admin'))
            ->addBatchAction(Action::new('downloadZip', 'Download ZIP')
                ->linkToCrudAction('downloadZipBatch')
                ->setIcon('fa fa-download'));
    }

    public function downloadZipBatch(BatchActionDto $batchActionDto): Response
    {
        $items = $this->microstructures->findBy(['id' => $batchActionDto->getEntityIds()]);
        if ($items === []) {
            $this->addFlash('warning', 'No items selected.');
            return $this->redirect($batchActionDto->getReferrerUrl());
        }

        $dir = $this->getParameter('kernel.project_dir').'/public/uploads/snapshots';
        $zipPath = tempnam(sys_get_temp_dir(), 'snapshots_').'.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->addFlash('danger', 'Cannot create ZIP.');
            return $this->redirect($batchActionDto->getReferrerUrl());
        }

        $added = 0;
        foreach ($items as $item) {
            $filename = $item->getFilename();
            if (!is_string($filename) || !preg_match('/^[A-Za-z0-9._-]+\.png$/', $filename)) {
                continue;
            }
            $path = $dir.'/'.$filename;
            if (is_file($path)) {
                $zip->addFile($path, $filename);
                $added++;
            }
        }
        $zip->close();

        if ($added === 0) {
            @unlink($zipPath);
            $this->addFlash('warning', 'No matching files found.');
            return $this->redirect($batchActionDto->getReferrerUrl());
        }

        $archiveName = sprintf('microstructures_%s.zip', (new \DateTimeImmutable())->format('Ymd_His'));

        $response = new BinaryFileResponse($zipPath);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $archiveName);

        return $response;
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = [
            IdField::new('id')->hideOnForm(),
            TextField::new('alloy'),
            TextField::new('position'),
            TextField::new('scale'),
            TextField::new('comment'),
            ImageField::new('filename')
                ->setBasePath('/uploads/snapshots')
                ->setLabel('Picture')
                ->hideOnForm(),
            DateTimeField::new('date')->setTimezone('Europe/Warsaw'),
        ];

        if ($pageName === Crud::PAGE_EDIT) {
            $entity = $this->contextProvider->getContext()?->getEntity()?->getInstance();
            if ($entity instanceof Microstructure && $entity->getFilename() !== null) {
                $fields[] = FormField::addPanel('Picture')
                    ->setHelp(sprintf(
                        '<img src="/uploads/snapshots/%s" alt="" style="max-width:100%%; height:auto;">',
                        htmlspecialchars($entity->getFilename(), ENT_QUOTES)
                    ))
                    ->setFormTypeOption('help_html', true);
            }
        }

        return $fields;
    }
}
