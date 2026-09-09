<?php
$files = glob(__DIR__ . "/*.php");
foreach($files as $file) {
    if(basename($file) == "update_fonts.php") continue;
    $content = file_get_contents($file);
    $original = $content;

    // 1. Replace Outfit font URL with Poppins
    $content = preg_replace('/https:\/\/fonts\.googleapis\.com\/css2\?family=Outfit[^"]+/', 'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap', $content);

    // 2. Ensure Poppins URL is present
    if (strpos($content, 'family=Poppins') === false) {
        $poppins_link = "<link href=\"https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap\" rel=\"stylesheet\">\n    ";
        $content = str_replace('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome', $poppins_link . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome', $content);
    }

    // 3. Fix font-family in CSS to be Poppins everywhere
    $content = str_replace("'Outfit', 'Segoe UI', Arial, sans-serif", "'Poppins', 'Segoe UI', Arial, sans-serif", $content);
    $content = preg_replace("/font-family:\s*'Poppins',\s*sans-serif;/", "font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;", $content);

    if ($original !== $content) {
        file_put_contents($file, $content);
        echo "Updated " . basename($file) . "\n";
    }
}
echo "Done.\n";
