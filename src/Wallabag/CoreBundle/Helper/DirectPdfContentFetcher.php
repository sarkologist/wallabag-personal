<?php

namespace Wallabag\CoreBundle\Helper;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Smalot\PdfParser\Parser as PdfParser;

class DirectPdfContentFetcher
{
    private $logger;
    private $popplerPdfTextExtractor;
    private $pdfTextNormalizer;

    public function __construct(?LoggerInterface $logger = null, ?PopplerPdfTextExtractor $popplerPdfTextExtractor = null, ?PdfTextNormalizer $pdfTextNormalizer = null)
    {
        $this->logger = $logger ?: new NullLogger();
        $this->popplerPdfTextExtractor = $popplerPdfTextExtractor ?: new PopplerPdfTextExtractor();
        $this->pdfTextNormalizer = $pdfTextNormalizer ?: new PdfTextNormalizer();
    }

    public function supports($url)
    {
        $path = parse_url((string) $url, \PHP_URL_PATH);

        return \is_string($path) && 1 === preg_match('/\.pdf$/i', $path);
    }

    public function fetch($url)
    {
        $response = $this->download($url);

        if (!$this->isPdfResponse($response)) {
            throw new \RuntimeException(sprintf('Url "%s" did not return a PDF response.', $url));
        }

        $parsedPdf = null;
        $parserException = null;

        try {
            $parsedPdf = $this->parsePdfContent($response['body']);
        } catch (\Throwable $e) {
            $parserException = $e;
        }

        $details = $parsedPdf ? $parsedPdf['details'] : [];
        $text = $parsedPdf ? $parsedPdf['text'] : '';

        try {
            $popplerText = $this->extractTextWithPoppler($response['body']);

            if ('' !== trim($popplerText)) {
                $text = $popplerText;
            }
        } catch (\Throwable $e) {
            $this->logger->info('Poppler pdftotext extraction failed. Falling back to the PHP PDF parser.', [
                'exception' => $e,
                'url' => $url,
            ]);
        }

        if ('' === trim($text) && null !== $parserException) {
            throw $parserException;
        }

        $text = $this->pdfTextNormalizer->normalize($text);

        $html = mb_convert_encoding(nl2br($text), 'UTF-8', 'UTF-8');
        $html = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $html);

        return [
            'status' => $response['status'] ?: 200,
            'title' => $this->extractTitle($details, $text, $url),
            'language' => '',
            'date' => null,
            'authors' => array_filter([$this->extractFirstDetail($details, 'Author')]),
            'html' => (string) $html,
            'url' => $url,
            'image' => '',
            'native_ad' => false,
            'headers' => $response['headers'],
        ];
    }

    protected function parsePdfContent($body)
    {
        $parser = new PdfParser();
        $pdf = $parser->parseContent($body);

        return [
            'details' => $pdf->getDetails(),
            'text' => $pdf->getText(),
        ];
    }

    protected function extractTextWithPoppler($body)
    {
        return $this->popplerPdfTextExtractor->extract($body);
    }

    protected function download($url)
    {
        try {
            return $this->downloadWithTlsVerification($url, true);
        } catch (\RuntimeException $e) {
            if (0 !== strpos((string) $url, 'https://')) {
                throw $e;
            }

            $this->logger->warning('Verified direct PDF download failed. Retrying with relaxed TLS verification.', [
                'exception' => $e,
                'url' => $url,
            ]);

            return $this->downloadWithTlsVerification($url, false);
        }
    }

    protected function downloadWithTlsVerification($url, $verifyPeer)
    {
        $headers = [
            'User-Agent: PHP/' . \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION,
            'Accept: application/pdf,*/*',
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'follow_location' => 1,
                'ignore_errors' => true,
                'max_redirects' => 10,
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
            ],
        ]);

        $errorMessage = null;
        set_error_handler(static function ($type, $message) use (&$errorMessage) {
            $errorMessage = $message;
            return true;
        });

        try {
            $body = @file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        $responseHeaders = isset($http_response_header) ? $http_response_header : [];
        $response = $this->parseResponseHeaders($responseHeaders);

        if (false === $body) {
            $message = sprintf('Unable to download PDF from "%s".', $url);
            if (null !== $errorMessage) {
                $message .= ' ' . $errorMessage;
            }

            throw new \RuntimeException($message);
        }

        $response['body'] = $body;

        return $response;
    }

    private function isPdfResponse(array $response)
    {
        $contentType = isset($response['headers']['content-type']) ? strtolower($response['headers']['content-type']) : '';

        return 0 === strpos($contentType, 'application/pdf')
            || 0 === strpos($response['body'], '%PDF-');
    }

    private function parseResponseHeaders(array $rawHeaders)
    {
        $status = null;
        $headers = [];

        foreach ($rawHeaders as $line) {
            if (0 === stripos($line, 'HTTP/')) {
                $headers = [];
                $status = $this->parseStatusCode($line);
                continue;
            }

            $separator = strpos($line, ':');
            if (false === $separator) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));

            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        return [
            'status' => $status,
            'headers' => $headers,
        ];
    }

    private function parseStatusCode($line)
    {
        if (1 === preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractFirstDetail(array $details, $key)
    {
        if (!isset($details[$key])) {
            return null;
        }

        $detail = $details[$key];
        if (\is_array($detail)) {
            $detail = reset($detail);
        }

        if (!\is_string($detail)) {
            return null;
        }

        $detail = trim($detail);

        return '' === $detail ? null : $detail;
    }

    private function extractTitle(array $details, $text, $url)
    {
        $metadataTitle = $this->extractFirstDetail($details, 'Title');
        if ($this->isUsefulTitle($metadataTitle)) {
            return $metadataTitle;
        }

        $textTitle = $this->titleFromText($text);
        if (null !== $textTitle) {
            return $textTitle;
        }

        return $metadataTitle ?: $this->titleFromUrl($url);
    }

    private function isUsefulTitle($title)
    {
        if (!\is_string($title) || '' === trim($title)) {
            return false;
        }

        return 0 === preg_match('/\.(?:docx?|pdf|rtf|odt|dvi)\b/i', $title);
    }

    private function titleFromText($text)
    {
        $lines = preg_split('/\R/u', str_replace(["\r\n", "\r"], "\n", (string) $text));
        $titleLines = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));

            if (!$this->isLikelyTitleLine($line)) {
                continue;
            }

            $titleLines[] = $line;
            if (2 === \count($titleLines) || \strlen(implode(' ', $titleLines)) >= 80) {
                break;
            }
        }

        if (empty($titleLines)) {
            return null;
        }

        return implode(' ', $titleLines);
    }

    private function isLikelyTitleLine($line)
    {
        if ('' === $line || \strlen($line) > 160) {
            return false;
        }

        return 0 === preg_match('/^(?:\d+|page\s+\d+)$/i', $line);
    }

    private function titleFromUrl($url)
    {
        $path = parse_url((string) $url, \PHP_URL_PATH);
        $title = \is_string($path) ? pathinfo($path, \PATHINFO_BASENAME) : '';

        return '' === $title ? 'PDF' : $title;
    }
}
