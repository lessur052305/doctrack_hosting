<?php

use App\Services\ValidationService;

test('a document with every required section and enough words passes validation', function () {
    $text = "JOB ORDER\nJob Order No: JO-2026-0001\nDate Requested: July 16, 2026\n"
        . "Requested By: Test Requester\nDescription of Work:\n"
        . "Perform scheduled servicing on the company delivery truck including an oil change, "
        . "brake inspection, tire rotation, and a full fluid level check before the next route.";

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeTrue()
        ->and($result['errors'])->toBe([]);
});

test('a document missing a required section fails validation with a specific error', function () {
    $text = "JOB ORDER\nDate Requested: July 16, 2026\nRequested By: Test Requester\n"
        . "Description of Work: some description that is long enough to pass the word count on its own here.";

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeFalse()
        ->and($result['errors'])->toContain('Missing required section/field: "Job Order No"');
});

test('a document under the minimum word count fails validation', function () {
    $text = "JOB ORDER\nJob Order No: JO-1\nDate Requested: July 16, 2026\n"
        . "Requested By: X\nDescription of Work: too short.";

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeFalse();
});
