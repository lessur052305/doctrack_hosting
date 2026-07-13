<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Efficient records management drives organizational productivity.');
})->purpose('Display an inspiring quote');
