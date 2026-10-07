<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SubmissionPhotoStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads/%kernel.environment%')]
        private string $directory,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    public function store(UploadedFile $photo): string
    {
        $filename = bin2hex(random_bytes(16)).'.'.$photo->guessExtension();
        $photo->move($this->directory, $filename);

        return $filename;
    }

    public function path(string $filename): string
    {
        return $this->directory.'/'.$filename;
    }

    public function remove(?string $filename): void
    {
        if (null === $filename) {
            return;
        }

        try {
            $this->filesystem->remove($this->path($filename));
        } catch (IOExceptionInterface $exception) {
            $this->logger->warning('Não foi possível remover a foto substituída.', ['exception' => $exception]);
        }
    }
}
