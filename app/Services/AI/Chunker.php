<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

class Chunker
{
    public function chunk(string $text, int $chunkSize = 180, int $overlap = 40): array
    {
        $cleanText = trim(preg_replace('/\s+/', ' ', $text));

        if ($cleanText === '') {
            return [];
        }

        $words = preg_split('/\s+/', $cleanText, -1, PREG_SPLIT_NO_EMPTY);
        $total = count($words);

        if ($total === 0) {
            return [];
        }

        $chunks = [];
        $index = 0;

        while ($index < $total) {
            $slice = array_slice($words, $index, $chunkSize);
            $chunks[] = Str::squish(implode(' ', $slice));

            if ($overlap <= 0) {
                $index += $chunkSize;
            } else {
                $index += max(1, $chunkSize - $overlap);
            }
        }

        return array_values(array_filter($chunks));
    }
}
