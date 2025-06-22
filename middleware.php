<?php
function ensureCleanOutput() {
    if (!ob_get_level()) {
        ob_start();
    }
    if (ob_get_length() > 0) {
        $output = ob_get_clean();
        $logMessage = date('Y-m-d H:i:s') . " - Unexpected output detected: " . $output . "\n";
        error_log($logMessage, 3, __DIR__ . '/unexpected_output.log');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unexpected server output']);
        exit;
    }
}

/**

 * @param array $data 
 */function finalizeJsonOutput($data) {
    ensureCleanOutput();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}