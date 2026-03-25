<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::find(1);
echo "Admin Email: " . $user->email . "\n";
echo "Password hash starts with: " . substr($user->password, 0, 30) . "\n";

// Try to test password
$testPassword = "password";
if (password_verify($testPassword, $user->password)) {
    echo "✓ Password 'password' matches!\n";
} else {
    echo "✗ Password 'password' does NOT match\n";
    echo "Trying other passwords...\n";
}
?>
