<?php

namespace App\Controller\Admin;

use App\Entity\Microstructure;
use App\Repository\MicrostructureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

class SnapshotController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MicrostructureRepository $microstructures,
        private readonly MailerInterface $mailer,
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
        $comment = trim((string) ($payload['comment'] ?? '')) ?: null;

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
            ->setDate(new \DateTimeImmutable())
            ->setComment($comment);

        $this->em->persist($microstructure);
        $this->em->flush();

        return $this->json([
            'ok' => true,
            'filename' => $filename,
            'url' => '/uploads/snapshots/'.$filename,
            'message' => 'Image saved!'
        ]);
    }

    #[Route('/admin/snapshot/download', name: 'admin_snapshot_download', methods: ['POST'])]
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $filenames = is_array($payload['filenames'] ?? null) ? $payload['filenames'] : [];

        if ($filenames === []) {
            return $this->json(['error' => 'No images in session'], 400);
        }

        $dir = $this->getParameter('kernel.project_dir').'/public/uploads/snapshots';
        $zipPath = tempnam(sys_get_temp_dir(), 'snapshots_').'.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->json(['error' => 'Cannot create ZIP'], 500);
        }

        $added = 0;
        foreach ($filenames as $filename) {
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
            return $this->json(['error' => 'No matching files found'], 404);
        }

        $archiveName = sprintf('microstructures_%s.zip', (new \DateTimeImmutable())->format('Ymd_His'));

        $response = new BinaryFileResponse($zipPath);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $archiveName);

        return $response;
    }

    #[Route('/admin/snapshot/export', name: 'admin_snapshot_export', methods: ['POST'])]
    public function export(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $filenames = is_array($payload['filenames'] ?? null) ? $payload['filenames'] : [];
        $email = trim((string) ($payload['email'] ?? ''));

        $emailErrors = Validation::createValidator()->validate($email, [
            new Assert\NotBlank(),
            new Assert\Email(),
        ]);
        if (count($emailErrors) > 0) {
            return $this->json(['error' => 'Invalid email address'], 400);
        }

        if ($filenames === []) {
            return $this->json(['error' => 'No images in session'], 400);
        }

        $dir = $this->getParameter('kernel.project_dir').'/public/uploads/snapshots';
        $zipPath = tempnam(sys_get_temp_dir(), 'snapshots_').'.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->json(['error' => 'Cannot create ZIP'], 500);
        }

        $added = 0;
        foreach ($filenames as $filename) {
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
            return $this->json(['error' => 'No matching files found'], 404);
        }

        $archiveName = sprintf('microstructures_%s.zip', (new \DateTimeImmutable())->format('Ymd_His'));
        $zipBytes = file_get_contents($zipPath);
        @unlink($zipPath);

        $message = (new Email())
            ->from('lab30@gidev.com.ua')
            ->to($email)
            ->subject('Microstructure session export')
            ->text(sprintf('Attached: %d image(s) from the current session.', $added))
            ->attach($zipBytes, $archiveName, 'application/zip');

        try {
            $this->mailer->send($message);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Mail failed: '.$e->getMessage()], 500);
        }

        return $this->json([
            'ok' => true,
            'message' => sprintf('Sent %d image(s) to %s', $added, $email),
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
