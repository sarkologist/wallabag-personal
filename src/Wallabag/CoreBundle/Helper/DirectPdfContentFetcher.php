<?php

namespace Wallabag\CoreBundle\Helper;

use Smalot\PdfParser\Parser as PdfParser;

class DirectPdfContentFetcher
{
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

        $parser = new PdfParser();
        $pdf = $parser->parseContent($response['body']);
        $details = $pdf->getDetails();
        $text = $pdf->getText();

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

    protected function download($url)
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
        ]);

        $body = @file_get_contents($url, false, $context);
        $responseHeaders = isset($http_response_header) ? $http_response_header : [];
        $response = $this->parseResponseHeaders($responseHeaders);

        if (false === $body) {
            throw new \RuntimeException(sprintf('Unable to download PDF from "%s".', $url));
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

        return 0 === preg_match('/\.(?:docx?|pdf|rtf|odt)\b/i', $title);
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
