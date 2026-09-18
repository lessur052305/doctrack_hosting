import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PROJECT_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * Shells out to `php artisan tinker` to talk to the real dev database —
 * these tests exercise real HTTP requests against the actual running app
 * (see playwright.config.js's own docblock for why), so there's no
 * in-process Eloquent connection or DB transaction to reuse from Node.
 * execFileSync (not execSync) so the PHP snippet is passed as a real
 * argv entry, never shell-interpolated — no quoting hazards regardless
 * of what the snippet contains.
 */
function tinker(phpCode) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', phpCode], {
        cwd: PROJECT_ROOT,
        encoding: 'utf-8',
    });
}

const TEST_CATEGORY = 'Job Order (Playwright Countdown Test)';

/**
 * One disposable originator + approver + routed document, scoped to a
 * throwaway category so this can never collide with (or get polluted
 * by) the real Job Order pipeline. Mirrors the exact fixture the old
 * Dusk version of this test used.
 */
export function createCountdownGuardFixture() {
    const output = tinker(`
        \$originator = App\\Models\\User::factory()->originator()->create(['email' => 'pw-orig-' . uniqid() . '@example.com']);
        \$originator->forceFill(['email_verified_at' => now()])->save();
        \$approver = App\\Models\\User::factory()->approver('${TEST_CATEGORY}')->create(['email' => 'pw-appr-' . uniqid() . '@example.com']);
        \$approver->forceFill(['email_verified_at' => now()])->save();
        \$stage = App\\Models\\WorkflowStage::firstOrCreate(
            ['document_category' => '${TEST_CATEGORY}', 'stage_name' => 'Only Stage'],
            ['sequence_order' => 1]
        );
        \$document = App\\Models\\DocumentRepository::create([
            'originator_id' => \$originator->user_id,
            'title' => 'pw-countdown-' . uniqid() . '.txt',
            'file_path' => 'documents/pw-countdown.txt',
            'mime_type' => 'text/plain',
            'ml_category' => '${TEST_CATEGORY}',
            'is_validated' => true,
            'due_date' => now()->addDay(),
            'global_status' => 'classified_validated',
        ]);
        app(App\\Services\\WorkflowService::class)->routeToWorkflow(\$document);
        echo json_encode(['approverEmail' => \$approver->email]);
    `);

    const jsonStart = output.indexOf('{');
    if (jsonStart === -1) {
        throw new Error(`createCountdownGuardFixture: tinker produced no JSON output:\n${output}`);
    }
    return JSON.parse(output.slice(jsonStart));
}

/** Deletes everything scoped to the disposable test category/accounts above — safe to call even if setup partially failed. */
export function cleanupCountdownGuardFixture() {
    tinker(`
        \$docIds = App\\Models\\DocumentRepository::where('ml_category', '${TEST_CATEGORY}')->pluck('document_id');
        App\\Models\\DocumentAssignment::whereIn('document_id', \$docIds)->delete();
        App\\Models\\DocumentRepository::whereIn('document_id', \$docIds)->delete();
        App\\Models\\WorkflowStage::where('document_category', '${TEST_CATEGORY}')->delete();
        App\\Models\\User::where('email', 'like', 'pw-%@example.com')->delete();
        echo 'cleaned';
    `);
}
