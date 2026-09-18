<?php

namespace App\Support;

use Phpml\Helper\Predictable;
use Phpml\Math\Matrix;
use Phpml\Regression\Regression;

/**
 * Ridge Regression — the same normal-equation math php-ml's own
 * LeastSquares uses (coefficient = (X'X)^-1 X'Y), with one addition: a
 * penalty term added before inverting, coefficient = (X'X + lambda*I)^-1
 * X'Y. That penalty is what keeps the fitted coefficients conservative
 * when there's little data to constrain them (small sample counts, a
 * single unusual outlier) — and its influence shrinks automatically as
 * more real data accumulates and the X'X term grows relative to it, with
 * no separate logic needed to "turn it off" later. See
 * ApprovalTimeMlService's own docblock for why this matters for a model
 * meant to work reasonably from a small sample count and get sharper as
 * more real decisions come in.
 *
 * php-ml itself has no Ridge implementation — only LeastSquares and SVR
 * (confirmed directly from its source) — so this is a small, self-
 * contained addition built on the exact same Matrix utility LeastSquares
 * already uses, implementing the same Regression interface so it's a
 * drop-in replacement wherever LeastSquares was used.
 */
class RidgeRegression implements Regression
{
    use Predictable;

    private array $samples = [];
    private array $targets = [];
    private float $intercept = 0.0;
    private array $coefficients = [];

    /**
     * @param  float  $lambda  Regularization strength — how hard the
     *     penalty pushes coefficients toward zero. 0.0 reduces this to
     *     exactly plain Least Squares; higher values are more cautious
     *     with small/noisy data.
     */
    public function __construct(private float $lambda = 1.0)
    {
    }

    public function train(array $samples, array $targets): void
    {
        $this->samples = array_merge($this->samples, $samples);
        $this->targets = array_merge($this->targets, $targets);

        $this->computeCoefficients();
    }

    /**
     * @return mixed
     */
    protected function predictSample(array $sample)
    {
        $result = $this->intercept;
        foreach ($this->coefficients as $index => $coefficient) {
            $result += $coefficient * $sample[$index];
        }

        return $result;
    }

    public function getCoefficients(): array
    {
        return $this->coefficients;
    }

    public function getIntercept(): float
    {
        return $this->intercept;
    }

    /**
     * coefficient = (X'X + lambda*I)^-1 X'Y — the same normal equation as
     * plain least squares, with the lambda*I penalty added before
     * inverting. The intercept's own row/column in that penalty matrix
     * is deliberately left at 0 (not penalized) — shrinking the real
     * feature coefficients toward a cautious baseline is the point;
     * shrinking the baseline itself would bias every prediction toward
     * zero regardless of the inputs, which isn't what "be cautious with
     * little data" is supposed to mean.
     */
    private function computeCoefficients(): void
    {
        $samplesMatrix = $this->getSamplesMatrix();
        $targetsMatrix = $this->getTargetsMatrix();

        $xtx = $samplesMatrix->transpose()->multiply($samplesMatrix);
        $penalty = $this->penaltyMatrix($xtx->getRows());

        $ts = $xtx->add($penalty)->inverse();
        $tf = $samplesMatrix->transpose()->multiply($targetsMatrix);

        $this->coefficients = $ts->multiply($tf)->getColumnValues(0);
        $this->intercept = array_shift($this->coefficients);
    }

    /** lambda * identity, except the [0][0] cell (the intercept term, added as column 0 below) stays 0. */
    private function penaltyMatrix(int $size): Matrix
    {
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $row = array_fill(0, $size, 0.0);
            $row[$i] = $i === 0 ? 0.0 : $this->lambda;
            $rows[] = $row;
        }

        return new Matrix($rows);
    }

    /** Add one dimension for intercept calculation — same convention as LeastSquares. */
    private function getSamplesMatrix(): Matrix
    {
        $samples = [];
        foreach ($this->samples as $sample) {
            array_unshift($sample, 1);
            $samples[] = $sample;
        }

        return new Matrix($samples);
    }

    private function getTargetsMatrix(): Matrix
    {
        if (is_array($this->targets[0])) {
            return new Matrix($this->targets);
        }

        return Matrix::fromFlatArray($this->targets);
    }
}
