<?php

require 'vendor/autoload.php';

use AiSystem\Services\PatternLibrary;

try {
    echo "Creating PatternLibrary...\n";
    $lib = new PatternLibrary([]);
    echo "Success! PatternLibrary created.\n";

    echo "Testing with patterns...\n";
    $lib2 = new PatternLibrary([
        'test' => [
            'description' => 'Test pattern',
            'parameters' => ['param1' => 'Param 1'],
            'semantic_template' => 'Test {param1}',
        ],
    ]);
    echo "Success! PatternLibrary with patterns created.\n";

    $pattern = $lib2->getPattern('test');
    echo "Pattern retrieved: " . print_r($pattern, true) . "\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
