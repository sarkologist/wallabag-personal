<?php

namespace Wallabag\CoreBundle\Helper;

class PdfContentFormatter
{
    private $pdfTextNormalizer;

    public function __construct(?PdfTextNormalizer $pdfTextNormalizer = null)
    {
        $this->pdfTextNormalizer = $pdfTextNormalizer ?: new PdfTextNormalizer();
    }

    public function format($content, $mimetype)
    {
        if (!$this->shouldFormat($content, $mimetype)) {
            return $content;
        }

        $text = preg_replace('~<br\s*/?>[ \t]*(?:\r\n|\r|\n)?~i', "\n", (string) $content);
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = $this->pdfTextNormalizer->normalize($text);
        $text = preg_replace('/[ \t\x0B\f\x{00A0}]+/u', ' ', $text);
        $text = trim((string) $text);

        if ('' === $text) {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/', preg_replace('/\n{3,}/', "\n\n", $text));
        $html = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = $this->formatParagraph(explode("\n", $paragraph));

            if ('' === $paragraph) {
                continue;
            }

            $html[] = '<p>' . htmlspecialchars($paragraph, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return implode('', $html);
    }

    private function shouldFormat($content, $mimetype)
    {
        if (!$this->isPdf($mimetype) || !\is_string($content)) {
            return false;
        }

        return 1 === preg_match('~<br\s*/?>~i', $content);
    }

    private function isPdf($mimetype)
    {
        if (!\is_string($mimetype)) {
            return false;
        }

        $mimetype = strtolower(trim(explode(';', $mimetype)[0]));

        return 'application/pdf' === $mimetype;
    }

    private function formatParagraph(array $lines)
    {
        $paragraph = '';

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ('' === $line) {
                continue;
            }

            if ('' === $paragraph) {
                $paragraph = $line;
                continue;
            }

            if ($this->canJoinHyphenatedLine($paragraph, $line)) {
                $paragraph = substr($paragraph, 0, -1) . $line;
                continue;
            }

            if ($this->canKeepHyphenatedLine($paragraph, $line)) {
                $paragraph .= $line;
                continue;
            }

            $paragraph .= ' ' . $line;
        }

        return $this->normalizePunctuationSpacing($paragraph);
    }

    private function canJoinHyphenatedLine($previousLine, $nextLine)
    {
        return 1 === preg_match('/[\p{L}]{2,}-$/u', $previousLine)
            && !$this->endsWithHardHyphenPrefix($previousLine)
            && 1 === preg_match('/^\p{Ll}/u', $nextLine);
    }

    private function canKeepHyphenatedLine($previousLine, $nextLine)
    {
        return 1 === preg_match('/[\p{L}]{2,}-$/u', $previousLine)
            && $this->endsWithHardHyphenPrefix($previousLine)
            && 1 === preg_match('/^\p{Ll}/u', $nextLine);
    }

    private function endsWithHardHyphenPrefix($line)
    {
        if (0 === preg_match('/([\p{L}]+)-$/u', $line, $matches)) {
            return false;
        }

        return \in_array(mb_strtolower($matches[1], 'UTF-8'), [
            'anti',
            'co',
            'counter',
            'cross',
            'ex',
            'extra',
            'inter',
            'intra',
            'macro',
            'micro',
            'mid',
            'mini',
            'multi',
            'non',
            'over',
            'post',
            'pre',
            'pro',
            're',
            'self',
            'semi',
            'sub',
            'super',
            'trans',
            'ultra',
            'under',
        ], true);
    }

    private function normalizePunctuationSpacing($paragraph)
    {
        $paragraph = preg_replace('/\s+([,.;:?!\)\]\}])/u', '$1', $paragraph);
        $paragraph = preg_replace('/([\(\[\{])\s+/u', '$1', (string) $paragraph);

        return trim((string) $paragraph);
    }
}
