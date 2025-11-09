<?php

namespace Smalot\PdfParser;

class Document
{
    public function __construct(private string $text)
    {
    }

    public function getText(?string $encoding = null): string
    {
        return $this->text;
    }
}
