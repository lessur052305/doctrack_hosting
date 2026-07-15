<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Section 6: MIME-type verification (not just extension) — but only for
 * formats that sniff reliably across OS/Office versions. Legacy .doc
 * (OLE2) and .docx (zip) are excluded: their real detected MIME varies
 * enough across producers that a strict check here would false-reject
 * legitimate Word documents. Those stay covered by the mimes: rule only,
 * with WorkflowService::ingest()'s extraction_failed handling as a
 * downstream backstop for garbage content that slips through.
 */
class ReliableMimeType implements ValidationRule
{
    private const EXPECTED = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'txt' => 'text/plain',
    ];

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $expectedMime = self::EXPECTED[$extension] ?? null;

        if ($expectedMime !== null && $value->getMimeType() !== $expectedMime) {
            $fail('The :attribute file content does not match its file extension.');
        }
    }
}
