<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SnapshotController extends AbstractController
{
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

        $magnification = preg_replace('/[^A-Za-z0-9]/', '', (string) ($payload['magnification'] ?? 'unknown'));
        $dir = $this->getParameter('kernel.project_dir').'/public/uploads/snapshots';

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->json(['error' => 'Cannot create directory'], 500);
        }

        $filename = sprintf('snapshot-%s-%s-x%s.png', date('Ymd-His'), bin2hex(random_bytes(3)), $magnification);
        file_put_contents($dir.'/'.$filename, $binary);

        return $this->json([
            'ok' => true,
            'filename' => $filename,
            'url' => '/uploads/snapshots/'.$filename,
            'message' => 'Image saved!'
        ]);
    }

    #[Route('/admin/snapshot/{filename}', name: 'admin_snapshot_delete', methods: ['DELETE'], requirements: ['filename' => 'snapshot-[A-Za-z0-9._-]+\.png'])]
    public function delete(string $filename): JsonResponse
    {
        $path = $this->getParameter('kernel.project_dir').'/public/uploads/snapshots/'.$filename;
        if (!is_file($path)) {
            return $this->json(['error' => 'Not found'], 404);
        }

        if (!unlink($path)) {
            return $this->json(['error' => 'Failed to delete'], 500);
        }
        flash()->success('Picture delete.');
        return $this->json([
            'ok' => true,
            'message' => 'Picture delete',
            ]);
    }
}
