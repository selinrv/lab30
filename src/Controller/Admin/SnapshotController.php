<?php

namespace App\Controller\Admin;

use App\Entity\Microstructure;
use App\Repository\MicrostructureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SnapshotController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MicrostructureRepository $microstructures,
    ) {
    }

    #[Route('/admin/snapshot', name: 'admin_snapshot', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || empty($payload['image'])) {
            return $this->json(['error' => 'Missing image'], 400);
        }

        if (!preg_match('#^data:image/png;base64,(.+)$#', $payload['image'], $matches)) {
            return $this->json(['error' => 'Invalid image format'], 400);
        }

        $binary = base64_decode($matches[1], true);
        if ($binary === false) {
            return $this->json(['error' => 'Invalid base64'], 400);
        }

        $sanitize = static fn (string $v): string => preg_replace('/[^A-Za-z0-9]/', '', $v);

        $magnification = $sanitize((string) ($payload['magnification'] ?? 'unknown'));
        $alloy = $sanitize((string) ($payload['alloy'] ?? '')) ?: 'unknown';
        $position = $sanitize((string) ($payload['position'] ?? '')) ?: 'unknown';

        $dir = $this->getParameter('kernel.project_dir').'/public/uploads/snapshots';

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->json(['error' => 'Cannot create directory'], 500);
        }

        $base = sprintf('%s_%s_x%s', $alloy, $position, $magnification);
        $filename = $base.'.png';
        $i = 1;
        while (file_exists($dir.'/'.$filename)) {
            $filename = sprintf('%s_%d.png', $base, $i++);
        }
        file_put_contents($dir.'/'.$filename, $binary);

        $microstructure = (new Microstructure())
            ->setScale($magnification)
            ->setAlloy($alloy)
            ->setPosition($position)
            ->setFilename($filename)
            ->setDate(new \DateTimeImmutable());

        $this->em->persist($microstructure);
        $this->em->flush();

        return $this->json([
            'ok' => true,
            'filename' => $filename,
            'url' => '/uploads/snapshots/'.$filename,
            'message' => 'Image saved!'
        ]);
    }

    #[Route('/admin/snapshot/{filename}', name: 'admin_snapshot_delete', methods: ['DELETE'], requirements: ['filename' => '[A-Za-z0-9._-]+\.png'])]
    public function delete(string $filename): JsonResponse
    {
        $path = $this->getParameter('kernel.project_dir').'/public/uploads/snapshots/'.$filename;
        if (!is_file($path)) {
            return $this->json(['error' => 'Not found'], 404);
        }

        if (!unlink($path)) {
            return $this->json(['error' => 'Failed to delete'], 500);
        }

        $microstructure = $this->microstructures->findOneBy(['filename' => $filename]);
        if ($microstructure !== null) {
            $this->em->remove($microstructure);
            $this->em->flush();
        }

        flash()->success('Picture delete.');
        return $this->json([
            'ok' => true,
            'message' => 'Picture delete',
            ]);
    }
}
