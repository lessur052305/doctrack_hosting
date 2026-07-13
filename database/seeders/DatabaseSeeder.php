<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'username' => 'admin',
            'full_name' => 'System Administrator',
            'email' => 'admin@ujfcorp.test',
            'role' => 'admin',
            'password_hash' => Hash::make('admin123'),
            'is_active' => true,
        ]);

        User::create([
            'username' => 'jsantos',
            'full_name' => 'Juan Santos',
            'email' => 'jsantos@ujfcorp.test',
            'role' => 'originator',
            'password_hash' => Hash::make('jsantos123'),
            'created_by' => $admin->user_id,
            'is_active' => true,
        ]);

        User::create([
            'username' => 'mreyes',
            'full_name' => 'Maria Reyes',
            'email' => 'mreyes@ujfcorp.test',
            'role' => 'approver',
            'assigned_category' => 'Job Order',
            'password_hash' => Hash::make('mreyes123'),
            'created_by' => $admin->user_id,
            'is_active' => true,
        ]);

        // Default workflow pipelines per document category (Scope 1.4)
        $pipelines = [
            'Job Order' => ['Technical Review', 'Budget Check', 'Final Approval'],
            'Purchase Requisition' => ['Budget Check', 'Procurement Review', 'Final Approval'],
            'Service Report' => ['Quality Inspection', 'Final Approval'],
        ];

        foreach ($pipelines as $category => $stages) {
            foreach ($stages as $i => $name) {
                WorkflowStage::create([
                    'document_category' => $category,
                    'stage_name' => $name,
                    'sequence_order' => $i + 1,
                    'description' => "{$name} for {$category} documents.",
                ]);
            }
        }
    }
}