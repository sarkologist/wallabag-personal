<?php

namespace Wallabag\CoreBundle\Helper;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PopplerPdfTextExtractor
{
    private $binary;
    private $executableFinder;

    public function __construct($binary = null, ?ExecutableFinder $executableFinder = null)
    {
        $this->binary = $binary;
        $this->executableFinder = $executableFinder ?: new ExecutableFinder();
    }

    public function extract($content)
    {
        $binary = $this->binary ?: $this->executableFinder->find('pdftotext');

        if (null === $binary) {
            throw new \RuntimeException('The pdftotext binary was not found.');
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'wallabag_pdf_');
        if (false === $inputPath) {
            throw new \RuntimeException('Unable to create a temporary PDF file.');
        }

        $outputPath = $inputPath . '.txt';

        try {
            if (false === file_put_contents($inputPath, $content)) {
                throw new \RuntimeException('Unable to write the temporary PDF file.');
            }

            $process = new Process([$binary, '-raw', '-enc', 'UTF-8', $inputPath, $outputPath]);
            $process->setTimeout(30);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'pdftotext failed to extract PDF text.');
            }

            if (!is_file($outputPath)) {
                throw new \RuntimeException('pdftotext did not create an output file.');
            }

            $text = file_get_contents($outputPath);
            if (false === $text) {
                throw new \RuntimeException('Unable to read the pdftotext output file.');
            }

            return $text;
        } finally {
            if (is_file($inputPath)) {
                @unlink($inputPath);
            }

            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }
}
