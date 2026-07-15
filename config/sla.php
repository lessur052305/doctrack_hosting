<?php

return [
    // Section 1: minimum buffer between "now" and a submitted due_date.
    'min_due_date_buffer_minutes' => 15,

    // Tiered SLA formula constants.
    'approver_sla_fraction' => 0.25,
    'short_due_threshold_minutes' => 60,
    'fixed_short_due_sla_minutes' => 15,
    'max_approver_sla_minutes' => 360, // 6-hour cap (Tier 2)

    // Default operational window, seeded into sla_settings on first access.
    'default_working_days' => [1, 2, 3, 4, 5, 6], // Mon-Sat (Carbon: 0=Sun..6=Sat)
    'default_work_start' => '09:00',
    'default_work_end' => '17:00',
];
