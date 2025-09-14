<?php
// Create reference photo for student ID 10
$source = 'uploads/profiles/1.png';
$destination = 'uploads/profiles/10.png';

if (file_exists($source)) {
    if (copy($source, $destination)) {
        echo "Successfully created reference photo for student ID 10\n";
        echo "File size: " . filesize($destination) . " bytes\n";
    } else {
        echo "Failed to copy file\n";
    }
} else {
    echo "Source file not found: $source\n";
}

// List all profile photos
echo "\nAll profile photos:\n";
$files = scandir('uploads/profiles/');
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        echo "- $file (" . filesize("uploads/profiles/$file") . " bytes)\n";
    }
}
?>
