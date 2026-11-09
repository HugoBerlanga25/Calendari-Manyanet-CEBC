<?php
/**
 * Actualizador para GitHub Actions: php run.php agenda|basquet
 * Descarga los datos del CEBC y escribe out/<app>.json (el mismo formato que dades.php de cada web).
 * Si falla, no toca el archivo anterior.
 */
date_default_timezone_set('Europe/Madrid');
$app = isset($argv[1]) ? $argv[1] : '';
if (!in_array($app, ['agenda', 'basquet'], true)) {
    fwrite(STDERR, "Uso: php run.php agenda|basquet\n");
    exit(2);
}
$cfg = require __DIR__ . "/config_$app.php";
$cfg['pressupost_segons'] = 120;   // aquí no hay límite de tiempo del hosting
require __DIR__ . '/scraper.php';
require __DIR__ . "/payload_$app.php";

@mkdir(__DIR__ . '/work', 0755, true);
@mkdir(__DIR__ . '/out', 0755, true);
$dataFile = __DIR__ . "/work/$app.data.json";
@unlink($dataFile);

$res = cebc_actualitzar($cfg, $dataFile);
foreach ($res['log'] as $l) { echo $l, "\n"; }
foreach ($res['errors'] as $e) { echo "AVISO: $e\n"; }
if (!$res['ok']) {
    fwrite(STDERR, "ERROR: no se ha podido actualizar '$app'; se conserva lo publicado.\n");
    exit(1);
}
$payload = ($app === 'agenda')
    ? agenda_payload(cebc_carrega_data($dataFile), $cfg)
    : cebc_payload(cebc_carrega_data($dataFile), $cfg);
if (empty($payload['meta']['actualitzat'])) {
    fwrite(STDERR, "ERROR: datos vacíos; se conserva lo publicado.\n");
    exit(1);
}
file_put_contents(__DIR__ . "/out/$app.json", cebc_json($payload));
echo "OK · out/$app.json escrito (" . $payload['meta']['actualitzat'] . ")\n";
