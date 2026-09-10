<?php

namespace App\Service;

final readonly class CompressedImage
{
    public function __construct(
        public string $bytes,
        public string $extension,
        public string $mimeType,
    ) {
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }
}
