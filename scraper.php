<?php
/**
 * Scraper del CEBC (Gestió Esportiva) per al Bàsquet Pare Manyanet.
 *
 * Compatible amb PHP 7.4+ (InfinityFree). Només necessita cURL (o allow_url_fopen com a pla B),
 * DOM i mbstring (amb alternativa si faltés).
 */

/* =====================================================================
 * UTILITATS DE TEXT
 * ===================================================================== */

function cebc_lower($s)
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower((string)$s, 'UTF-8');
    }
    return strtr(strtolower((string)$s), [
        'À' => 'à', 'Á' => 'á', 'È' => 'è', 'É' => 'é', 'Í' => 'í', 'Ï' => 'ï',
        'Ò' => 'ò', 'Ó' => 'ó', 'Ú' => 'ú', 'Ü' => 'ü', 'Ç' => 'ç', 'Ñ' => 'ñ',
    ]);
}

/** Minúscules, sense accents, sense espais repetits. Serveix per comparar i filtrar. */
function cebc_norm($s)
{
    $s = cebc_lower($s);
    $s = strtr($s, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', '·' => '.', "\xC2\xAD" => '',
    ]);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function cebc_slug($s)
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', cebc_norm($s)), '-');
}

function cebc_text($node)
{
    if (!$node) {
        return '';
    }
    $t = str_replace(["\xC2\xA0", "\xC2\xAD"], [' ', ''], $node->textContent);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

function cebc_date_iso($s)
{
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim((string)$s), $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
    }
    return null;
}

function cebc_hora($s)
{
    if (preg_match('/(\d{1,2}):(\d{2})/', (string)$s, $m)) {
        return sprintf('%02d:%02d', $m[1], $m[2]);
    }
    return null; // "sense hora"
}

/* =====================================================================
 * PETICIONS HTTP (cURL en paral·lel, amb alternativa seqüencial)
 * ===================================================================== */

function cebc_curl_opts($url, array $cfg)
{
    $verify = !empty($cfg['ssl_verify']);
    return [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => (int)$cfg['timeout_peticio'],
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1, // evita problemes d'HTTP/2 en proxies i allotjaments compartits
        CURLOPT_USERAGENT      => $cfg['user_agent'],
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: ca,es;q=0.8'],
    ];
}

function cebc_resp_ok($code, $body)
{
    return $code === 200 && is_string($body) && strlen($body) > 500 && stripos($body, '<html') !== false;
}

/** Descarrega un bloc d'URLs a la vegada amb curl_multi. */
function cebc_curl_chunk(array $chunk, array $cfg)
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($chunk as $url) {
        $ch = curl_init();
        curl_setopt_array($ch, cebc_curl_opts($url, $cfg));
        curl_multi_add_handle($mh, $ch);
        $handles[$url] = $ch;
    }
    $running = 0;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0 && curl_multi_select($mh, 1.0) === -1) {
            usleep(100000);
        }
    } while ($running > 0 && $status === CURLM_OK);

    // Codis de resultat de cada transferència (curl_error() no és fiable amb curl_multi)
    $resultats = [];
    while (($info = curl_multi_info_read($mh)) !== false) {
        if (isset($info['handle'])) {
            $resultats[(int)$info['handle']] = (int)$info['result'];
        }
    }

    $out = [];
    foreach ($handles as $url => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $cr   = isset($resultats[(int)$ch]) ? $resultats[(int)$ch] : 0;
        if ($err === '' && $cr !== 0) {
            $err = function_exists('curl_strerror') ? curl_strerror($cr) : 'cURL error ' . $cr;
        }
        $out[$url] = [
            'ok'   => cebc_resp_ok($code, $body),
            'code' => $code,
            'body' => is_string($body) ? $body : '',
            'err'  => $err !== '' ? $err : ($code !== 200 ? 'HTTP ' . $code : ''),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/** Pla B sense cURL: file_get_contents. */
function cebc_fopen_get($url, array $cfg)
{
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => (int)$cfg['timeout_peticio'],
            'header'  => "User-Agent: " . $cfg['user_agent'] . "\r\nAccept-Language: ca,es;q=0.8\r\n",
        ],
        'ssl' => ['verify_peer' => !empty($cfg['ssl_verify']), 'verify_peer_name' => !empty($cfg['ssl_verify'])],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    return [
        'ok'   => cebc_resp_ok($code, $body),
        'code' => $code,
        'body' => is_string($body) ? $body : '',
        'err'  => $body === false ? 'file_get_contents ha fallat' : ($code !== 200 ? 'HTTP ' . $code : ''),
    ];
}

/**
 * Descarrega moltes URLs. Retorna [url => ['ok','code','body','err']].
 * Les que fallen es reintenten (en sèrie, amb una pausa curta).
 */
function cebc_http_many(array $urls, array $cfg)
{
    $urls = array_values(array_unique($urls));
    $res = [];
    if (!$urls) {
        return $res;
    }
    if (function_exists('curl_multi_init') && function_exists('curl_init')) {
        foreach (array_chunk($urls, max(1, (int)$cfg['concurrencia'])) as $chunk) {
            $res = array_merge($res, cebc_curl_chunk($chunk, $cfg));
        }
    } else {
        foreach ($urls as $u) {
            $res[$u] = cebc_fopen_get($u, $cfg);
        }
    }
    for ($i = 0; $i < (int)$cfg['reintents']; $i++) {
        $failed = array_keys(array_filter($res, function ($r) {
            return !$r['ok'];
        }));
        if (!$failed) {
            break;
        }
        usleep(600000);
        foreach ($failed as $u) {
            if (function_exists('curl_init')) {
                $res[$u] = cebc_curl_chunk([$u], $cfg)[$u];
            } else {
                $res[$u] = cebc_fopen_get($u, $cfg);
            }
        }
    }
    return $res;
}

/* =====================================================================
 * PARSERS HTML
 * ===================================================================== */

function cebc_xpath($html)
{
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return new DOMXPath($dom);
}

function cebc_max_page($html)
{
    $max = 1;
    if (preg_match_all('/(?:\?|&amp;|&)page=(\d+)/', $html, $m)) {
        $max = max(1, (int)max(array_map('intval', $m[1])));
    }
    return min($max, 80);
}

function cebc_input_value(DOMXPath $xp, $id)
{
    $n = $xp->query("//input[@id='" . $id . "']")->item(0);
    return $n ? trim($n->getAttribute('value')) : '';
}

/** Llistat de grups (calendaris o resultats). */
function cebc_parse_llistat($html)
{
    $xp = cebc_xpath($html);
    $grups = [];
    foreach ($xp->query('//table//tbody/tr') as $tr) {
        $th  = $xp->query('./th', $tr)->item(0);
        $tds = $xp->query('./td', $tr);
        if (!$th || $tds->length < 4) {
            continue;
        }
        $id = (int)cebc_text($th);
        if ($id <= 0) {
            continue;
        }
        $grups[] = [
            'id'        => $id,
            'temporada' => cebc_text($tds->item(0)),
            'esport'    => cebc_text($tds->item(1)),
            'categoria' => cebc_text($tds->item(2)),
            'nom'       => cebc_text($tds->item(3)),
        ];
    }
    return ['grups' => $grups, 'pagines' => cebc_max_page($html)];
}

/** Temporades del desplegable: [id => "2026-2027", ...]. */
function cebc_parse_temporades($html)
{
    $xp = cebc_xpath($html);
    $out = [];
    foreach ($xp->query("//select[@name='season']/option") as $op) {
        $v = trim($op->getAttribute('value'));
        if ($v !== '' && ctype_digit($v)) {
            $out[(int)$v] = cebc_text($op);
        }
    }
    return $out;
}

function cebc_estat_from_text($text)
{
    $n = cebc_norm($text);
    if ($n === '') {
        return 'pendent';
    }
    if (strpos($n, 'pendent') !== false) {
        return 'pendent';
    }
    if (strpos($n, 'ajorn') !== false) {
        return 'ajornat';
    }
    if (strpos($n, 'sense resultat') !== false) {
        return 'sense_resultat';
    }
    if (strpos($n, 'reposa') !== false) {
        return 'reposa';
    }
    if (strpos($n, 'suspes') !== false) {
        return 'suspes';
    }
    if (strpos($n, 'incompare') !== false || strpos($n, 'no presentat') !== false) {
        return 'incompareixenca';
    }
    return 'altre';
}

/**
 * Detall d'un grup (pàgina de resultats o de calendari).
 *  - Cada pàgina (?page=N) correspon a una jornada.
 *  - Cada partit són 3 files: [Lloc/Data/Hora], [local - visitant], [resultat o estat].
 *  - Les dates/hores en vermell (text-danger) indiquen canvis respecte del calendari original.
 */
function cebc_parse_detall($html, $mode = 'resultats')
{
    $xp = cebc_xpath($html);
    $out = [
        'categoria'      => cebc_input_value($xp, 'category'),
        'nom'            => cebc_input_value($xp, 'name'),
        'temporada'      => cebc_input_value($xp, 'season'),
        'partits'        => [],
        'classificacio'  => [],
        'jornades_total' => 0,
        'pagines'        => cebc_max_page($html),
    ];

    $jornada = null;
    $volta = 1;
    $cur = null;

    $push = function () use (&$cur, &$out) {
        if ($cur !== null) {
            $out['partits'][] = $cur;
            $cur = null;
        }
    };

    $rows = $xp->query("//table[contains(@class,'table-sm') and not(contains(@class,'table-striped'))]//tr");
    foreach ($rows as $tr) {
        $cls = ' ' . $tr->getAttribute('class') . ' ';
        $tds = $xp->query('./td', $tr);
        $txt = [];
        foreach ($tds as $td) {
            $txt[] = cebc_text($td);
        }
        $joined = trim(implode(' ', $txt));

        // Capçalera de jornada
        if (strpos($cls, ' bg-color1 ') !== false) {
            $push();
            if (preg_match('/JORNADA:\s*(\d+)\s*de\s*(\d+)/iu', $joined, $m)) {
                $jornada = (int)$m[1];
                $out['jornades_total'] = max($out['jornades_total'], (int)$m[2]);
            }
            if (preg_match('/VOLTA:\s*(\d+)/iu', $joined, $m)) {
                $volta = (int)$m[1];
            }
            continue;
        }

        // Fila Lloc / Data / Hora
        if (preg_match('/^Lloc:\s*(.*?)\s*Data:\s*(\d{1,2}\/\d{1,2}\/\d{4})\s*Hora:\s*(.*)$/iu', $joined, $m)) {
            $push();
            $canviData = false;
            $canviHora = false;
            foreach ($tds as $td) {
                if (strpos($td->getAttribute('class'), 'text-danger') === false) {
                    continue;
                }
                $t = cebc_text($td);
                if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $t)) {
                    $canviData = true;
                } elseif (preg_match('/^\d{1,2}:\d{2}/', $t)) {
                    $canviHora = true;
                }
            }
            $lloc = trim($m[1]);
            if ($lloc === '' || strpos(cebc_norm($lloc), 'sense camp') !== false) {
                $lloc = null;
            }
            $cur = [
                'jornada'     => $jornada,
                'volta'       => $volta,
                'data'        => cebc_date_iso($m[2]),
                'hora'        => cebc_hora($m[3]),
                'lloc'        => $lloc,
                'local'       => null,
                'visitant'    => null,
                'gl'          => null,
                'gv'          => null,
                'estat'       => 'pendent',
                'estat_text'  => null,
                'canvi_data'  => $canviData,
                'canvi_hora'  => $canviHora,
            ];
            continue;
        }

        if ($cur === null) {
            continue;
        }

        // Fila d'equips: "local - visitant"
        if ($cur['local'] === null && $tds->length >= 3 && $txt[1] === '-') {
            $cur['local'] = $txt[0];
            $cur['visitant'] = $txt[2];
            if (cebc_norm($txt[0]) === 'reposa' || cebc_norm($txt[2]) === 'reposa') {
                $cur['estat'] = 'reposa';
            }
            if ($mode === 'calendari') {
                $push();
            }
            continue;
        }

        // Fila de resultat o d'estat
        if ($cur['local'] !== null && $mode === 'resultats') {
            $nums = [];
            foreach ($xp->query('.//strong', $tr) as $st) {
                $t = cebc_text($st);
                if (preg_match('/^\d+$/', $t)) {
                    $nums[] = (int)$t;
                }
            }
            if (count($nums) >= 2) {
                $cur['gl'] = $nums[0];
                $cur['gv'] = $nums[1];
                $cur['estat'] = 'jugat';
            } else {
                $estat = cebc_estat_from_text($joined);
                if ($cur['estat'] !== 'reposa') {
                    $cur['estat'] = $estat;
                }
                if ($estat === 'altre' && $joined !== '') {
                    $cur['estat_text'] = $joined;
                }
            }
            $push();
        }
    }
    $push();

    // Classificació
    foreach ($xp->query("//table[contains(@class,'table-striped')]//tbody/tr") as $tr) {
        $tds = $xp->query('./td', $tr);
        if ($tds->length < 9) {
            continue;
        }
        $out['classificacio'][] = [
            'equip' => cebc_text($tds->item(0)),
            'punts' => (int)cebc_text($tds->item(1)),
            'pj'    => (int)cebc_text($tds->item(2)),
            'pg'    => (int)cebc_text($tds->item(3)),
            'pe'    => (int)cebc_text($tds->item(4)),
            'pp'    => (int)cebc_text($tds->item(5)),
            'np'    => (int)cebc_text($tds->item(6)),
            'gf'    => (int)cebc_text($tds->item(7)),
            'gc'    => (int)cebc_text($tds->item(8)),
        ];
    }

    $out['pagines'] = max($out['pagines'], min(80, $out['jornades_total']));
    return $out;
}

/* =====================================================================
 * LÒGICA DEL CLUB
 * ===================================================================== */

function cebc_es_club($nom, $clau)
{
    return $nom !== null && $nom !== '' && strpos(cebc_norm($nom), cebc_norm($clau)) !== false;
}

/** Noms d'equip del club que apareixen en un grup (classificació + partits). */
function cebc_equips_club(array $det, $clau)
{
    $noms = [];
    foreach ($det['classificacio'] as $c) {
        if (cebc_es_club($c['equip'], $clau)) {
            $noms[$c['equip']] = true;
        }
    }
    foreach ($det['partits'] as $p) {
        foreach (['local', 'visitant'] as $k) {
            if (cebc_es_club($p[$k], $clau)) {
                $noms[$p[$k]] = true;
            }
        }
    }
    return array_keys($noms);
}

function cebc_subtitol_auto($teamName)
{
    if (preg_match('/\b([1-6])\s?(r|n|t)\b/iu', cebc_norm($teamName), $m)) {
        return $m[1] . strtolower($m[2]) . ' de Primària';
    }
    return null;
}

function cebc_make_equip(array $def, array $g, $team)
{
    $sub = array_key_exists('subtitol', $def) ? $def['subtitol'] : null;
    if ($sub === 'auto') {
        $sub = cebc_subtitol_auto($team);
    }
    return [
        'id'        => $def['id'],
        'nom'       => $def['nom'],
        'subtitol'  => $sub,
        'color'     => isset($def['color']) ? $def['color'] : 'mixt',
        'categoria' => $g['categoria'],
        'grup_id'   => $g['id'],
        'grup_nom'  => $g['nom'],
        'equip_nom' => $team,
    ];
}

/** Assigna cada (grup, equip del club) a una definició de config; la resta es creen automàticament. */
function cebc_build_equips(array $cfg, array $grups)
{
    $pairs = [];
    foreach ($grups as $g) {
        foreach ($g['equips_club'] as $team) {
            $pairs[] = ['g' => $g, 'team' => $team];
        }
    }
    $used = [];
    $equips = [];
    foreach ($cfg['equips'] as $def) {
        foreach ($pairs as $i => $p) {
            if (isset($used[$i])) {
                continue;
            }
            if (!empty($def['categoria']) && !preg_match($def['categoria'], cebc_norm($p['g']['categoria']))) {
                continue;
            }
            if (!empty($def['grup']) && !preg_match($def['grup'], cebc_norm($p['g']['nom']))) {
                continue;
            }
            if (!empty($def['equip']) && !preg_match($def['equip'], cebc_norm($p['team']))) {
                continue;
            }
            $used[$i] = true;
            $equips[] = cebc_make_equip($def, $p['g'], $p['team']);
            break;
        }
    }
    if (isset($cfg['auto_equips']) && !$cfg['auto_equips']) {
        return $equips;
    }
    foreach ($pairs as $i => $p) {
        if (isset($used[$i])) {
            continue;
        }
        $def = [
            'id'       => 'auto-' . cebc_slug($p['g']['categoria'] . ' ' . $p['g']['id'] . ' ' . $p['team']),
            'nom'      => $p['g']['categoria'],
            'subtitol' => $p['team'],
            'color'    => 'mixt',
        ];
        $equips[] = cebc_make_equip($def, $p['g'], $p['team']);
    }
    return $equips;
}

/**
 * Ordena la classificació: punts (desc) -> enfrontament directe entre els empatats
 * (punts i després diferència) -> diferència de punts general -> punts a favor -> nom.
 * El CEBC publica la taula sense ordenar; aquí es calcula l'ordre.
 */
function cebc_ordena_classificacio(array $cls, array $partits)
{
    if (count($cls) < 2) {
        return $cls;
    }
    $pts = [];
    foreach ($cls as $c) {
        $pts[(int)$c['punts']][] = $c['equip'];
    }
    // Mini-lliga entre equips empatats a punts
    $h2h = [];
    foreach ($pts as $p => $noms) {
        if (count($noms) < 2) {
            continue;
        }
        foreach ($noms as $n) {
            $h2h[$n] = ['p' => 0, 'd' => 0];
        }
        foreach ($partits as $m) {
            if (!isset($m['gl'], $m['gv']) || $m['gl'] === null || $m['gv'] === null) {
                continue;
            }
            $l = $m['local']; $v = $m['visitant'];
            if (!in_array($l, $noms, true) || !in_array($v, $noms, true)) {
                continue;
            }
            $gl = (int)$m['gl']; $gv = (int)$m['gv'];
            $h2h[$l]['d'] += $gl - $gv;
            $h2h[$v]['d'] += $gv - $gl;
            if ($gl > $gv) { $h2h[$l]['p'] += 3; $h2h[$v]['p'] += 1; }
            elseif ($gv > $gl) { $h2h[$v]['p'] += 3; $h2h[$l]['p'] += 1; }
            else { $h2h[$l]['p'] += 2; $h2h[$v]['p'] += 2; }
        }
    }
    usort($cls, function ($a, $b) use ($h2h) {
        if ($a['punts'] !== $b['punts']) {
            return $b['punts'] <=> $a['punts'];
        }
        $ha = $h2h[$a['equip']] ?? ['p' => 0, 'd' => 0];
        $hb = $h2h[$b['equip']] ?? ['p' => 0, 'd' => 0];
        if ($ha['p'] !== $hb['p']) { return $hb['p'] <=> $ha['p']; }
        if ($ha['d'] !== $hb['d']) { return $hb['d'] <=> $ha['d']; }
        $da = $a['gf'] - $a['gc']; $db = $b['gf'] - $b['gc'];
        if ($da !== $db) { return $db <=> $da; }
        if ($a['gf'] !== $b['gf']) { return $b['gf'] <=> $a['gf']; }
        return strcmp($a['equip'], $b['equip']);
    });
    return $cls;
}

function cebc_sort_partits(array &$partits)
{
    usort($partits, function ($a, $b) {
        $k = [(int)$a['volta'], (int)$a['jornada'], (string)$a['data'], (string)$a['hora'], (string)$a['local']];
        $l = [(int)$b['volta'], (int)$b['jornada'], (string)$b['data'], (string)$b['hora'], (string)$b['local']];
        return $k <=> $l;
    });
}

/* =====================================================================
 * PERSISTÈNCIA
 * ===================================================================== */

function cebc_load_json($file)
{
    if (!is_file($file)) {
        return null;
    }
    $d = json_decode((string)@file_get_contents($file), true);
    return is_array($d) ? $d : null;
}

function cebc_save_json($file, array $data)
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $file);
}

/* =====================================================================
 * ORQUESTRADOR
 * ===================================================================== */

/**
 * Executa una actualització completa. Retorna ['ok'=>bool, 'log'=>[], 'errors'=>[], 'data'=>array|null].
 * Si alguna part falla, es conserva el que hi havia a data.json per no deixar la web buida.
 */
function cebc_actualitzar(array $cfg, $dataFile)
{
    $t0 = microtime(true);
    $log = [];
    $errors = [];
    $partial = false;
    $left = function () use ($t0, $cfg) {
        return (float)$cfg['pressupost_segons'] - (microtime(true) - $t0);
    };

    $prev = cebc_load_json($dataFile);
    $base = rtrim($cfg['base_url'], '/');

    /* 0) Temporada: fixa o detectada automàticament (la de l'id més alt del desplegable) */
    $seasonId = $cfg['temporada_id'];
    if ($seasonId === 'auto') {
        $detectada = null;
        $urlBase = $base . '/resultats';
        $rs = cebc_http_many([$urlBase], $cfg)[$urlBase];
        if ($rs['ok']) {
            $opcions = cebc_parse_temporades($rs['body']);
            if ($opcions) {
                $detectada = max(array_keys($opcions));
                $log[] = 'Temporada detectada: ' . $opcions[$detectada] . ' (id ' . $detectada . ').';
            }
        }
        if ($detectada === null) {
            $detectada = ($prev && !empty($prev['meta']['temporada_id'])) ? (int)$prev['meta']['temporada_id'] : 2;
            $errors[] = 'No s\'ha pogut detectar la temporada automàticament; s\'usa la id ' . $detectada . '.';
        }
        $seasonId = $detectada;
    }
    $seasonId = (int)$seasonId;
    $listUrl = $base . '/resultats?season=' . $seasonId . '&sport=' . (int)$cfg['esport_id'] . '&category=&name=';

    /* 1) Llistat de grups de la temporada */
    $r = cebc_http_many([$listUrl], $cfg)[$listUrl];
    if (!$r['ok']) {
        $errors[] = 'No s\'ha pogut obtenir el llistat de grups: ' . $r['err'];
        return ['ok' => false, 'log' => $log, 'errors' => $errors, 'data' => $prev];
    }
    $ll = cebc_parse_llistat($r['body']);
    if ($ll['pagines'] > 1) {
        $extra = [];
        for ($p = 2; $p <= $ll['pagines']; $p++) {
            $extra[] = $listUrl . '&page=' . $p;
        }
        foreach (cebc_http_many($extra, $cfg) as $u => $rr) {
            if ($rr['ok']) {
                $ll['grups'] = array_merge($ll['grups'], cebc_parse_llistat($rr['body'])['grups']);
            } else {
                $errors[] = 'Pàgina del llistat no disponible (' . $u . '): ' . $rr['err'];
            }
        }
    }
    if (!$ll['grups']) {
        $errors[] = 'El llistat de grups ha vingut buit (possible canvi a la web del CEBC).';
        return ['ok' => false, 'log' => $log, 'errors' => $errors, 'data' => $prev];
    }
    if (!empty($cfg['filtre_grups'])) {
        $filtre = $cfg['filtre_grups'];
        $ll['grups'] = array_values(array_filter($ll['grups'], function ($g) use ($filtre) {
            return preg_match($filtre, cebc_norm($g['categoria'] . ' ' . $g['nom'])) === 1;
        }));
    }
    $log[] = 'Llistat: ' . count($ll['grups']) . ' grups de bàsquet.';

    /* 2) Primera pàgina de resultats de cada grup (ens diu si el club hi juga) */
    $urls1 = [];
    foreach ($ll['grups'] as $g) {
        $urls1[$g['id']] = $base . '/resultats/' . $g['id'];
    }
    $resp1 = cebc_http_many(array_values($urls1), $cfg);

    $detalls = [];   // id => detall acumulat
    $meta = [];      // id => info del llistat
    foreach ($ll['grups'] as $g) {
        $meta[$g['id']] = $g;
        $rr = $resp1[$urls1[$g['id']]];
        if (!$rr['ok']) {
            $errors[] = 'Grup ' . $g['id'] . ' (' . $g['nom'] . '): no s\'ha pogut descarregar (' . $rr['err'] . ').';
            $detalls[$g['id']] = null;
            continue;
        }
        $det = cebc_parse_detall($rr['body'], 'resultats');
        if (!empty($cfg['nomes_definits'])) {
            $pseudo = [['id' => $g['id'], 'categoria' => $g['categoria'], 'nom' => $g['nom'],
                        'equips_club' => cebc_equips_club($det, $cfg['club_clau'])]];
            if (!cebc_build_equips($cfg, $pseudo)) {
                unset($detalls[$g['id']]);
                continue; // cap equip definit en aquest grup: no cal baixar-ne més pàgines
            }
        }
        foreach ($det['partits'] as &$p) {
            $p['pagina'] = 1;
        }
        unset($p);
        $detalls[$g['id']] = $det;
    }

    /* 3) Resta de pàgines (jornades) només dels grups on juga el club */
    $pending = [];   // url => [id, pagina]
    foreach ($detalls as $id => $det) {
        if ($det === null) {
            continue;
        }
        if (!cebc_equips_club($det, $cfg['club_clau'])) {
            continue;
        }
        for ($p = 2; $p <= $det['pagines']; $p++) {
            $pending[$base . '/resultats/' . $id . '?page=' . $p] = [$id, $p];
        }
    }
    $batchSize = max(4, (int)$cfg['concurrencia'] * 3);
    $failedPages = [];
    // El CEBC mostra a cada pàgina (jornada) la classificació ACUMULADA fins a aquella jornada.
    // Per tenir la classificació actual cal la de l'última jornada, no la de la primera.
    $clsPagina = [];
    foreach (array_chunk($pending, $batchSize, true) as $batch) {
        if ($left() < 4) {
            $partial = true;
            foreach ($batch as $u => $info) {
                $failedPages[$info[0]][] = $info[1];
            }
            continue;
        }
        $resp = cebc_http_many(array_keys($batch), $cfg);
        foreach ($batch as $u => $info) {
            list($id, $pg) = $info;
            if (!$resp[$u]['ok']) {
                $failedPages[$id][] = $pg;
                $errors[] = 'Grup ' . $id . ', pàgina ' . $pg . ': ' . $resp[$u]['err'];
                continue;
            }
            $d = cebc_parse_detall($resp[$u]['body'], 'resultats');
            foreach ($d['partits'] as $p) {
                $p['pagina'] = $pg;
                $detalls[$id]['partits'][] = $p;
            }
            if ($d['classificacio']) {
                $clsPagina[$id][$pg] = $d['classificacio'];
            }
        }
    }

    // Classificació definitiva de cada grup: la de l'última jornada (la més completa)
    $prevGrupsCls = ($prev && isset($prev['grups']) && is_array($prev['grups'])) ? $prev['grups'] : [];
    foreach ($detalls as $id => $det) {
        if ($det === null || !cebc_equips_club($det, $cfg['club_clau'])) {
            continue; // grups sense equip del club: no es descarreguen més pàgines ni cal la classificació
        }
        if (empty($det['classificacio']) && empty($clsPagina[$id])) {
            continue; // categoria sense classificació oficial (p. ex. Prebenjamí)
        }
        $ultima = (int)$det['pagines'];
        if (isset($clsPagina[$id][$ultima])) {
            $detalls[$id]['classificacio'] = $clsPagina[$id][$ultima];
        } elseif ($ultima > 1) {
            // L'última pàgina no s'ha pogut descarregar: es reaprofita l'anterior si hi era; si no, la més recent disponible
            if (!empty($prevGrupsCls[(string)$id]['classificacio'])) {
                $detalls[$id]['classificacio'] = $prevGrupsCls[(string)$id]['classificacio'];
                $errors[] = 'Grup ' . $id . ': no s\'ha pogut llegir la classificació de l\'última jornada; es conserva l\'anterior.';
            } elseif (!empty($clsPagina[$id])) {
                $millor = max(array_keys($clsPagina[$id]));
                $detalls[$id]['classificacio'] = $clsPagina[$id][$millor];
                $errors[] = 'Grup ' . $id . ': classificació provisional (jornada ' . $millor . '), l\'última no s\'ha pogut llegir.';
            } else {
                $errors[] = 'Grup ' . $id . ': la classificació pot estar desactualitzada (només s\'ha llegit la de la primera jornada).';
            }
        }
    }
    if ($partial) {
        $errors[] = 'Temps esgotat: actualització parcial (es conserva el que hi havia de les jornades pendents).';
    }

    /* 4) Calendaris (pla B o fusió, segons config) */
    $modeCal = $cfg['usar_calendari'];
    if ($modeCal !== 'mai') {
        $calUrls = [];
        foreach ($detalls as $id => $det) {
            $needs = ($det === null) || ($modeCal === 'sempre');
            if ($det !== null && !$needs) {
                $needs = empty($det['partits']) && cebc_equips_club($det, $cfg['club_clau']);
            }
            if (!$needs || $left() < 6) {
                continue;
            }
            // Primer la pàgina 1 per saber quantes jornades hi ha
            $calUrls[$id] = $base . '/calendaris/' . $id;
        }
        if ($calUrls) {
            $cal1 = cebc_http_many(array_values($calUrls), $cfg);
            $calExtra = [];
            $calParsed = [];
            foreach ($calUrls as $id => $u) {
                if (!$cal1[$u]['ok']) {
                    $errors[] = 'Calendari del grup ' . $id . ' no disponible: ' . $cal1[$u]['err'];
                    continue;
                }
                $c = cebc_parse_detall($cal1[$u]['body'], 'calendari');
                foreach ($c['partits'] as &$p) {
                    $p['pagina'] = 1;
                }
                unset($p);
                $calParsed[$id] = $c;
                if ($detalls[$id] === null && !cebc_equips_club($c, $cfg['club_clau'])) {
                    unset($calParsed[$id]); // el club no hi juga: no cal seguir
                    continue;
                }
                for ($p = 2; $p <= $c['pagines']; $p++) {
                    $calExtra[$base . '/calendaris/' . $id . '?page=' . $p] = [$id, $p];
                }
            }
            if ($left() > 4 && $calExtra) {
                $resp = cebc_http_many(array_keys($calExtra), $cfg);
                foreach ($calExtra as $u => $info) {
                    list($id, $pg) = $info;
                    if (!isset($calParsed[$id]) || !$resp[$u]['ok']) {
                        continue;
                    }
                    $c = cebc_parse_detall($resp[$u]['body'], 'calendari');
                    foreach ($c['partits'] as $p) {
                        $p['pagina'] = $pg;
                        $calParsed[$id]['partits'][] = $p;
                    }
                }
            }
            // Fusió: si el grup no tenia resultats, s'usa el calendari; si en tenia, només s'omplen forats
            foreach ($calParsed as $id => $c) {
                if ($detalls[$id] === null) {
                    $c['classificacio'] = [];
                    $detalls[$id] = $c;
                    $log[] = 'Grup ' . $id . ': dades obtingudes només del calendari (sense resultats).';
                    continue;
                }
                $key = function ($p) {
                    return $p['volta'] . '|' . $p['jornada'] . '|' . cebc_norm($p['local']) . '|' . cebc_norm($p['visitant']);
                };
                $have = [];
                foreach ($detalls[$id]['partits'] as $p) {
                    $have[$key($p)] = true;
                }
                foreach ($c['partits'] as $p) {
                    if (!isset($have[$key($p)])) {
                        $detalls[$id]['partits'][] = $p;
                    }
                }
            }
        }
    }

    /* 5) Muntatge de les dades finals (només grups on juga el club) */
    $prevGrups = ($prev && isset($prev['grups']) && is_array($prev['grups'])) ? $prev['grups'] : [];
    $grupsOut = [];
    $grupsInfo = [];
    foreach ($detalls as $id => $det) {
        if ($det === null) {
            // No s'ha pogut descarregar: es conserva l'anterior si hi era
            if (isset($prevGrups[(string)$id])) {
                $grupsOut[(string)$id] = $prevGrups[(string)$id];
                $grupsInfo[] = $prevGrups[(string)$id] + ['equips_club' => []];
                $errors[] = 'Grup ' . $id . ': s\'han conservat les dades anteriors.';
            }
            continue;
        }
        $club = cebc_equips_club($det, $cfg['club_clau']);
        if (!$club) {
            continue;
        }
        // Reaprofita jornades que han fallat
        if (!empty($failedPages[$id]) && isset($prevGrups[(string)$id]['partits'])) {
            foreach ($prevGrups[(string)$id]['partits'] as $p) {
                if (isset($p['pagina']) && in_array((int)$p['pagina'], $failedPages[$id], true)) {
                    $det['partits'][] = $p;
                }
            }
        }
        cebc_sort_partits($det['partits']);
        $info = $meta[$id];
        $g = [
            'id'             => (int)$id,
            'categoria'      => $det['categoria'] !== '' ? $det['categoria'] : $info['categoria'],
            'nom'            => $det['nom'] !== '' ? $det['nom'] : $info['nom'],
            'jornades_total' => $det['jornades_total'],
            'partits'        => $det['partits'],
            'classificacio'  => cebc_ordena_classificacio($det['classificacio'], $det['partits']),
        ];
        $grupsOut[(string)$id] = $g;
        $grupsInfo[] = $g + ['equips_club' => $club];
        $log[] = sprintf(
            'Grup %d · %s · %s: %d partits, %d equips a la classificació, equips del club: %s.',
            $id, $g['categoria'], $g['nom'], count($g['partits']), count($g['classificacio']), implode(', ', $club)
        );
    }

    if (!$grupsOut) {
        $errors[] = 'No s\'ha trobat cap equip del club (clau "' . $cfg['club_clau'] . '") a la temporada id ' . $seasonId . '. No es modifica data.json.';
        return ['ok' => false, 'log' => $log, 'errors' => $errors, 'data' => $prev];
    }

    $equips = cebc_build_equips($cfg, $grupsInfo);
    $equipsAssoc = [];
    foreach ($equips as $e) {
        $equipsAssoc[$e['id']] = $e;
    }
    // Equips dels grups que no s'han pogut descarregar: es conserven els anteriors
    if ($prev && isset($prev['equips']) && is_array($prev['equips'])) {
        foreach ($prev['equips'] as $eid => $e) {
            if (!isset($equipsAssoc[$eid]) && isset($e['grup_id']) && isset($grupsOut[(string)$e['grup_id']])) {
                $equipsAssoc[$eid] = $e;
            }
        }
        $equips = array_values($equipsAssoc);
    }

    $temporada = '';
    foreach ($ll['grups'] as $g) {
        if ($g['temporada'] !== '') {
            $temporada = $g['temporada'];
            break;
        }
    }

    $data = [
        'meta' => [
            'actualitzat' => date('c'),
            'temporada'   => $temporada,
            'temporada_id' => $seasonId,
            'font'        => $base,
            'durada_s'    => round(microtime(true) - $t0, 1),
            'parcial'     => $partial,
            'errors'      => $errors,
        ],
        'equips' => $equipsAssoc,
        'grups'  => $grupsOut,
    ];

    if (!cebc_save_json($dataFile, $data)) {
        $errors[] = 'No s\'ha pogut escriure ' . basename($dataFile) . ' (permisos de la carpeta?).';
        return ['ok' => false, 'log' => $log, 'errors' => $errors, 'data' => $prev];
    }
    $log[] = 'Equips publicats: ' . implode(', ', array_map(function ($e) {
        return $e['nom'];
    }, $equips)) . '.';
    $log[] = 'Fet en ' . $data['meta']['durada_s'] . ' s.';
    return ['ok' => true, 'log' => $log, 'errors' => $errors, 'data' => $data];
}
