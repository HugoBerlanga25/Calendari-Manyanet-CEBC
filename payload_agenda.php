<?php
/** Datos del Benjamí Verd para el navegador (los incrusta index.php y los sirve dades.php). */

function cebc_carrega_data($file)
{
    if (!is_file($file)) {
        return null;
    }
    $d = json_decode((string)file_get_contents($file), true);
    return (is_array($d) && !empty($d['equips'])) ? $d : null;
}

function cebc_data_text($iso)
{
    $mesos = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $ts = strtotime((string)$iso);
    if (!$ts) {
        return '';
    }
    return date('j', $ts) . ' de ' . $mesos[(int)date('n', $ts) - 1] . ' a las ' . date('H:i', $ts);
}

/** Resultado del último intento de actualización (lo escribe actualizar.php). */
function agenda_ultim_intent()
{
    $f = __DIR__ . '/../cache/ultim_resultat.json';
    if (!is_file($f)) {
        return null;
    }
    $r = json_decode((string)@file_get_contents($f), true);
    if (!is_array($r) || empty($r['hora'])) {
        return null;
    }
    if ($r['ok'] === null) {
        if (time() - strtotime($r['hora']) < 90) {
            return null; // todavía en curso
        }
        $r['ok'] = false;
        $r['errors'] = ['El último intento no terminó (tiempo límite del hosting o error de PHP).'];
    }
    return ['hora' => $r['hora'], 'ok' => (bool)$r['ok'], 'errors' => isset($r['errors']) ? $r['errors'] : [], 'origen' => isset($r['origen']) ? $r['origen'] : ''];
}

function agenda_payload($data, array $cfg)
{
    $p = [
        'meta'   => ['actualitzat' => '', 'text' => '', 'temporada' => ''],
        'config' => ['auto' => !empty($cfg['auto_visita']) && empty($cfg['dades_remotes']), 'minuts' => (int)$cfg['interval_visita_min'], 'app' => $cfg['app'],
                     'remot' => !empty($cfg['dades_remotes']) ? rtrim($cfg['dades_remotes'], '/') . '/agenda.json' : ''],
        'verd'   => ['nom' => 'Benjamí Verd', 'equip' => '', 'grup' => '', 'partits' => [], 'classificacio' => []],
        'intent' => agenda_ultim_intent(),
    ];
    if (!$data) {
        return $p;
    }
    $meta = isset($data['meta']) ? $data['meta'] : [];
    $p['meta'] = [
        'actualitzat' => isset($meta['actualitzat']) ? $meta['actualitzat'] : '',
        'text'        => isset($meta['actualitzat']) ? cebc_data_text($meta['actualitzat']) : '',
        'temporada'   => isset($meta['temporada']) ? $meta['temporada'] : '',
    ];
    $eq = isset($data['equips']['benjami-verd']) ? $data['equips']['benjami-verd'] : reset($data['equips']);
    $g  = isset($data['grups'][(string)$eq['grup_id']]) ? $data['grups'][(string)$eq['grup_id']] : null;
    if (!$g) {
        return $p;
    }
    $nostre = $eq['equip_nom'];
    $out = [];
    foreach ($g['partits'] as $m) {
        $casa = ($m['local'] === $nostre);
        if (!$casa && $m['visitant'] !== $nostre) {
            continue;
        }
        $pf = $casa ? $m['gl'] : $m['gv'];
        $pc = $casa ? $m['gv'] : $m['gl'];
        $out[] = [
            'jornada' => (int)$m['jornada'], 'volta' => (int)$m['volta'],
            'data' => $m['data'], 'hora' => $m['hora'], 'lloc' => $m['lloc'],
            'rival' => $casa ? $m['visitant'] : $m['local'], 'casa' => $casa,
            'pf' => $pf, 'pc' => $pc, 'estat' => $m['estat'],
            'canvi' => !empty($m['canvi_data']) || !empty($m['canvi_hora']),
        ];
    }
    $p['verd'] = [
        'nom' => $eq['nom'], 'equip' => $nostre, 'grup' => $g['nom'],
        'partits' => $out, 'classificacio' => $g['classificacio'],
    ];
    return $p;
}

function cebc_json($payload)
{
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
