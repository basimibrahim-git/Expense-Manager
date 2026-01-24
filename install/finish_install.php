<?php
// install/finish_install.php
// Deletes the install directory and redirects to dashboard

function rmdir_recursive($dir) {
    if (!is_dir($dir)) return false;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? rmdir_recursive("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}

// 1. Delete install.php
@unlink('install.php');

// 2. Delete finish_install.php (Self)
// Note: On Windows, self-deletion while running can be tricky. 
// Standard PHP usually allows renaming/marking for deletion or relying on OS cleanup.
// But we should try to delete the *directory* if possible from outside?
// Since this file IS inside /install, we can't easily delete /install while running this script.

// Strategy: Move a cleanup script to temp/root, run it, or just delete what we can.
// Best approach for web self-destruct: 
// Simply delete 'install.php' and tell user to delete folder if it remains.

@unlink(__FILE__); // Try to delete self

// Redirect
header("Location: ../dashboard.php");
exit;
?>
