<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser as PdfParser;
use thiagoalessio\TesseractOCR\TesseractNotFoundException;
use thiagoalessio\TesseractOCR\TesseractOCR;

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
        $failureReason = null;

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
            $ocr = $this->extractWithOcr($file->getRealPath());
            $text = $ocr['text'];
            $usedOcr = true;
            if (mb_strlen(trim($text)) < self::MIN_USABLE_CHARS) {
                $failureReason = $ocr['failure_reason'];
            }
        }

        return [
            'text' => trim($text),
            'used_ocr_fallback' => $usedOcr,
            // Specific, user-facing-safe reason extraction produced no
            // usable text — null when extraction actually succeeded.
            // One of: 'ocr_binary_missing', 'ocr_package_missing',
            // 'ocr_error', or null (generic/unknown).
            'failure_reason' => $failureReason,
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

    /**
     * @return array{text: string, failure_reason: ?string}
     */
    private function extractWithOcr(string $path): array
    {
        if (!class_exists(TesseractOCR::class)) {
            return ['text' => '', 'failure_reason' => 'ocr_package_missing'];
        }

        try {
            $ocr = new TesseractOCR($path);
            $this->useBundledTesseractIfPresent($ocr);
            return ['text' => $ocr->run(), 'failure_reason' => null];
        } catch (TesseractNotFoundException $e) {
            // The PHP wrapper is installed, but the system `tesseract-ocr`
            // binary it shells out to isn't — distinct from "no OCR support
            // was ever installed" so the failure message can tell an admin
            // exactly what's missing rather than a generic hedge.
            report($e);
            return ['text' => '', 'failure_reason' => 'ocr_binary_missing'];
        } catch (\Throwable $e) {
            report($e);
            return ['text' => '', 'failure_reason' => 'ocr_error'];
        }
    }

    /**
     * This environment has no system-wide `tesseract-ocr` package
     * installed, and installing one via apt requires root privileges this
     * process doesn't have. A self-contained copy (binary + its
     * libtesseract/liblept shared libraries + eng.traineddata) was
     * extracted from the official .deb packages — via `apt-get download`
     * + `dpkg-deb -x`, neither of which need root — into
     * storage/tesseract-bin. Point the OCR wrapper at it if present;
     * otherwise leave it alone so a real system install (e.g. in a
     * different environment where `sudo apt-get install tesseract-ocr`
     * was run) is used via $PATH as normal.
     */
    private function useBundledTesseractIfPresent(TesseractOCR $ocr): void
    {
        // Prefer a real system install when one's on $PATH — the bundled
        // copy only carries its own libtesseract/liblept, not the ~15
        // further transitive shared libraries tesseract itself links
        // against (libarchive, libcurl, libnettle, etc.). Those happen to
        // already be present on a typical dev machine but are NOT
        // guaranteed on a minimal container image, where the bundled
        // binary can fail with "error while loading shared libraries" for
        // whichever one is missing. A real `apt-get install tesseract-ocr`
        // pulls in its complete, correct dependency chain automatically,
        // so it's strictly more reliable whenever it's available.
        if ($this->systemTesseractAvailable()) {
            return;
        }

        $binDir = storage_path('tesseract-bin');
        $executable = $binDir . '/bin/tesseract';

        if (!is_file($executable)) {
            return;
        }

        putenv('LD_LIBRARY_PATH=' . $binDir . '/lib');
        putenv('TESSDATA_PREFIX=' . $binDir . '/share/5/tessdata');
        $ocr->executable($executable);
    }

    private function systemTesseractAvailable(): bool
    {
        $path = trim((string) @shell_exec('command -v tesseract 2>/dev/null'));

        return $path !== '';
    }
}
