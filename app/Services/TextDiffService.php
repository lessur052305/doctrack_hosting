<?php

namespace App\Services;

/**
 * TextDiffService
 * ----------------
 * Word-tokenized LCS (longest-common-subsequence) diff for the Revision
 * History "before/after" comparison (Feature: Google-Docs-style history —
 * see DocumentRevision, WorkflowService::saveDocumentRevision()). Every
 * word/punctuation run and every whitespace run is its own token, kept
 * separately so both panes reconstruct their original spacing exactly;
 * the LCS walk then classifies each token unchanged/removed/added, the
 * same convention a Git or Google Docs diff uses (red strikeout-style on
 * the "before" side, green on the "after" side — left to the view to
 * render).
 */
class TextDiffService
{
    /**
     * Guards the O(n*m) DP table below from ever actually running at a
     * pathological size — a full document-length diff on two ~100,000-
     * character texts could tokenize into tens of thousands of words,
     * and the classic LCS table is quadratic in both time and memory.
     * Past this many cells, treat the whole texts as one removed/added
     * block instead of hanging the request; real originator edits (a
     * paragraph or two at a time) never come close to this.
     */
    private const MAX_DP_CELLS = 4_000_000;

    public function diff(string $before, string $after): array
    {
        $beforeTokens = $this->tokenize($before);
        $afterTokens = $this->tokenize($after);

        if ((count($beforeTokens) + 1) * (count($afterTokens) + 1) > self::MAX_DP_CELLS) {
            return [
                'before' => $beforeTokens === [] ? [] : [['text' => $before, 'type' => 'removed']],
                'after' => $afterTokens === [] ? [] : [['text' => $after, 'type' => 'added']],
            ];
        }

        return $this->lcsDiff($beforeTokens, $afterTokens);
    }

    private function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\S+|\s+/u', $text, $matches);

        return $matches[0];
    }

    private function lcsDiff(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $cols = $m + 1;

        // A flat SplFixedArray instead of a nested PHP array — a real
        // n*m grid of boxed array entries would burn far more memory
        // than the same cell count stored contiguously.
        $dp = new \SplFixedArray(($n + 1) * $cols);
        for ($j = 0; $j <= $m; $j++) {
            $dp[$n * $cols + $j] = 0;
        }
        for ($i = 0; $i <= $n; $i++) {
            $dp[$i * $cols + $m] = 0;
        }

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i * $cols + $j] = $a[$i] === $b[$j]
                    ? $dp[($i + 1) * $cols + ($j + 1)] + 1
                    : max($dp[($i + 1) * $cols + $j], $dp[$i * $cols + ($j + 1)]);
            }
        }

        $before = [];
        $after = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $before[] = ['text' => $a[$i], 'type' => 'unchanged'];
                $after[] = ['text' => $b[$j], 'type' => 'unchanged'];
                $i++;
                $j++;
            } elseif ($dp[($i + 1) * $cols + $j] >= $dp[$i * $cols + ($j + 1)]) {
                $before[] = ['text' => $a[$i], 'type' => 'removed'];
                $i++;
            } else {
                $after[] = ['text' => $b[$j], 'type' => 'added'];
                $j++;
            }
        }
        while ($i < $n) {
            $before[] = ['text' => $a[$i], 'type' => 'removed'];
            $i++;
        }
        while ($j < $m) {
            $after[] = ['text' => $b[$j], 'type' => 'added'];
            $j++;
        }

        return [
            'before' => $this->mergeAdjacent($before),
            'after' => $this->mergeAdjacent($after),
        ];
    }

    /** Collapses consecutive same-type tokens into one chunk, so the view doesn't wrap a <mark> around every single word. */
    private function mergeAdjacent(array $tokens): array
    {
        $merged = [];
        foreach ($tokens as $token) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['type'] === $token['type']) {
                $merged[$last]['text'] .= $token['text'];
            } else {
                $merged[] = $token;
            }
        }

        return $merged;
    }
}
