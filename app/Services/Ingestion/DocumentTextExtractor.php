<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentTextExtractor
{
    public function extract(string $disk, string $path): ?string
    {
        $storage = Storage::disk($disk);

        if (!$storage->exists($path)) {
            return null;
        }

        $mime = (string) $storage->mimeType($path);

        if (Str::startsWith($mime, 'text/')) {
            return $this->normalize($storage->get($path));
        }

        if ($mime === 'application/pdf' || Str::endsWith(Str::lower($path), '.pdf')) {
            $contents = $storage->get($path);

            return $this->extractFromPdf($contents);
        }

        return null;
    }

    protected function extractFromPdf(string $contents): ?string
    {
        if ($contents === '') {
            return null;
        }

        // Strip out binary streams to improve the odds of capturing text tokens.
        $contents = preg_replace('/stream.*?endstream/s', ' ', $contents) ?? $contents;

        preg_match_all('/\((?:[^()\\]|\\.)*\)/s', $contents, $matches);

        if (empty($matches[0])) {
            return null;
        }

        $segments = array_map([$this, 'decodePdfString'], $matches[0]);
        $text = implode(' ', array_filter($segments));

        return $this->normalize($text);
    }

    protected function decodePdfString(string $value): string
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

        return strtr($value, $replacements);
    }

    protected function normalize(?string $text): ?string
    {
        if (!is_string($text)) {
            return null;
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return $text;
    }
}
