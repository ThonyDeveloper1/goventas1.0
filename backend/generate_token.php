<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::find(1);
$token = $user->createToken('test_token')->plainTextToken;
echo "Token de Admin: " . $token . "\n";

$user2 = \App\Models\User::find(2);
$token2 = $user2->createToken('test_token2')->plainTextToken;
echo "Token de Vendedora 2: " . $token2 . "\n";
?>
