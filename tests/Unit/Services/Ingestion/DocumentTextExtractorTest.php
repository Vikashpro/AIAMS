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

    public function testItExtractsUtf16HexEncodedTextFromPdf(): void
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            $this->markTestSkipped('smalot/pdfparser dependency is not available.');
        }

        $encodedPdf = <<<'PDF'
JVBERi0xLjQKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2JqCjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9Db3VudCAxIC9LaWRzIFszIDAgUl0gPj4KZW5kb2JqCjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAyMDAgMjAwXSAvQ29udGVudHMgNCAwIFIgL1Jlc291cmNlcyA8PCAvRm9udCA8PCAvRjEgNSAwIFIgPj4gPj4gPj4KZW5kb2JqCjQgMCBvYmoKPDwgL0xlbmd0aCA4MCA+PgpzdHJlYW0KQlQKL0YxIDI0IFRmCjcyIDEyMCBUZAo8RkVGRjAwNDgwMDY1MDA2QzAwNkMwMDZGMDAyMDAwNTUwMDU0MDA0NjAwMzEwMDM2PiBUagpFVAplbmRzdHJlYW0KZW5kb2JqCjUgMCBvYmoKPDwgL1R5cGUgL0ZvbnQgL1N1YnR5cGUgL1R5cGUxIC9CYXNlRm9udCAvSGVsdmV0aWNhID4+CmVuZG9iagp4cmVmCjAgNgowMDAwMDAwMDAwIDY1NTM1IGYgCjAwMDAwMDAwMDkgMDAwMDAgbiAKMDAwMDAwMDA1OCAwMDAwMCBuIAowMDAwMDAwMTE1IDAwMDAwIG4gCjAwMDAwMDAyNDEgMDAwMDAgbiAKMDAwMDAwMDM3MCAwMDAwMCBuIAp0cmFpbGVyCjw8IC9TaXplIDYgL1Jvb3QgMSAwIFIgPj4Kc3RhcnR4cmVmCjQ0MAolJUVPRg==
PDF;

        $pdfContents = base64_decode($encodedPdf, true);
        $this->assertIsString($pdfContents, 'Fixture base64 encoding should decode to a string.');

        Storage::fake('ingest-docs');
        Storage::disk('ingest-docs')->put('utf16-hex.pdf', $pdfContents);

        $extractor = $this->app->make(DocumentTextExtractor::class);

        $text = $extractor->extract('ingest-docs', 'utf16-hex.pdf');

        $this->assertSame('Hello UTF16', $text);
        $this->assertSame('UTF-8', mb_detect_encoding($text, 'UTF-8', true), 'Extracted text should be valid UTF-8.');
    }

    public function testItIgnoresNoiseOutsideTextOperators(): void
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            $this->markTestSkipped('smalot/pdfparser dependency is not available.');
        }

        $encodedPdf = <<<'PDF'
JVBERi0xLjQKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2Jq
CjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9Db3VudCAxIC9LaWRzIFszIDAgUl0gPj4KZW5kb2Jq
CjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAzMDAg
MDBdIC9Db250ZW50cyBbNCAwIFIgNSAwIFJdIC9SZXNvdXJjZXMgPDwgL0ZvbnQgPDwgL0YxIDYg
MCBSID4+ID4+ID4+CmVuZG9iago0IDAgb2JqCjw8IC9MZW5ndGggNzAgPj4Kc3RyZWFtCkJUCi9G
MSAyNCBUZgoyOCA3MjAgVGQKKFByaW1hcnkgVGV4dCkgVGoKRVQKZW5kc3RyZWFtCmVuZG9iago1
IDAgb2JqCjw8IC9MZW5ndGggNDAgPj4Kc3RyZWFtCihyYW5kb20gbm9pc2UpICUgKG5vIHRleHQg
b3BlcmF0b3IpCihCYWQgVG9rZW4pCmVuZHN0cmVhbQplbmRvYmoKNiAwIG9iago8PCAvVHlwZSAv
Rm9udCAvU3VidHlwZSAvVHlwZTEgL0Jhc2VGb250IC9IZWx2ZXRpY2EgPj4KZW5kb2JqCiUlRU9G
PDF;

        $pdfContents = base64_decode($encodedPdf, true);
        $this->assertIsString($pdfContents, 'Fixture base64 encoding should decode to a string.');

        Storage::fake('ingest-docs');
        Storage::disk('ingest-docs')->put('noisy.pdf', $pdfContents);

        $extractor = $this->app->make(DocumentTextExtractor::class);

        $text = $extractor->extract('ingest-docs', 'noisy.pdf');

        $this->assertSame('Primary Text', $text);
        $this->assertSame('UTF-8', mb_detect_encoding($text, 'UTF-8', true), 'Extracted text should be valid UTF-8.');
    }
}
