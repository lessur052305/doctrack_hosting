<?php

namespace App\Services;

/**
 * ValidationService
 * ------------------
 * Implements "Automated Document Validation" (Scope 1.4): checks required
 * sections, mandatory fields, and formatting standards before a document
 * proceeds to the approval workflow (DFD Process 3.0 sub-process 3.3/3.4).
 *
 * Templates below are configurable per document category, corresponding to
 * the "Standardized Digital Document Submission" templates named in Scope
 * (Job Order, Purchase Requisition, Service Report).
 */
class ValidationService
{
    /** Required section keywords each document category must contain. */
    private const TEMPLATES = [
        'Job Order' => [
            'required_sections' => ['job order no', 'date requested', 'requested by', 'description of work'],
            'min_word_count' => 30,
        ],
        'Purchase Requisition' => [
            'required_sections' => ['requisition no', 'department', 'item description', 'quantity', 'budget'],
            'min_word_count' => 20,
        ],
        'Service Report' => [
            'required_sections' => ['service report no', 'technician', 'date of service', 'findings'],
            'min_word_count' => 25,
        ],
    ];

    /**
     * @return array{is_valid: bool, errors: array<int,string>}
     */
    public function validate(string $category, string $text): array
    {
        $errors = [];
        $template = self::TEMPLATES[$category] ?? null;

        if (!$template) {
            return ['is_valid' => false, 'errors' => ["Unrecognized document category: {$category}"]];
        }

        $normalized = strtolower($text);

        foreach ($template['required_sections'] as $section) {
            if (!str_contains($normalized, $section)) {
                $errors[] = "Missing required section/field: \"" . ucwords($section) . "\"";
            }
        }

        $wordCount = str_word_count($text);
        if ($wordCount < $template['min_word_count']) {
            $errors[] = "Document content is too short ({$wordCount} words; minimum {$template['min_word_count']}). Possible incomplete submission.";
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public static function knownCategories(): array
    {
        return array_keys(self::TEMPLATES);
    }
}
