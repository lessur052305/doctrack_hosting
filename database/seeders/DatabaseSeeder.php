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
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'full_name' => 'System Administrator',
                'email' => 'admin@ujfcorp.test',
                'role' => 'admin',
                'password_hash' => Hash::make('admin123'),
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['username' => 'jsantos'],
            [
                'full_name' => 'Juan Santos',
                'email' => 'jsantos@ujfcorp.test',
                'role' => 'originator',
                'password_hash' => Hash::make('jsantos123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        // All three Job Order approvers — the load-balanced routing and
        // per-stage approver assignment only has something real to
        // demonstrate when more than one eligible approver exists.
        User::updateOrCreate(
            ['username' => 'mreyes'],
            [
                'full_name' => 'Maria Reyes',
                'email' => 'mreyes@ujfcorp.test',
                'role' => 'approver',
                'assigned_category' => 'Job Order',
                'password_hash' => Hash::make('mreyes123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['username' => 'arose'],
            [
                'full_name' => 'Allen Rose',
                'email' => 'arose@ujfcorp.test',
                'role' => 'approver',
                'assigned_category' => 'Job Order',
                'password_hash' => Hash::make('arose123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['username' => 'lvinz'],
            [
                'full_name' => 'Lessur Vinz',
                'email' => 'lvinz@ujfcorp.test',
                'role' => 'approver',
                'assigned_category' => 'Job Order',
                'password_hash' => Hash::make('lvinz123'),
                'created_by' => $admin->user_id,
                'is_active' => true,
            ]
        );

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
