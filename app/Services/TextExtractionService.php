<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * TextExtractionService
 * -----------------------
 * Implements the "Hybrid Extraction Pipeline" from Scope (1.4):
 *   1. Digital Extraction — assume the document is searchable (born-digital)
 *      and pull text directly:
 *        - .txt          -> read as plain text
 *        - .docx         -> unzip and read word/document.xml (pure PHP,
 *                           via the built-in ZipArchive extension — no
 *                           external Composer package required)
 *        - .pdf          -> via smalot/pdfparser, if installed
 *   2. OCR fallback — if step 1 yields no usable text (e.g. a scanned
 *      image-only PDF, a plain image file, or a legacy .doc), fall back to
 *      an OCR engine (tesseract via `thiagoalessio/tesseract_ocr`, if
 *      installed) so the ML classifier always receives usable text
 *      regardless of format.
 *
 * Composer packages required for full-fidelity PDF/OCR support (optional —
 * .txt and .docx work with zero extra dependencies):
 *   smalot/pdfparser, thiagoalessio/tesseract_ocr (+ system tesseract-ocr)
 */
class TextExtractionService
{
    private const MIN_USABLE_CHARS = 40;

    public function extract(UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        $text = '';
        $usedOcr = false;

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            $text = $this->extractFromPdf($file->getRealPath());
        } elseif ($extension === 'docx') {
            $text = $this->extractFromDocx($file->getRealPath());
        } elseif (str_starts_with($mime, 'text/') || $extension === 'txt') {
            $text = file_get_contents($file->getRealPath());
        }
        // .doc (legacy binary Word) and image types intentionally fall
        // through to the OCR attempt below — there is no reliable
        // dependency-free way to read either format directly.

        if (mb_strlen(trim($text)) < self::MIN_USABLE_CHARS) {
            $text = $this->extractWithOcr($file->getRealPath());
            $usedOcr = true;
        }

        return [
            'text' => trim($text),
            'used_ocr_fallback' => $usedOcr,
        ];
    }

    private function extractFromPdf(string $path): string
    {
        try {
            if (!class_exists(PdfParser::class)) {
                return ''; // package not installed in this environment; triggers OCR fallback
            }
            $parser = new PdfParser();
            $pdf = $parser->parseFile($path);
            return $pdf->getText();
        } catch (\Throwable $e) {
            report($e);
            return '';
        }
    }

    /**
     * .docx files are a zip archive containing XML. word/document.xml holds
     * the visible body text. This needs no external library — PHP's
     * bundled ZipArchive extension is enough.
     */
    private function extractFromDocx(string $path): string
    {
        try {
            if (!class_exists(\ZipArchive::class)) {
                return ''; // php-zip extension not enabled; triggers OCR fallback
            }

            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return '';
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false) {
                return '';
            }

            // Word inserts paragraph/break tags that should become spaces so
            // words don't get glued together once tags are stripped.
            $xml = preg_replace('/<\/w:p>|<w:br\/?>/', ' ', $xml);
            $text = strip_tags($xml);

            return html_entity_decode($text, ENT_QUOTES | ENT_XML1);
        } catch (\Throwable $e) {
            report($e);
            return '';
        }
    }

    private function extractWithOcr(string $path): string
    {
        try {
            if (!class_exists(\TesseractOCR::class)) {
                return ''; // OCR package/binary not available in this environment
            }
            return (new \TesseractOCR($path))->run();
        } catch (\Throwable $e) {
            report($e);
            return '';
        }
    }
}