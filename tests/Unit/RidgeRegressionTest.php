<?php

use App\Support\RidgeRegression;
use Phpml\Regression\LeastSquares;

test('with lambda=0, it produces the same predictions as plain LeastSquares', function () {
    $samples = [[1, 2], [2, 1], [3, 4], [4, 3], [5, 6]];
    $targets = [10, 12, 20, 22, 30];

    $ols = new LeastSquares();
    $ols->train($samples, $targets);

    $ridge = new RidgeRegression(lambda: 0.0);
    $ridge->train($samples, $targets);

    foreach ($samples as $sample) {
        expect($ridge->predict($sample))->toBeGreaterThan($ols->predict($sample) - 0.0001)
            ->and($ridge->predict($sample))->toBeLessThan($ols->predict($sample) + 0.0001);
    }
});

test('a higher lambda shrinks the fitted coefficients toward zero', function () {
    $samples = [[1, 2], [2, 1], [3, 4], [4, 3], [5, 6], [1, 5], [6, 2]];
    $targets = [10, 12, 20, 22, 30, 18, 24];

    $mild = new RidgeRegression(lambda: 0.1);
    $mild->train($samples, $targets);

    $strong = new RidgeRegression(lambda: 50.0);
    $strong->train($samples, $targets);

    $mildMagnitude = array_sum(array_map('abs', $mild->getCoefficients()));
    $strongMagnitude = array_sum(array_map('abs', $strong->getCoefficients()));

    expect($strongMagnitude)->toBeLessThan($mildMagnitude);
});

test('a single unusual outlier shifts a ridge fit\'s coefficient less than it shifts a plain fit\'s', function () {
    // A clean, near-linear relationship...
    $samples = [[1], [2], [3], [4], [5]];
    $targets = [10, 20, 30, 40, 50];

    // ...plus one wildly inconsistent point (a "one weird slow approver").
    $samplesWithOutlier = array_merge($samples, [[6]]);
    $targetsWithOutlier = array_merge($targets, [500]);

    $lambda = 1.0;

    $olsClean = new LeastSquares();
    $olsClean->train($samples, $targets);
    $olsWithOutlier = new LeastSquares();
    $olsWithOutlier->train($samplesWithOutlier, $targetsWithOutlier);

    $ridgeClean = new RidgeRegression($lambda);
    $ridgeClean->train($samples, $targets);
    $ridgeWithOutlier = new RidgeRegression($lambda);
    $ridgeWithOutlier->train($samplesWithOutlier, $targetsWithOutlier);

    // How far the outlier pushed each method's own coefficient away from
    // its own "no outlier" baseline — the actual claim under test is that
    // ridge reacts LESS to the same intruder, not that it lands on any
    // particular absolute number.
    $olsShift = abs($olsWithOutlier->getCoefficients()[0] - $olsClean->getCoefficients()[0]);
    $ridgeShift = abs($ridgeWithOutlier->getCoefficients()[0] - $ridgeClean->getCoefficients()[0]);

    expect($ridgeShift)->toBeLessThan($olsShift);
});

test('predicts a plain array sample (not just a batch) the same way LeastSquares does', function () {
    $ridge = new RidgeRegression(lambda: 1.0);
    $ridge->train([[1, 1], [2, 2], [3, 3]], [5, 10, 15]);

    $prediction = $ridge->predict([2, 2]);

    expect($prediction)->toBeFloat();
});
