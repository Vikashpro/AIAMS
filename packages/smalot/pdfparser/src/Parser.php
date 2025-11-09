<?php

namespace Smalot\PdfParser;

class Parser
{
    public function parseContent(string $contents): Document
    {
        $segments = [];

        $streams = [];

        foreach ($this->extractStreams($contents) as $stream) {
            $stream['decoded'] = $this->decodeStream($stream['data'], $stream['filters']);
            $streams[] = $stream;
        }

        $glyphMaps = $this->buildFontGlyphMaps($contents, $streams);

        foreach ($streams as $stream) {
            if ($stream['decoded'] !== null) {
                $segments[] = $this->extractTextTokens($stream['decoded'], $glyphMaps);
            }
        }

        // Fallback to the raw document so we still capture plain tokens.
        $segments[] = $this->extractTextTokens($contents, $glyphMaps);

        $text = trim(implode("\n", array_filter($segments)));

        return new Document($text);
    }

    private function extractStreams(string $contents): array
    {
        $streams = [];
        $pattern = '/(\d+)\s+(\d+)\s+obj\s*<<(.*?)>>\s*stream\s*(.*?)\s*endstream/s';
        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $streams[] = [
                    'object' => $match[1] . ' ' . $match[2],
                    'dictionary' => $match[3],
                    'data' => $this->cleanupStreamData($match[4]),
                    'filters' => $this->parseFilters($match[3]),
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

    private function extractTextTokens(?string $contents, array $glyphMaps): string
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
            $glyphMap = $operand['font'] !== null ? ($glyphMaps[$operand['font']] ?? null) : null;

            if ($operand['type'] === 'hex') {
                $segments[] = $this->decodeHexString($operand['value'], $glyphMap);
                continue;
            }

            $segments[] = $this->decodePdfString($operand['value'], $glyphMap);
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
            $fonts = $this->collectFontsWithinBlock($block, $blockOffset);

            if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)\s*(?=\s*(?:Tj|TJ|\'|\"))/s', $block, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$token, $offset]) {
                    $operands[] = [
                        'offset' => $blockOffset + $offset,
                        'type' => 'string',
                        'value' => trim($token),
                        'font' => $this->findActiveFont($fonts, $blockOffset + $offset),
                    ];
                }
            }

            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*(?=\s*(?:Tj|TJ|\'|\"))/s', $block, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as [$token, $offset]) {
                    $operands[] = [
                        'offset' => $blockOffset + $offset,
                        'type' => 'hex',
                        'value' => $token,
                        'font' => $this->findActiveFont($fonts, $blockOffset + $offset),
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
                                'font' => $this->findActiveFont($fonts, $blockOffset + $arrayOffset + $offset),
                            ];
                        }
                    }

                    if (preg_match_all('/<([0-9A-Fa-f]+)>/', $arrayContents, $hexMatches, PREG_OFFSET_CAPTURE)) {
                        foreach ($hexMatches[1] as [$token, $offset]) {
                            $operands[] = [
                                'offset' => $blockOffset + $arrayOffset + $offset,
                                'type' => 'hex',
                                'value' => $token,
                                'font' => $this->findActiveFont($fonts, $blockOffset + $arrayOffset + $offset),
                            ];
                        }
                    }
                }
            }
        }

        return $operands;
    }

    private function decodePdfString(string $value, ?array $glyphMap = null): string
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

        if ($glyphMap !== null) {
            return $this->finalizeNormalized($this->mapGlyphString($value, $glyphMap));
        }

        return $this->normalizeEncoding($value);
    }

    private function decodeHexString(string $value, ?array $glyphMap = null): string
    {
        if (strlen($value) % 2 === 1) {
            $value .= '0';
        }

        $decoded = '';
        for ($i = 0, $length = strlen($value); $i < $length; $i += 2) {
            $decoded .= chr(hexdec(substr($value, $i, 2)));
        }

        if ($glyphMap !== null) {
            return $this->finalizeNormalized($this->mapGlyphString($decoded, $glyphMap));
        }

        return $this->normalizeEncoding($decoded);
    }

    private function buildFontGlyphMaps(string $contents, array $streams): array
    {
        $fontReferences = $this->collectFontReferences($contents);

        if ($fontReferences === []) {
            return [];
        }

        $fontToUnicode = [];

        foreach ($fontReferences as $fontName => $objectRef) {
            $dictionary = $this->findObjectDictionary($contents, $objectRef);

            if ($dictionary !== null && preg_match('/\/ToUnicode\s+(\d+\s+\d+)\s+R/', $dictionary, $match)) {
                $fontToUnicode[$fontName] = $match[1];
            }
        }

        if ($fontToUnicode === []) {
            return [];
        }

        $decodedStreams = [];

        foreach ($streams as $stream) {
            if (!isset($stream['object'], $stream['decoded'])) {
                continue;
            }

            $decodedStreams[$stream['object']] = $stream['decoded'];
        }

        $glyphMaps = [];

        foreach ($fontToUnicode as $fontName => $objectRef) {
            $cmap = $decodedStreams[$objectRef] ?? null;

            if ($cmap === null) {
                continue;
            }

            $map = $this->parseToUnicodeCMap($cmap);

            if ($map !== null) {
                $glyphMaps[$fontName] = $map;
            }
        }

        return $glyphMaps;
    }

    private function collectFontReferences(string $contents): array
    {
        $references = [];

        if (!preg_match_all('/\/Font\s*<<([^>]*)>>/s', $contents, $matches)) {
            return $references;
        }

        foreach ($matches[1] as $block) {
            if (preg_match_all('/\/([A-Za-z0-9_.+\-]+)\s+(\d+)\s+(\d+)\s+R/', $block, $fontMatches, PREG_SET_ORDER)) {
                foreach ($fontMatches as $fontMatch) {
                    $references[$fontMatch[1]] = $fontMatch[2] . ' ' . $fontMatch[3];
                }
            }
        }

        return $references;
    }

    private function findObjectDictionary(string $contents, string $objectRef): ?string
    {
        $pattern = sprintf('/%s\s+obj\s*<<(.*?)>>\s*endobj/s', preg_quote($objectRef, '/'));

        if (!preg_match($pattern, $contents, $match)) {
            return null;
        }

        return $match[1];
    }

    private function parseToUnicodeCMap(string $cmap): ?array
    {
        $map = [];
        $maxLength = 0;

        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $sections)) {
            foreach ($sections[1] as $section) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>/', $section, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $pair) {
                        $glyph = $this->hexToBinary($pair[1]);
                        $unicode = $this->unicodeFromHex($pair[2]);

                        if ($glyph === null || $unicode === null) {
                            continue;
                        }

                        $map[$glyph] = $unicode;
                        $maxLength = max($maxLength, strlen($glyph));
                    }
                }
            }
        }

        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $sections)) {
            foreach ($sections[1] as $section) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>\s+(<([0-9A-Fa-f]+)>|\[(.*?)\])/', $section, $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $range) {
                        $startGlyph = $this->hexToBinary($range[1]);
                        $endGlyph = $this->hexToBinary($range[2]);

                        if ($startGlyph === null || $endGlyph === null) {
                            continue;
                        }

                        $startCode = $this->binaryToInt($startGlyph);
                        $endCode = $this->binaryToInt($endGlyph);

                        if ($startCode === null || $endCode === null) {
                            continue;
                        }

                        $length = strlen($startGlyph);
                        $maxLength = max($maxLength, $length);

                        if ($range[3][0] === '<') {
                            $unicodeStart = $this->unicodeFromHex($range[4]);

                            if ($unicodeStart === null) {
                                continue;
                            }

                            $unicodeCodePoint = $this->unicodeToInt($unicodeStart);

                            if ($unicodeCodePoint === null) {
                                continue;
                            }

                            for ($code = $startCode, $offset = 0; $code <= $endCode; ++$code, ++$offset) {
                                $glyph = $this->intToBinary($code, $length);
                                $unicode = $this->codePointToUtf8($unicodeCodePoint + $offset);

                                if ($glyph === null || $unicode === null) {
                                    continue;
                                }

                                $map[$glyph] = $unicode;
                            }
                        } else {
                            if (preg_match_all('/<([0-9A-Fa-f]+)>/', $range[5], $unicodeMatches)) {
                                $codes = $unicodeMatches[1];
                                $expected = ($endCode - $startCode) + 1;

                                if (count($codes) === $expected) {
                                    foreach ($codes as $index => $codeHex) {
                                        $glyph = $this->intToBinary($startCode + $index, $length);
                                        $unicode = $this->unicodeFromHex($codeHex);

                                        if ($glyph === null || $unicode === null) {
                                            continue;
                                        }

                                        $map[$glyph] = $unicode;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($map === []) {
            return null;
        }

        if ($maxLength < 1) {
            $maxLength = 1;
        }

        return [
            'map' => $map,
            'maxLength' => $maxLength,
        ];
    }

    private function collectFontsWithinBlock(string $block, int $blockOffset): array
    {
        $fonts = [];

        if (preg_match_all('/\/([A-Za-z0-9_.+\-]+)\s+[-+0-9.]+\s+Tf/', $block, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => [$fontName, $offset]) {
                $fonts[] = [
                    'name' => $fontName,
                    'offset' => $blockOffset + ($matches[0][$index][1] ?? $offset),
                ];
            }
        }

        return $fonts;
    }

    private function findActiveFont(array $fonts, int $operandOffset): ?string
    {
        $active = null;

        foreach ($fonts as $font) {
            if ($font['offset'] <= $operandOffset) {
                if ($active === null || $font['offset'] > $active['offset']) {
                    $active = $font;
                }
            }
        }

        return $active['name'] ?? null;
    }

    private function mapGlyphString(string $value, array $glyphMap): string
    {
        $map = $glyphMap['map'] ?? [];
        $maxLength = $glyphMap['maxLength'] ?? 1;

        if ($map === []) {
            return '';
        }

        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length;) {
            $matched = false;
            $chunkSize = min($maxLength, $length - $i);

            for (; $chunkSize > 0; --$chunkSize) {
                $segment = substr($value, $i, $chunkSize);

                if (isset($map[$segment])) {
                    $result .= $map[$segment];
                    $i += $chunkSize;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                ++$i;
            }
        }

        return $result;
    }

    private function hexToBinary(string $hex): ?string
    {
        $hex = preg_replace('/\s+/', '', $hex);

        if ($hex === '' || (strlen($hex) % 2) === 1) {
            return null;
        }

        return hex2bin($hex) ?: null;
    }

    private function unicodeFromHex(string $hex): ?string
    {
        $binary = $this->hexToBinary($hex);

        if ($binary === null) {
            return null;
        }

        $converted = @mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');

        return is_string($converted) ? $converted : null;
    }

    private function binaryToInt(string $binary): ?int
    {
        $length = strlen($binary);

        if ($length === 0 || $length > 4) {
            return null;
        }

        $value = 0;

        for ($i = 0; $i < $length; ++$i) {
            $value = ($value << 8) | ord($binary[$i]);
        }

        return $value;
    }

    private function intToBinary(int $value, int $length): ?string
    {
        if ($length < 1 || $length > 4) {
            return null;
        }

        $bytes = '';

        for ($i = $length - 1; $i >= 0; --$i) {
            $shift = $i * 8;
            $bytes .= chr(($value >> $shift) & 0xFF);
        }

        return $bytes;
    }

    private function unicodeToInt(string $unicode): ?int
    {
        if ($unicode === '') {
            return null;
        }

        if (!function_exists('mb_ord')) {
            $binary = @mb_convert_encoding($unicode, 'UTF-16BE', 'UTF-8');

            if (!is_string($binary) || strlen($binary) === 0 || strlen($binary) > 4) {
                return null;
            }

            return $this->binaryToInt($binary);
        }

        $codePoint = @mb_ord($unicode, 'UTF-8');

        return is_int($codePoint) ? $codePoint : null;
    }

    private function codePointToUtf8(int $codePoint): ?string
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF) {
            return null;
        }

        if ($codePoint <= 0x7F) {
            return chr($codePoint);
        }

        if ($codePoint <= 0x7FF) {
            return chr(0xC0 | ($codePoint >> 6)) . chr(0x80 | ($codePoint & 0x3F));
        }

        if ($codePoint <= 0xFFFF) {
            return chr(0xE0 | ($codePoint >> 12))
                . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        return chr(0xF0 | ($codePoint >> 18))
            . chr(0x80 | (($codePoint >> 12) & 0x3F))
            . chr(0x80 | (($codePoint >> 6) & 0x3F))
            . chr(0x80 | ($codePoint & 0x3F));
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
