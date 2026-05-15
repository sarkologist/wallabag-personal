<?php

namespace Tests\Wallabag\CoreBundle\Helper;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Wallabag\CoreBundle\Helper\DirectPdfContentFetcher;

class DirectPdfContentFetcherTest extends TestCase
{
    public function testRetriesHttpsDownloadWithRelaxedTlsWhenVerifiedDownloadFails()
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $fetcher = new TestableDirectPdfContentFetcher([
            new \RuntimeException('Unable to download PDF from "https://example.com/file.pdf". operation failed'),
            [
                'status' => 200,
                'headers' => [
                    'content-type' => 'application/pdf',
                ],
                'body' => '%PDF-1.1 test',
            ],
        ], $logger);

        $response = $fetcher->downloadPublic('https://example.com/file.pdf');

        $this->assertSame('%PDF-1.1 test', $response['body']);
        $this->assertSame([true, false], $fetcher->tlsVerificationAttempts);
        $this->assertTrue($handler->hasWarningRecords());
    }

    public function testDoesNotRelaxTlsForHttpDownloadFailure()
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $fetcher = new TestableDirectPdfContentFetcher([
            new \RuntimeException('Unable to download PDF from "http://example.com/file.pdf".'),
        ], $logger);

        try {
            $fetcher->downloadPublic('http://example.com/file.pdf');
            $this->fail('Expected HTTP download failure to be thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame([true], $fetcher->tlsVerificationAttempts);
            $this->assertFalse($handler->hasWarningRecords());
        }
    }

    public function testRejectsRelaxedTlsRetryWhenResponseIsNotPdf()
    {
        $fetcher = new TestableDirectPdfContentFetcher([
            new \RuntimeException('Unable to download PDF from "https://example.com/file.pdf". operation failed'),
            [
                'status' => 200,
                'headers' => [
                    'content-type' => 'text/html',
                ],
                'body' => '<html></html>',
            ],
        ]);

        try {
            $fetcher->fetch('https://example.com/file.pdf');
            $this->fail('Expected non-PDF response to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not return a PDF response', $e->getMessage());
            $this->assertSame([true, false], $fetcher->tlsVerificationAttempts);
        }
    }

    public function testRejectsDocumentFilenamesAsUsefulMetadataTitles()
    {
        $method = new \ReflectionMethod(DirectPdfContentFetcher::class, 'isUsefulTitle');
        $method->setAccessible(true);
        $fetcher = new DirectPdfContentFetcher();

        $this->assertFalse($method->invoke($fetcher, 'varela.dvi'));
        $this->assertFalse($method->invoke($fetcher, 'paper.pdf'));
        $this->assertTrue($method->invoke($fetcher, 'Autopoiesis and a Biology of Intentionality'));
    }

    public function testUsesPopplerTextWhenAvailable()
    {
        $fetcher = new TestableDirectPdfContentFetcher([
            [
                'status' => 200,
                'headers' => [
                    'content-type' => 'application/pdf',
                ],
                'body' => '%PDF-1.1 test',
            ],
        ]);
        $fetcher->parsedText = 'arti cial de nition speci c pro ting is not intrinsic';
        $fetcher->popplerText = 'artificial definition specific profiting is not intrinsic';

        $response = $fetcher->fetch('https://example.com/file.pdf');

        $this->assertSame('artificial definition specific profiting is not intrinsic', $response['html']);
        $this->assertSame(1, $fetcher->popplerExtractionAttempts);
        $this->assertSame('artificial definition specific profiting is not intrinsic', $response['title']);
    }

    public function testFallsBackToPhpPdfParserTextWhenPopplerFails()
    {
        $fetcher = new TestableDirectPdfContentFetcher([
            [
                'status' => 200,
                'headers' => [
                    'content-type' => 'application/pdf',
                ],
                'body' => '%PDF-1.1 test',
            ],
        ]);
        $fetcher->parsedText = 'Parser text survived.';
        $fetcher->popplerException = new \RuntimeException('pdftotext failed');

        $response = $fetcher->fetch('https://example.com/file.pdf');

        $this->assertSame('Parser text survived.', $response['html']);
        $this->assertSame(1, $fetcher->popplerExtractionAttempts);
    }
}

class TestableDirectPdfContentFetcher extends DirectPdfContentFetcher
{
    public $tlsVerificationAttempts = [];
    public $parsedDetails = [];
    public $parsedText = '';
    public $parserException;
    public $popplerText = '';
    public $popplerException;
    public $popplerExtractionAttempts = 0;
    private $responses;

    public function __construct(array $responses, ?Logger $logger = null)
    {
        parent::__construct($logger);
        $this->responses = $responses;
    }

    public function downloadPublic($url)
    {
        return $this->download($url);
    }

    protected function downloadWithTlsVerification($url, $verifyPeer)
    {
        $this->tlsVerificationAttempts[] = $verifyPeer;
        $response = array_shift($this->responses);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    protected function parsePdfContent($body)
    {
        if ($this->parserException instanceof \Throwable) {
            throw $this->parserException;
        }

        return [
            'details' => $this->parsedDetails,
            'text' => $this->parsedText,
        ];
    }

    protected function extractTextWithPoppler($body)
    {
        ++$this->popplerExtractionAttempts;

        if ($this->popplerException instanceof \Throwable) {
            throw $this->popplerException;
        }

        return $this->popplerText;
    }
}
