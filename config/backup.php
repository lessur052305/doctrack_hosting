<?php

return [
    // How many database dumps and how many file archives to retain —
    // pruned independently, oldest-first, on every `backup:run`.
    'keep' => env('BACKUP_KEEP', 14),
];
