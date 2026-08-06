<?php
/**
 * CLI smoke test for sales_export.php — writes an .xlsx file to adminStaff/runtime/.
 * Usage: php adminStaff/api/_test_sales_export_cli.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['from'] = $argv[1] ?? '2020-01-01';
$_GET['to'] = $argv[2] ?? date('Y-m-d');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['user_name'] = 'CLI Test';

$outDir = dirname(__DIR__) . '/runtime';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$outFile = $outDir . '/sales_export_test.xlsx';
define('ORTUS_EXPORT_TARGET', $outFile);
require __DIR__ . '/sales_export.php';

if (!is_file($outFile) || filesize($outFile) < 100) {
    fwrite(STDERR, "Export failed or invalid XLSX output.\n");
    exit(1);
}

$fh = fopen($outFile, 'rb');
$magic = fread($fh, 2);
fclose($fh);
if ($magic !== 'PK') {
    fwrite(STDERR, "Export file is not a valid XLSX.\n");
    exit(1);
}

fwrite(STDOUT, 'Wrote ' . $outFile . ' (' . filesize($outFile) . " bytes)\n");
