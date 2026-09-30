<?php
/** Explicit CLI entry point for the single DEC-PLUS-MIG-01 backfill. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (($argv[1] ?? '') !== '--execute-dec-plus-mig-01') {
    fwrite(STDERR, "ABORT: explicit --execute-dec-plus-mig-01 confirmation is required.\n");
    exit(2);
}

$root = dirname(__DIR__, 4);
require_once $root . '/wp-load.php';

$required = [
    'PGE_Catalog_Activation_Repository',
    'PGE_Catalog_Provider_Origin_Repository',
    'PGE_Catalog_Order_Revocation_Repository',
    'PGE_Catalog_Order_Revocation_Service',
    'PGE_Catalog_Event_Binding_Repository',
    'PGE_Catalog_Event_Binding_Service',
];
foreach ($required as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "ABORT: required plugin bootstrap is unavailable.\n");
        exit(3);
    }
}

require_once dirname(__DIR__) . '/includes/class-pge-dec-plus-mig-01-backfill.php';
$result = PGE_DEC_Plus_MIG_01_Backfill::execute();
echo wp_json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(in_array($result['result'] ?? '', ['backfilled', 'already_backfilled'], true) ? 0 : 1);
