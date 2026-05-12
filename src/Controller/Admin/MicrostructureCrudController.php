<?php

namespace App\Controller\Admin;

use App\Entity\Microstructure;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class MicrostructureCrudController extends AbstractCrudController
{
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

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('alloy'),
            TextField::new('position'),
            TextField::new('scale'),
            TextField::new('filename'),
            DateTimeField::new('date'),
        ];
    }
}
