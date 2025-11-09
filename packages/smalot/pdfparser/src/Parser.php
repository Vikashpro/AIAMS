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

        $operands = $this->collectTextOperands($contents);

        if ($operands === []) {
            return '';
        }

        usort($operands, static function (array $left, array $right): int {
            return $left['offset'] <=> $right['offset'];
        });

        $segments = [];

        foreach ($operands as $operand) {
            if ($operand['type'] === 'hex') {
                $segments[] = $this->decodeHexString($operand['value']);
                continue;
            }

            $segments[] = $this->decodePdfString($operand['value']);
        }

        return trim(implode(' ', array_filter($segments)));
    }

    private function collectTextOperands(string $contents): array
    {
        $operands = [];

        if (!preg_match_all('/BT\s*(.*?)\s*ET/s', $contents, $textBlocks, PREG_OFFSET_CAPTURE)) {
            return $operands;
        }

        foreach ($textBlocks[1] as [$block, $blockOffset]) {
            if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)\s*(?=\s*(?:Tj|TJ|\'|\"))/s', $block, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$token, $offset]) {
                    $operands[] = [
                        'offset' => $blockOffset + $offset,
                        'type' => 'string',
                        'value' => trim($token),
                    ];
                }
            }

            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*(?=\s*(?:Tj|TJ|\'|\"))/s', $block, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as [$token, $offset]) {
                    $operands[] = [
                        'offset' => $blockOffset + $offset,
                        'type' => 'hex',
                        'value' => $token,
                    ];
                }
            }

            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $arrayMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($arrayMatches[1] as [$arrayContents, $arrayOffset]) {
                    if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $arrayContents, $stringMatches, PREG_OFFSET_CAPTURE)) {
                        foreach ($stringMatches[0] as [$token, $offset]) {
                            $operands[] = [
                                'offset' => $blockOffset + $arrayOffset + $offset,
                                'type' => 'string',
                                'value' => $token,
                            ];
                        }
                    }

                    if (preg_match_all('/<([0-9A-Fa-f]+)>/', $arrayContents, $hexMatches, PREG_OFFSET_CAPTURE)) {
                        foreach ($hexMatches[1] as [$token, $offset]) {
                            $operands[] = [
                                'offset' => $blockOffset + $arrayOffset + $offset,
                                'type' => 'hex',
                                'value' => $token,
                            ];
                        }
                    }
                }
            }
        }

        return $operands;
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

        $bom = substr($value, 0, 2);

        if ($bom === "\xFE\xFF") {
            $value = substr($value, 2);

            return $this->finalizeNormalized(mb_convert_encoding($value, 'UTF-8', 'UTF-16BE'));
        }

        if ($bom === "\xFF\xFE") {
            $value = substr($value, 2);

            return $this->finalizeNormalized(mb_convert_encoding($value, 'UTF-8', 'UTF-16LE'));
        }

        $length = strlen($value);

        if ($length >= 2) {
            $zeroCount = substr_count($value, "\x00");

            if ($zeroCount >= ($length / 4)) {
                $evenZeros = 0;
                $oddZeros = 0;

                for ($i = 0; $i < $length; $i++) {
                    if ($value[$i] === "\x00") {
                        if (($i % 2) === 0) {
                            ++$evenZeros;
                        } else {
                            ++$oddZeros;
                        }
                    }
                }

                $encoding = $evenZeros >= $oddZeros ? 'UTF-16BE' : 'UTF-16LE';

                return $this->finalizeNormalized(mb_convert_encoding($value, 'UTF-8', $encoding));
            }
        }

        $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);

        if ($encoding === false) {
            return '';
        }

        if ($encoding !== 'UTF-8') {
            $value = mb_convert_encoding($value, 'UTF-8', $encoding);
        }

        return $this->finalizeNormalized($value);
    }

    private function finalizeNormalized(string|false $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_detect_encoding($value, 'UTF-8', true) === false ? '' : $value;
    }
}
