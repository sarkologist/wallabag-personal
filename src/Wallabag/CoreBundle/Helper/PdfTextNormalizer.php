<?php

namespace Wallabag\CoreBundle\Helper;

class PdfTextNormalizer
{
    private const MARGIN_LINE_LIMIT = 4;

    public function normalize($text)
    {
        $text = $this->normalizeLigatures((string) $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]+/', ' ', $text);

        $pages = preg_split('/\f+/', (string) $text);
        if (false === $pages) {
            $pages = [(string) $text];
        }

        $pages = $this->cleanPages($pages);
        $recurringMarginLines = $this->findRecurringMarginLines($pages);
        $pages = $this->removeRecurringMarginLines($pages, $recurringMarginLines);

        $text = implode("\n", array_map(static function (array $lines) {
            return trim(implode("\n", $lines));
        }, $pages));

        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        return trim((string) $text);
    }

    private function normalizeLigatures($text)
    {
        return strtr((string) $text, [
            "\u{FB00}" => 'ff',
            "\u{FB01}" => 'fi',
            "\u{FB02}" => 'fl',
            "\u{FB03}" => 'ffi',
            "\u{FB04}" => 'ffl',
            "\u{FB05}" => 'st',
            "\u{FB06}" => 'st',
        ]);
    }

    private function cleanPages(array $pages)
    {
        $cleanedPages = [];

        foreach ($pages as $page) {
            $lines = explode("\n", (string) $page);
            $cleanedLines = [];

            foreach ($lines as $line) {
                $line = trim((string) preg_replace('/[ \t\x0B\x{00A0}]+/u', ' ', $line));

                if ($this->isStandalonePageNumber($line)) {
                    continue;
                }

                $cleanedLines[] = $line;
            }

            $cleanedPages[] = $this->trimEmptyLines($cleanedLines);
        }

        return $cleanedPages;
    }

    private function findRecurringMarginLines(array $pages)
    {
        $counts = [];

        foreach ($pages as $lines) {
            $seenOnPage = [];

            foreach ($this->marginLineKeys($lines) as $key) {
                $seenOnPage[$key] = true;
            }

            foreach (array_keys($seenOnPage) as $key) {
                if (!isset($counts[$key])) {
                    $counts[$key] = 0;
                }

                ++$counts[$key];
            }
        }

        return array_filter($counts, static function ($count) {
            return $count >= 2;
        });
    }

    private function marginLineKeys(array $lines)
    {
        $keys = [];
        $nonEmptyIndexes = $this->nonEmptyIndexes($lines);
        $marginIndexes = array_unique(array_merge(
            array_slice($nonEmptyIndexes, 0, self::MARGIN_LINE_LIMIT),
            array_slice($nonEmptyIndexes, -self::MARGIN_LINE_LIMIT)
        ));

        foreach ($marginIndexes as $index) {
            foreach ($this->lineKeys($lines[$index]) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function removeRecurringMarginLines(array $pages, array $recurringMarginLines)
    {
        if (empty($recurringMarginLines)) {
            return $pages;
        }

        foreach ($pages as $pageIndex => $lines) {
            $nonEmptyIndexes = $this->nonEmptyIndexes($lines);
            $topIndexes = array_slice($nonEmptyIndexes, 0, self::MARGIN_LINE_LIMIT);
            $bottomIndexes = array_slice($nonEmptyIndexes, -self::MARGIN_LINE_LIMIT);
            $marginIndexes = array_unique(array_merge($topIndexes, $bottomIndexes));

            foreach ($marginIndexes as $lineIndex) {
                if (!$this->hasRecurringLineKey($lines[$lineIndex], $recurringMarginLines)) {
                    continue;
                }

                $lines[$lineIndex] = '';
            }

            $pages[$pageIndex] = $this->trimEmptyLines($lines);
        }

        return $pages;
    }

    private function hasRecurringLineKey($line, array $recurringMarginLines)
    {
        foreach ($this->lineKeys($line) as $key) {
            if (isset($recurringMarginLines[$key])) {
                return true;
            }
        }

        return false;
    }

    private function nonEmptyIndexes(array $lines)
    {
        $indexes = [];

        foreach ($lines as $index => $line) {
            if ('' !== trim((string) $line)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    private function trimEmptyLines(array $lines)
    {
        while (!empty($lines) && '' === trim((string) reset($lines))) {
            array_shift($lines);
        }

        while (!empty($lines) && '' === trim((string) end($lines))) {
            array_pop($lines);
        }

        return array_values($lines);
    }

    private function lineKey($line)
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', (string) $line));

        if ('' === $line || $this->isStandalonePageNumber($line) || \strlen($line) > 160) {
            return null;
        }

        if (0 === preg_match('/\p{L}/u', $line)) {
            return null;
        }

        return mb_strtolower($line, 'UTF-8');
    }

    private function lineKeys($line)
    {
        $keys = [];
        $exactKey = $this->lineKey($line);

        if (null !== $exactKey) {
            $keys[] = 'exact:' . $exactKey;
        }

        $fuzzyKey = $this->fuzzyMarginLineKey($line);

        if (null !== $fuzzyKey) {
            $keys[] = 'fuzzy:' . $fuzzyKey;
        }

        return $keys;
    }

    private function fuzzyMarginLineKey($line)
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', (string) $line));

        if ('' === $line || $this->isStandalonePageNumber($line) || \strlen($line) > 160) {
            return null;
        }

        $line = $this->stripMarginPageNumberTokens($line);
        $line = strtr($line, [
            '0' => 'O',
            '£' => 'E',
            '!' => 'I',
        ]);
        $line = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $line);

        if (null === $line || mb_strlen($line, 'UTF-8') < 8) {
            return null;
        }

        if (0 === preg_match('/\p{L}/u', $line)) {
            return null;
        }

        return mb_strtolower($line, 'UTF-8');
    }

    private function stripMarginPageNumberTokens($line)
    {
        $line = preg_replace('/^(?:page\s*)?(?:\d{1,4}|[tTIl|]\d{1,4})\s+/u', '', (string) $line);
        $line = preg_replace('/\s+(?:page\s*)?(?:\d{1,4}|[tTIl|]\d{1,4})$/u', '', (string) $line);

        return trim((string) $line);
    }

    private function isStandalonePageNumber($line)
    {
        return 1 === preg_match('/^(?:page\s*)?\d+$/i', trim((string) $line));
    }
}
