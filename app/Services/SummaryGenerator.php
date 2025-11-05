<?php

namespace App\Services;

class SummaryGenerator
{
    public function generate(string $text): string
    {
        $cleanText = trim(preg_replace('/\s+/', ' ', $text));

        if ($cleanText === '') {
            return 'Summary generation requires document text.';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $cleanText, -1, PREG_SPLIT_NO_EMPTY);

        if (!$sentences) {
            return mb_substr($cleanText, 0, 280) . '...';
        }

        $summary = implode(' ', array_slice($sentences, 0, min(3, count($sentences))));

        return mb_strimwidth($summary, 0, 480, '...');
    }
}
