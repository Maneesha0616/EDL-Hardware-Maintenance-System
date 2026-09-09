<?php
$directories = ['user', 'unithead', 'storekeeper', 'technician'];
$baseDir = __DIR__;

$newFooter = '            <footer class="footer">
                <div class="container" style="padding: 0; text-align: center;">
                    <img src="../images/hardware_logo.png" alt="Logo" style="width: 50px; border-radius: 10px; margin-bottom: 1rem;">
                    <h3>Hardware Maintenance System</h3>
                    <p style="margin-top: 1rem; color: #64748b;">&copy; <?php echo date(\'Y\'); ?> Electricity Distribution Lanka (Private) Limited</p>
                    <p style="margin-top: 15px; font-size: 0.9rem; opacity: 0.5; color: #64748b;">Helping you manage hardware tools and maintenance tasks.</p>
                </div>
            </footer>';

foreach ($directories as $dirName) {
    $dir = $baseDir . '/' . $dirName;
    if (!is_dir($dir)) continue;

    $files = glob($dir . '/*.php');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        
        $pattern = '/<(div|footer)\s+class=["\']footer["\'].*?<\/\1>/is';
        
        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, ltrim($newFooter), $content);
            file_put_contents($file, $newContent);
            echo "Updated $file\n";
        } else {
            echo "No footer found in $file\n";
        }
    }
}
?>
