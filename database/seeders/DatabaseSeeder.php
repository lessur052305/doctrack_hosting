<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Idempotent by design — updateOrCreate()/firstOrCreate() keyed on the
     * unique columns, not create(). Running `php artisan db:seed` a second
     * time (without a fresh migration first) previously threw a duplicate
     * `username` error; it should always be safe to re-run.
     *
     * Real accounts, real Gmail addresses — start UNVERIFIED (no
     * email_verified_at), same as any account an admin creates through the
     * UI, and each gets a real verification email sent below. Necessary,
     * not just convenient: on a genuinely fresh `migrate:fresh --seed`
     * (a new deploy, or wiping this dev database), NOBODY can log in until
     * verified, and there's no admin session yet to click "Resend
     * verification" for anyone — including themselves. Without sending
     * here, a fresh install would have no way in at all. See
     * AuthController::login()/verifyEmail() and User::
     * sendEmailVerificationNotification().
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['username' => 'rvinz'],
            [
                'full_name' => 'Russel Vinz',
                'email' => 'aganarusselvinz@gmail.com',
                'role' => 'admin',
                'assigned_category' => null,
                'password_hash' => Hash::make('rvinz123'),
                'is_active' => true,
            ]
        );

        $allenRose = User::updateOrCreate(
            ['username' => 'arose'],
            [
                'full_name' => 'Allen Rose',
                'email' => 'anastacioalena23@gmail.com',
                'role' => 'originator',
                // Explicit null: an earlier seeder version used this same
                // username for a different (approver) role — clearing this
                // avoids a stale assigned_category surviving the switch.
                'assigned_category' => null,
                'password_hash' => Hash::make('arose123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        // Both Job Order approvers, unrestricted (no specific stage picks)
        // — eligible for every stage in the category by default, so the
        // load-balanced routing has more than one eligible approver to
        // actually demonstrate.
        $lessurVinz = User::updateOrCreate(
            ['username' => 'lvinz'],
            [
                'full_name' => 'Lessur Vinz',
                'email' => 'lessurvinz@gmail.com',
                'role' => 'approver',
                'assigned_category' => 'Job Order',
                'password_hash' => Hash::make('lvinz123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        $christian = User::updateOrCreate(
            ['username' => 'cperalta'],
            [
                'full_name' => 'Christian',
                'email' => 'peraltachristian.m@gmail.com',
                'role' => 'approver',
                'assigned_category' => 'Job Order',
                'password_hash' => Hash::make('cperalta123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        // Skips anyone already verified — a re-run of this idempotent
        // seeder (e.g. `db:seed` again without `migrate:fresh` first)
        // shouldn't re-send a link to an account that already clicked it.
        foreach ([$admin, $allenRose, $lessurVinz, $christian] as $seededUser) {
            if (!$seededUser->hasVerifiedEmail()) {
                $seededUser->sendEmailVerificationNotification();
            }
        }

        // Default workflow pipelines per document category (Scope 1.4)
        $pipelines = [
            'Job Order' => ['Technical Review', 'Budget Check', 'Final Approval'],
            'Purchase Requisition' => ['Budget Check', 'Procurement Review', 'Final Approval'],
            'Service Report' => ['Quality Inspection', 'Final Approval'],
        ];

        foreach ($pipelines as $category => $stages) {
            foreach ($stages as $i => $name) {
                WorkflowStage::firstOrCreate(
                    ['document_category' => $category, 'sequence_order' => $i + 1],
                    ['stage_name' => $name, 'description' => "{$name} for {$category} documents."]
                );
            }
        }
    }
}
