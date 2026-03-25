<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Check schema
$columns = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name='installations'");
echo "Current columns in installations table:\n";
foreach ($columns as $col) {
    echo "  - " . $col->column_name . "\n";
}
?>
