<?php

use App\Services\ClassificationService;

test('identical text scores maximum similarity', function () {
    $text = 'Job Order No: JO-2026-0001. Date Requested: July 1, 2026. Requested By: Test Requester. Description of Work: repair the aircon unit.';

    $similarity = app(ClassificationService::class)->wordOverlapSimilarity($text, $text);

    expect($similarity)->toBe(1.0);
});

test('completely unrelated text scores low similarity', function () {
    $textA = 'Job Order No: JO-2026-0001. Requested By: Juan Santos. Description of Work: repair the aircon unit in the conference room.';
    $textB = 'Purchase Requisition No: PR-2026-9999. Department: Finance. Item Description: laptop units. Quantity: five. Budget: two hundred thousand pesos.';

    $similarity = app(ClassificationService::class)->wordOverlapSimilarity($textA, $textB);

    expect($similarity)->toBeLessThan(0.3);
});

test('a near-copy with only names and dates changed scores high similarity', function () {
    // Realistic length (a full paragraph, not one short sentence) matters
    // here — a couple of differing words (name, date) barely move the
    // ratio once there's enough identical surrounding text, same as a real
    // near-duplicate training sample would look like in practice.
    $textA = 'Job Order No: JO-2026-0001. Date Requested: July 1, 2026. Requested By: Juan Santos, IT Department. Description of Work: repair the leaking split-type air conditioning unit in the third floor conference room, which has been producing a rattling noise during operation and needs to be addressed before the next scheduled meeting in that room.';
    $textB = 'Job Order No: JO-2026-0002. Date Requested: July 2, 2026. Requested By: Maria Reyes, IT Department. Description of Work: repair the leaking split-type air conditioning unit in the third floor conference room, which has been producing a rattling noise during operation and needs to be addressed before the next scheduled meeting in that room.';

    $similarity = app(ClassificationService::class)->wordOverlapSimilarity($textA, $textB);

    expect($similarity)->toBeGreaterThanOrEqual(0.85);
});

test('two genuinely different documents in the same category stay below the near-duplicate threshold', function () {
    $textA = 'Job Order No: JO-2026-0001. Date Requested: July 1, 2026. Requested By: Juan Santos, IT Department. Description of Work: replacement of a cracked window pane in the reception area facing the parking lot.';
    $textB = 'Company Job Order Form. This Job Order No. JO-2026-0002 is submitted for approval. Requested by: Maria Reyes, on behalf of Finance Department. Date requested: July 5, 2026. Description of work: preventive maintenance of the backup generator ahead of the scheduled monthly test.';

    $similarity = app(ClassificationService::class)->wordOverlapSimilarity($textA, $textB);

    expect($similarity)->toBeLessThan(0.85);
});
