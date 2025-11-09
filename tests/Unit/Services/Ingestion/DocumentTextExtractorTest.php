<?php

namespace Tests\Unit\Services\Ingestion;

use App\Services\Ingestion\DocumentTextExtractor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTextExtractorTest extends TestCase
{
    public function testItExtractsVisibleTextFromCompressedPdf(): void
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            $this->markTestSkipped('smalot/pdfparser dependency is not available.');
        }

        $encodedPdf = <<<'PDF'
JVBERi0xLjQKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2Jq
CjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9LaWRzIFszIDAgUl0gL0NvdW50IDEgPj4KZW5kb2Jq
CjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAyMDAg
MjAwXSAvQ29udGVudHMgNCAwIFIgL1Jlc291cmNlcyA8PCAvRm9udCA8PCAvRjEgNSAwIFIgPj4g
Pj4gPj4KZW5kb2JqCjQgMCBvYmoKPDwgL0xlbmd0aCA2MCAvRmlsdGVyIC9GbGF0ZURlY29kZSA+
PgpzdHJlYW0KeJxzCuHSdzNUMDJQCEnjMjdSMDcAslK4NDxSc3LyFZzzcwuKUouLU1MUwvOLclIU
NRVCsrhcQwB2Tg7zCmVuZHN0cmVhbQplbmRvYmoKNSAwIG9iago8PCAvVHlwZSAvRm9udCAvU3Vi
dHlwZSAvVHlwZTEgL0Jhc2VGb250IC9IZWx2ZXRpY2EgPj4KZW5kb2JqCnhyZWYKMCA2CjAwMDAw
MDAwMDAgNjU1MzUgZiAKMDAwMDAwMDAwOSAwMDAwMCBuIAowMDAwMDAwMDU4IDAwMDAwIG4gCjAw
MDAwMDAxMTUgMDAwMDAgbiAKMDAwMDAwMDI0MSAwMDAwMCBuIAowMDAwMDAwMzcyIDAwMDAwIG4g
CnRyYWlsZXIKPDwgL1NpemUgNiAvUm9vdCAxIDAgUiA+PgpzdGFydHhyZWYKNDQyCiUlRU9G
PDF;

        $pdfContents = base64_decode($encodedPdf, true);
        $this->assertIsString($pdfContents, 'Fixture base64 encoding should decode to a string.');

        Storage::fake('ingest-docs');
        Storage::disk('ingest-docs')->put('compressed.pdf', $pdfContents);

        $extractor = $this->app->make(DocumentTextExtractor::class);

        $text = $extractor->extract('ingest-docs', 'compressed.pdf');

        $this->assertSame('Hello Compressed World!', $text);
    }
}
