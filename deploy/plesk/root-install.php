<?php

// Opens the TPV installer (public/install.php), which deletes this file too
// when the installation finishes.
if (!is_file(__DIR__ . '/public/install.php')) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/public/install.php';
