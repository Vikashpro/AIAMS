<?php

namespace Smalot\PdfParser;

class Parser
{
    public function parseContent(string $contents): Document
    {
        $segments = [];

        foreach ($this->extractStreams($contents) as $stream) {
            $decoded = $this->decodeStream($stream['data'], $stream['filters']);

            if ($decoded !== null) {
                $segments[] = $this->extractTextTokens($decoded);
            }
        }

        // Fallback to the raw document so we still capture plain tokens.
        $segments[] = $this->extractTextTokens($contents);

        $text = trim(implode("\n", array_filter($segments)));

        return new Document($text);
    }

    private function extractStreams(string $contents): array
    {
        $streams = [];
        $pattern = '/<<(.*?)>>\s*stream\s*(.*?)\s*endstream/s';
        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $streams[] = [
                    'dictionary' => $match[1],
                    'data' => $this->cleanupStreamData($match[2]),
                    'filters' => $this->parseFilters($match[1]),
                ];
            }
        }

        return $streams;
    }

    private function cleanupStreamData(string $data): string
    {
        $data = ltrim($data, "\r\n");
        return $data;
    }

    private function parseFilters(string $dictionary): array
    {
        $filters = [];

        if (preg_match('/\/Filter\s+(\[[^\]]+\]|\/[^\s]+)/', $dictionary, $match)) {
            $raw = trim($match[1]);

            if (str_starts_with($raw, '[')) {
                preg_match_all('/\/([A-Za-z0-9#]+)/', $raw, $filterMatches);
                foreach ($filterMatches[1] ?? [] as $filter) {
                    $filters[] = $filter;
                }
            } else {
                $filters[] = ltrim($raw, '/');
            }
        }

        return $filters;
    }

    private function decodeStream(string $data, array $filters): ?string
    {
        $decoded = $data;

        foreach ($filters as $filter) {
            if (strcasecmp($filter, 'FlateDecode') === 0) {
                $decoded = $this->inflate($decoded);
            }
        }

        return $decoded;
    }

    private function inflate(string $data): ?string
    {
        $result = @zlib_decode($data);

        if ($result === false) {
            $result = @gzuncompress($data);
        }

        if ($result === false) {
            $result = @gzinflate($data);
        }

        return $result === false ? null : $result;
    }

    private function extractTextTokens(?string $contents): string
    {
        if ($contents === null || $contents === '') {
            return '';
        }

        $segments = [];
        if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $contents, $matches)) {
            foreach ($matches[0] as $segment) {
                $segments[] = $this->decodePdfString($segment);
            }
        }

        if (preg_match_all('/<([0-9A-Fa-f]+)>/', $contents, $hexMatches)) {
            foreach ($hexMatches[1] as $hexString) {
                $segments[] = $this->decodeHexString($hexString);
            }
        }

        return trim(implode(' ', array_filter($segments)));
    }

    private function decodePdfString(string $value): string
    {
        $value = substr($value, 1, -1);
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($matches) {
            return chr(octdec($matches[1]));
        }, $value) ?? $value;

        $replacements = [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\f' => "\f",
            '\\b' => "\b",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ];

        $value = strtr($value, $replacements);

        return $this->normalizeEncoding($value);
    }

    private function decodeHexString(string $value): string
    {
        if (strlen($value) % 2 === 1) {
            $value .= '0';
        }

        $decoded = '';
        for ($i = 0, $length = strlen($value); $i < $length; $i += 2) {
            $decoded .= chr(hexdec(substr($value, $i, 2)));
        }

        return $this->normalizeEncoding($decoded);
    }

    private function normalizeEncoding(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $encoding = null;

        $bom = substr($value, 0, 2);
        if ($bom === "\xFE\xFF") {
            $encoding = 'UTF-16BE';
            $value = substr($value, 2);
        } elseif ($bom === "\xFF\xFE") {
            $encoding = 'UTF-16LE';
            $value = substr($value, 2);
        }

        if ($encoding === null && strpos($value, "\x00") !== false) {
            $evenNulls = 0;
            $oddNulls = 0;
            $length = strlen($value);

            for ($i = 0; $i < $length; $i += 2) {
                if ($value[$i] === "\x00") {
                    ++$evenNulls;
                }

                if ($i + 1 < $length && $value[$i + 1] === "\x00") {
                    ++$oddNulls;
                }
            }

            if ($evenNulls > $oddNulls) {
                $encoding = 'UTF-16BE';
            } elseif ($oddNulls > $evenNulls) {
                $encoding = 'UTF-16LE';
            }
        }

        if ($encoding === null && function_exists('mb_detect_encoding')) {
            $detected = mb_detect_encoding(
                $value,
                ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1252', 'ISO-8859-1'],
                true
            );

            if (is_string($detected)) {
                $encoding = $detected;
            }
        }

        if ($encoding === null) {
            $encoding = 'ISO-8859-1';
        }

        if ($encoding === 'UTF-8') {
            if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
                $encoding = 'ISO-8859-1';
            } else {
                return $value;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', $encoding);
        }

        $converted = @iconv($encoding, 'UTF-8//IGNORE', $value);

        return $converted === false ? $value : $converted;
    }
}
