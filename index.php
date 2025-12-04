<?php
/**
 * TV Guide для iptvx.one EPG_LITE
 * - источник: https://iptvx.one/EPG_LITE (epg_lite.xml.gz)
 * - локальный распакованный файл: epg_lite.xml
 * - кэш: epg_cache.json
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

$cache_file = __DIR__ . '/epg_cache.json';
$cache_time = 3600; // 1 час

/**
 * Разбор времени из EPG: "20251119110000 +0300"
 * Возвращает UNIX timestamp (UTC) или false.
 */
function parse_epg_time(string $str)
{
    $str = trim($str);
    if (!preg_match('/^(\d{14})\s*([+\-]\d{4})?/', $str, $m)) {
        return false;
    }

    $ymdHis = $m[1];                // 20251119110000
    $tzOffset = $m[2] ?? '+0000';   // +0300 или +0000

    // Пытаемся сначала как "YmdHis O"
    $dt = DateTime::createFromFormat(
        'YmdHis O',
        $ymdHis . ' ' . $tzOffset,
        new DateTimeZone('UTC')
    );

    if (!$dt) {
        // Фолбэк — без таймзоны, считаем, что уже UTC
        $dt = DateTime::createFromFormat(
            'YmdHis',
            $ymdHis,
            new DateTimeZone('UTC')
        );
    }

    if (!$dt) {
        return false;
    }

    return $dt->getTimestamp();
}

/**
 * JSON-ответ и выход
 */
function json_exit(array $payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$has_cache = file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time;

/**
 * API: обновление кэша
 */
if (isset($_GET['action']) && $_GET['action'] === 'update_cache') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Accel-Buffering: no');

    set_time_limit(600);
    ini_set('memory_limit', '1024M');

    $log = [];
    $log[] = ['time' => date('H:i:s'), 'msg' => 'Начинаем загрузку EPG...'];

    $local_xml = __DIR__ . '/epg_lite.xml';
    $epg_url   = 'https://iptvx.one/EPG_LITE';

    // Наши каналы по ID из EPG_LITE
    $channels_by_id = [
        'piaty-int'        => '5 International',
        'domashny-int'     => 'Домашний International',
        'izvestia'         => 'Известия',
        'ntv-mir'          => 'НТВ Мир',
        'ntv-pravo'        => 'НТВ Право',
        'ntv-serial'       => 'НТВ Сериал',
        'ntv-style'        => 'НТВ Стиль',
        'perec-int'        => 'Перец International',
        'rentv-int'        => 'РЕН International',
        'rtr-planeta-eu'   => 'РТР Планета',
        'rossia-24'        => 'Россия 24',
        'sts-int'          => 'СТС International',
        'tnt-int-eu'       => 'ТНТ International',
        'tnt-music'        => 'ТНТ Music',
    ];

    // Резервный фильтр по имени (если вдруг ID изменится)
    $channels_filter = [
        'domashniy international' => 'Домашний International',
        'domashniy'               => 'Домашний International',
        'domashny'                => 'Домашний International',
        'izvesti'                 => 'Известия',
        'ntv mir'                 => 'НТВ Мир',
        'ntv pravo'               => 'НТВ Право',
        'ntv serial'              => 'НТВ Сериал',
        'ntv style'               => 'НТВ Стиль',
        'perets international'    => 'Перец International',
        'perec'                   => 'Перец International',
        'ren tv'                  => 'РЕН International',
        'rtr planeta'             => 'РТР Планета',
        'rossiya 24'              => 'Россия 24',
        'rossia 24'               => 'Россия 24',
        'sts international'       => 'СТС International',
        'tnt int'                 => 'ТНТ International',
        'tnt music'               => 'ТНТ Music',
    ];

    $xml_content = null;
    $xml_max_age = 86400; // 24 часа — максимальный возраст локального XML

    // 1) Пытаемся взять локальный XML (если не старше 24 часов)
    $use_local = false;
    if (file_exists($local_xml)) {
        $xml_age = time() - filemtime($local_xml);
        if ($xml_age < $xml_max_age) {
            $use_local = true;
            $age_hours = round($xml_age / 3600, 1);
            $log[] = ['time' => date('H:i:s'), 'msg' => "📁 Найден локальный XML (возраст: {$age_hours} ч): {$local_xml}"];
        } else {
            $age_hours = round($xml_age / 3600, 1);
            $log[] = ['time' => date('H:i:s'), 'msg' => "⚠️ Локальный XML устарел (возраст: {$age_hours} ч), удаляем...", 'warn' => true];
            @unlink($local_xml);
        }
    }

    if ($use_local) {
        $start_time = microtime(true);
        $xml_content = @file_get_contents($local_xml);
        $load_time = round(microtime(true) - $start_time, 2);

        if ($xml_content === false) {
            $log[] = ['time' => date('H:i:s'), 'msg' => "❌ Ошибка чтения локального XML", 'error' => true];
            json_exit(['success' => false, 'log' => $log]);
        }

        $size_mb = round(strlen($xml_content) / 1024 / 1024, 2);
        $log[] = ['time' => date('H:i:s'), 'msg' => "✅ Загружен локальный XML: {$size_mb} MB за {$load_time} сек"];
    } else {
        // 2) Скачиваем gz и распаковываем
        $log[] = ['time' => date('H:i:s'), 'msg' => "🌐 Скачиваем EPG_LITE: {$epg_url}"];

        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 120,
                'user_agent' => 'Vanlife TV Guide Bot',
            ]
        ]);

        $start_time = microtime(true);
        $gz_content = @file_get_contents($epg_url, false, $ctx);
        $download_time = round(microtime(true) - $start_time, 2);

        if ($gz_content === false) {
            $error = error_get_last();
            $log[] = ['time' => date('H:i:s'), 'msg' => "❌ Ошибка загрузки: " . ($error['message'] ?? 'unknown'), 'error' => true];
            json_exit(['success' => false, 'log' => $log]);
        }

        $size_mb = round(strlen($gz_content) / 1024 / 1024, 2);
        $log[] = ['time' => date('H:i:s'), 'msg' => "✅ Скачано: {$size_mb} MB за {$download_time} сек"];

        $log[] = ['time' => date('H:i:s'), 'msg' => "Распаковываем EPG..."];
        $start_time = microtime(true);
        $xml_content = @gzdecode($gz_content);
        $unzip_time = round(microtime(true) - $start_time, 2);

        if ($xml_content === false) {
            $log[] = ['time' => date('H:i:s'), 'msg' => "❌ Ошибка распаковки gzip", 'error' => true];
            json_exit(['success' => false, 'log' => $log]);
        }

        $xml_mb = round(strlen($xml_content) / 1024 / 1024, 2);
        $log[] = ['time' => date('H:i:s'), 'msg' => "✅ Распаковано: {$xml_mb} MB за {$unzip_time} сек"];

        // Сохраняем распакованный XML на диск
        @file_put_contents($local_xml, $xml_content);
        $log[] = ['time' => date('H:i:s'), 'msg' => "💾 Сохранён локальный XML: epg_lite.xml"];
    }

    // Теперь у нас в $xml_content весь epg_lite.xml

    $channels = [];
    $programs = [];
    $matched_programs = 0;

    // Диапазон дат: от вчера до +7 дней (UTC)
    $now = time();
    $date_start = strtotime(gmdate('Y-m-d 00:00:00', $now - 86400));        // вчера 00:00
    $date_end   = strtotime(gmdate('Y-m-d 23:59:59', $now + 7 * 86400));    // +7 дней

    // 1) Каналы
    $log[] = ['time' => date('H:i:s'), 'msg' => "Парсим каналы..."];

    preg_match_all(
        '/<channel\s+id="([^"]+)"[^>]*>(.*?)<\/channel>/s',
        $xml_content,
        $channel_matches,
        PREG_SET_ORDER
    );

    $log[] = ['time' => date('H:i:s'), 'msg' => "Найдено каналов в XML: " . count($channel_matches)];

    foreach ($channel_matches as $m) {
        $id    = $m[1];
        $inner = $m[2];

        // Сначала жёстко по ID
        if (isset($channels_by_id[$id])) {
            $display_name = $channels_by_id[$id];
        } else {
            // Пытаемся по имени
            if (preg_match('/<display-name[^>]*>(.*?)<\/display-name>/s', $inner, $mName)) {
                $name_raw = trim(html_entity_decode($mName[1], ENT_QUOTES, 'UTF-8'));
                $name_lower = mb_strtolower($name_raw, 'UTF-8');
                $display_name = null;
                foreach ($channels_filter as $key => $val) {
                    if (mb_stripos($name_lower, $key, 0, 'UTF-8') !== false) {
                        $display_name = $val;
                        break;
                    }
                }
                if (!$display_name) {
                    continue;
                }
            } else {
                continue;
            }
        }

        $name = '';
        if (preg_match('/<display-name[^>]*>(.*?)<\/display-name>/s', $inner, $mName2)) {
            $name = trim(html_entity_decode($mName2[1], ENT_QUOTES, 'UTF-8'));
        } else {
            $name = $display_name;
        }

        $icon = '';
        if (preg_match('/<icon[^>]+src="([^"]+)"/s', $inner, $mIcon)) {
            $icon = trim($mIcon[1]);
        }

        $channels[$id] = [
            'id'           => $id,
            'name'         => $name,
            'display_name' => $display_name,
            'icon'         => $icon,
        ];
    }

    $log[] = ['time' => date('H:i:s'), 'msg' => "✅ Отфильтровано каналов: " . count($channels)];

    if (!count($channels)) {
        $log[] = ['time' => date('H:i:s'), 'msg' => "⚠️ Ни один нужный канал не найден", 'warn' => true];
        json_exit(['success' => false, 'log' => $log]);
    }

    // 2) Программы
    $log[] = ['time' => date('H:i:s'), 'msg' => "Парсим программы..."];

    /**
     * В epg_lite.xml порядок атрибутов может быть произвольный:
     *  <programme start="..." stop="..." channel="tnt-music">
     *  <programme channel="tnt-music" start="..." stop="...">
     */
    preg_match_all(
        '/<programme\b([^>]*)>(.*?)<\/programme>/s',
        $xml_content,
        $prog_matches,
        PREG_SET_ORDER
    );

    $total_programs = count($prog_matches);
    $log[] = ['time' => date('H:i:s'), 'msg' => "Всего программ в XML: {$total_programs}"];

    // освободим память от исходного XML
    unset($xml_content);

    foreach ($prog_matches as $m) {
        $attrStr = $m[1];
        $inner   = $m[2];

        // channel="..."
        if (!preg_match('/\bchannel="([^"]+)"/', $attrStr, $mCh)) {
            continue;
        }
        $channel_id = $mCh[1];

        if (!isset($channels[$channel_id])) {
            // не наш канал
            continue;
        }

        // start="..."
        if (!preg_match('/\bstart="([^"]+)"/', $attrStr, $mSt)) {
            continue;
        }
        $start_str = $mSt[1];

        // stop="..."
        $stop_str = null;
        if (preg_match('/\bstop="([^"]+)"/', $attrStr, $mSp)) {
            $stop_str = $mSp[1];
        }

        $start_ts = parse_epg_time($start_str);
        $stop_ts  = $stop_str ? parse_epg_time($stop_str) : false;

        if ($start_ts === false) {
            continue;
        }
        if ($stop_ts === false) {
            $stop_ts = $start_ts + 3600;
        }

        // фильтр по диапазону дат
        if ($start_ts < $date_start || $start_ts > $date_end) {
            continue;
        }

        // Ключ по дате в UTC (совпадает с датой, которая прилетает из JS new Date().toISOString().split('T')[0])
        $date_key = gmdate('Y-m-d', $start_ts);

        if (!isset($programs[$date_key])) {
            $programs[$date_key] = [];
        }
        if (!isset($programs[$date_key][$channel_id])) {
            $programs[$date_key][$channel_id] = [];
        }

        // Читаем title / desc / category
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/s', $inner, $mTitle)) {
            $title = html_entity_decode(trim($mTitle[1]), ENT_QUOTES, 'UTF-8');
        }

        $desc = '';
        if (preg_match('/<desc[^>]*>(.*?)<\/desc>/s', $inner, $mDesc)) {
            $desc_raw = html_entity_decode(trim($mDesc[1]), ENT_QUOTES, 'UTF-8');
            $desc     = mb_substr($desc_raw, 0, 200);
        }

        $category = '';
        if (preg_match('/<category[^>]*>(.*?)<\/category>/s', $inner, $mCat)) {
            $category = html_entity_decode(trim($mCat[1]), ENT_QUOTES, 'UTF-8');
        }

        // Время для отображения — как в EPG (HH:MM), без учёта таймзоны,
        // чтобы совпадало с официальной программой
        $start_label = '??:??';
        if (preg_match('/^(\d{8})(\d{2})(\d{2})/', $start_str, $mTime)) {
            $hh = $mTime[2];
            $mm = $mTime[3];
            $start_label = $hh . ':' . $mm;
        }

        $programs[$date_key][$channel_id][] = [
            'start'    => $start_label,
            'start_ts' => $start_ts,
            'stop_ts'  => $stop_ts,
            'title'    => $title,
            'desc'     => $desc,
            'category' => $category,
        ];

        $matched_programs++;
    }

    $log[] = ['time' => date('H:i:s'), 'msg' => "✅ Отфильтровано программ: {$matched_programs}"];

    // Сохраняем кэш
    $log[] = ['time' => date('H:i:s'), 'msg' => "Сохраняем в кэш..."];

    $data = [
        'channels' => $channels,
        'programs' => $programs,
        'updated'  => time(),
    ];

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $log[] = ['time' => date('H:i:s'), 'msg' => "❌ Ошибка json_encode", 'error' => true];
        json_exit(['success' => false, 'log' => $log]);
    }

    $res = @file_put_contents($cache_file, $json);
    if ($res === false) {
        $log[] = ['time' => date('H:i:s'), 'msg' => "❌ Ошибка записи кэша (epg_cache.json)", 'error' => true];
        json_exit(['success' => false, 'log' => $log]);
    }

    $cache_size_kb = round(strlen($json) / 1024, 2);
    $log[] = ['time' => date('H:i:s'), 'msg' => "✅ Кэш сохранён: {$cache_size_kb} KB. Каналов: " . count($channels) . ", программ: {$matched_programs}"];
    $log[] = ['time' => date('H:i:s'), 'msg' => "🎉 Готово!"];

    json_exit([
        'success'  => true,
        'log'      => $log,
        'channels' => count($channels),
        'programs' => $matched_programs,
    ]);
}

/**
 * API: получение данных по дате/каналу
 */
if (isset($_GET['action']) && $_GET['action'] === 'get_data') {
    header('Content-Type: application/json; charset=utf-8');

    if (!file_exists($cache_file)) {
        echo json_encode(['success' => false, 'no_cache' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = file_get_contents($cache_file);
    if ($raw === false) {
        echo json_encode(['success' => false, 'no_cache' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'no_cache' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $date    = $_GET['date'] ?? gmdate('Y-m-d');
    $channel = $_GET['channel'] ?? 'all';

    $result = [
        'success'  => true,
        'channels' => $data['channels'] ?? [],
        'programs' => [],
        'updated'  => $data['updated'] ?? null,
    ];

    if (isset($data['programs'][$date])) {
        if ($channel === 'all') {
            $result['programs'] = $data['programs'][$date];
        } elseif (isset($data['programs'][$date][$channel])) {
            $result['programs'][$channel] = $data['programs'][$date][$channel];
        }
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// Информация о кэше для фронтенда
$cache_info = [];
if (file_exists($cache_file)) {
    $age = time() - filemtime($cache_file);
    $cache_info = [
        'exists'      => true,
        'age_seconds' => $age,
        'age_human'   => gmdate('H:i:s', $age),
        'size_kb'     => round(filesize($cache_file) / 1024, 2),
        'valid'       => $age < $cache_time,
    ];
} else {
    $cache_info = ['exists' => false];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>📺 TV Guide — Программа русских каналов на Turksat 42°E</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="description" content="Телепрограмма русскоязычных каналов на спутнике Turksat 42°E. ТНТ, СТС, НТВ, РЕН ТВ, Россия 24 и другие. Программа на 7 дней вперёд.">
    <meta name="keywords" content="turksat 42e, спутниковое тв, программа передач, русские каналы, тнт, стс, нтв, рен тв, россия 24, телепрограмма">
    <meta name="author" content="vanlife.bez.coffee">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://turksat42erus.vanlife.bez.coffee/">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Turksat 42E — Русские каналы">
    <meta property="og:title" content="📺 Программа русскоязычных каналов на спутнике Turksat 42°E">
    <meta property="og:description" content="Телепрограмма русских и международных каналов на спутнике Turksat 42°E. ТНТ, СТС, НТВ, РЕН ТВ, Россия 24. Удобный поиск по датам и каналам.">
    <meta property="og:url" content="https://turksat42erus.vanlife.bez.coffee/">
    <meta property="og:image" content="https://turksat42erus.vanlife.bez.coffee/og-image.jpg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="ru_RU">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="📺 Программа русскоязычных каналов на Turksat 42°E">
    <meta name="twitter:description" content="Актуальная программа передач русских и международных телеканалов на спутнике Turksat 42°E.">
    <meta name="twitter:image" content="https://turksat42erus.vanlife.bez.coffee/og-image.jpg">

    <!-- PWA -->
    <meta name="theme-color" content="#1B3C67">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-title" content="TV RU 42E">
    <link rel="manifest" href="/site.webmanifest">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f0f23;
            --bg-secondary: #1a1a2e;
            --bg-card: #16213e;
            --accent: #e94560;
            --accent-light: #ff6b6b;
            --text-primary: #ffffff;
            --text-secondary: #a0a0b0;
            --border: #2a2a4a;
            --current-bg: rgba(233, 69, 96, 0.15);
            --success: #4ade80;
            --warn: #fbbf24;
            --error: #ef4444;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.5;
        }

        .container { max-width: 1200px; margin: 0 auto; padding: 15px; }

        header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 15px;
            background: linear-gradient(135deg, var(--bg-secondary), var(--bg-card));
            border-bottom: 1px solid var(--border);
        }

        h1 {
            font-size: 1.2rem;
            background: linear-gradient(90deg, var(--accent), var(--accent-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .subtitle { color: var(--text-secondary); font-size: 0.85rem; }

        /* Loading screen */
        .loading-container {
            position: fixed;
            inset: 0;
            background: var(--bg-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        .loading-container.hidden { display: none; }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .loading-title { font-size: 1.2rem; margin-bottom: 15px; }

        .log-console {
            width: 100%;
            max-width: 600px;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 12px;
            overflow: hidden;
            margin-top: 20px;
        }

        .log-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background: #161b22;
            border-bottom: 1px solid #30363d;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .log-header .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        .log-header .dot.red    { background: #ff5f56; }
        .log-header .dot.yellow { background: #ffbd2e; }
        .log-header .dot.green  { background: #27c93f; }

        .log-content {
            padding: 15px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            line-height: 1.8;
        }

        .log-line { display: flex; gap: 10px; }
        .log-time { color: #6e7681; flex-shrink: 0; }
        .log-msg  { color: #c9d1d9; }
        .log-msg.success { color: var(--success); }
        .log-msg.warn    { color: var(--warn); }
        .log-msg.error   { color: var(--error); }

        .log-cursor {
            display: inline-block;
            width: 8px;
            height: 16px;
            background: var(--accent);
            animation: blink 1s infinite;
            vertical-align: middle;
            margin-left: 5px;
        }
        @keyframes blink { 50% { opacity: 0; } }

        .debug-info {
            margin-top: 15px;
            padding: 10px 15px;
            background: var(--bg-secondary);
            border-radius: 8px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        .debug-info strong { color: var(--accent); }

        .btn {
            padding: 12px 24px;
            background: var(--accent);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 15px;
        }
        .btn:hover { background: var(--accent-light); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-secondary {
            background: var(--bg-card);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover { border-color: var(--accent); }

        .main-content { display: none; }
        .main-content.visible { display: block; }

        /* Навигация по датам */
        .date-nav {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 15px 0;
            -webkit-overflow-scrolling: touch;
        }
        .date-btn {
            flex-shrink: 0;
            padding: 10px 14px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            min-width: 70px;
        }
        .date-btn .day { display: block; font-size: 1.1rem; font-weight: 600; }
        .date-btn .weekday { font-size: 0.7rem; opacity: 0.8; }
        .date-btn:hover { border-color: var(--accent); color: var(--text-primary); }
        .date-btn.active { background: var(--accent); border-color: var(--accent); color: white; }

        /* Фильтр каналов */
        .channel-filter {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            padding: 12px;
            background: var(--bg-secondary);
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .filter-btn {
            padding: 8px 12px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-secondary);
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-btn:hover { border-color: var(--accent); color: var(--text-primary); }
        .filter-btn.active { background: var(--accent); border-color: var(--accent); color: white; }

        .channels-grid { display: grid; gap: 20px; }

        .channel-card {
            background: var(--bg-secondary);
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .channel-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
        }

        .channel-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            object-fit: contain;
            background: white;
            padding: 3px;
        }
        .channel-icon-placeholder {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .channel-name { font-weight: 600; }
        .channel-original { font-size: 0.75rem; color: var(--text-secondary); }

        .channel-header-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .time-offset-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            background: var(--bg-primary);
            border-radius: 4px;
            color: var(--text-secondary);
        }
        .time-offset-badge.has-offset { color: var(--accent); }
        .settings-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: var(--bg-primary);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .settings-btn:hover { border-color: var(--accent); color: var(--accent); }

        /* Модальное окно настроек */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s;
        }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            max-width: 320px;
            width: 90%;
        }
        .modal-title { font-weight: 600; margin-bottom: 15px; }
        .modal-subtitle { font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 15px; }
        .offset-selector {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        .offset-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-primary);
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .offset-btn:hover { border-color: var(--accent); }
        .offset-value {
            flex: 1;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--accent);
        }
        .offset-hint { font-size: 0.75rem; color: var(--text-secondary); text-align: center; margin-bottom: 15px; }
        .modal-buttons { display: flex; gap: 10px; }
        .modal-btn {
            flex: 1;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-primary);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        .modal-btn:hover { border-color: var(--accent); }
        .modal-btn.primary { background: var(--accent); border-color: var(--accent); }
        .modal-btn.primary:hover { background: var(--accent-light); }

        .programs-list { padding: 8px; }

        .program-item {
            display: flex;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 6px;
        }
        .program-item:hover { background: rgba(255,255,255,0.03); }
        .program-item.current {
            background: var(--current-bg);
            border-left: 3px solid var(--accent);
        }

        .program-time {
            flex-shrink: 0;
            width: 50px;
            font-weight: 600;
            color: var(--accent);
            font-size: 0.85rem;
        }

        .program-info { flex: 1; }
        .program-title { font-weight: 500; font-size: 0.9rem; }
        .program-item.current .program-title { color: var(--accent-light); }

        .program-desc {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 3px;
        }

        .program-category {
            display: inline-block;
            padding: 2px 6px;
            background: var(--bg-card);
            border-radius: 4px;
            font-size: 0.65rem;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .now-badge {
            display: inline-block;
            padding: 2px 6px;
            background: var(--accent);
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 6px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 50% { opacity: 0.6; } }

        .empty {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        .past-toggle {
            display: inline-block;
            margin: 4px 12px 8px;
            padding: 4px 10px;
            font-size: 0.75rem;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-secondary);
            cursor: pointer;
        }
        .past-toggle:hover {
            border-color: var(--accent);
            color: var(--text-primary);
        }

        .past-programs {
            margin-bottom: 8px;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: var(--text-secondary);
            font-size: 0.8rem;
            border-top: 1px solid var(--border);
            margin-top: 30px;
        }
        footer a { color: var(--accent); text-decoration: none; }

        .update-info { margin-top: 8px; font-size: 0.75rem; }
        .refresh-btn {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            padding: 4px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            margin-left: 8px;
        }
        .refresh-btn:hover { border-color: var(--accent); color: var(--text-primary); }

        .github-btn {
            display: inline-block;
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            margin-left: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .github-btn:hover { border-color: var(--accent); color: var(--text-primary); }

        /* PWA Install Prompt */
        .pwa-install-banner {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-secondary);
            border-top: 1px solid var(--border);
            padding: 12px 15px;
            display: none;
            align-items: center;
            gap: 12px;
            z-index: 1500;
            animation: slideUp 0.3s ease;
        }
        .pwa-install-banner.show { display: flex; }
        @keyframes slideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }
        .pwa-install-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .pwa-install-text { flex: 1; }
        .pwa-install-title { font-weight: 600; font-size: 0.9rem; }
        .pwa-install-desc { font-size: 0.75rem; color: var(--text-secondary); }
        .pwa-install-btn {
            padding: 8px 16px;
            background: var(--accent);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .pwa-install-btn:hover { background: var(--accent-light); }
        .pwa-install-close {
            width: 28px;
            height: 28px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 1.2rem;
            cursor: pointer;
            border-radius: 6px;
        }
        .pwa-install-close:hover { background: var(--bg-card); color: var(--text-primary); }

        /* iOS Install Modal */
        .ios-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        .ios-modal-overlay.active { opacity: 1; visibility: visible; }
        .ios-modal {
            background: var(--bg-secondary);
            border-radius: 16px 16px 0 0;
            padding: 20px;
            width: 100%;
            max-width: 400px;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }
        .ios-modal-overlay.active .ios-modal { transform: translateY(0); }
        .ios-modal-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .ios-modal-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .ios-modal-title { font-weight: 600; font-size: 1.1rem; }
        .ios-modal-steps { margin-bottom: 20px; }
        .ios-step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        .ios-step:last-child { border-bottom: none; }
        .ios-step-num {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--accent);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .ios-step-text { font-size: 0.9rem; }
        .ios-step-icon { font-size: 1.2rem; }
        .ios-modal-close {
            width: 100%;
            padding: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-primary);
            font-weight: 500;
            cursor: pointer;
        }
        .ios-modal-close:hover { border-color: var(--accent); }

        @media (max-width: 500px) {
            .program-item { flex-direction: column; gap: 4px; }
            .program-time { width: auto; }
            .log-content { font-size: 0.7rem; }
        }
    </style>
</head>
<body>
<div class="loading-container" id="loading">
    <div class="spinner" id="spinner"></div>
    <div class="loading-title" id="loading-title">📺 TV Guide</div>

    <div class="log-console">
        <div class="log-header">
            <span class="dot red"></span>
            <span class="dot yellow"></span>
            <span class="dot green"></span>
            <span>Консоль загрузки</span>
        </div>
        <div class="log-content" id="log-content">
            <div class="log-line">
                <span class="log-time">[--:--:--]</span>
                <span class="log-msg">Инициализация...</span>
            </div>
        </div>
    </div>

    <div class="debug-info">
        <strong>Cache:</strong> <span id="cache-status">checking...</span>
    </div>

    <button class="btn" id="start-btn" onclick="startLoading(false)">🚀 Загрузить EPG</button>
</div>

<div class="main-content" id="main-content">
    <header>
        <h1>📺 TV Guide</h1>
        <span class="subtitle">Программа передач</span>
    </header>

    <div class="container">
        <nav class="date-nav" id="date-nav"></nav>
        <div class="channel-filter" id="channel-filter"></div>
        <div class="channels-grid" id="channels-grid"></div>
    </div>

    <footer>
        <p>Данные: <a href="https://iptvx.one/" target="_blank" rel="noopener">iptvx.one</a></p>
        <div class="update-info">
            <span id="update-time"></span>
            <button class="refresh-btn" onclick="forceRefresh()">🔄 Обновить EPG</button>
            <a href="https://github.com/Kopaev/Turksat_42e_RUS" target="_blank" rel="noopener" class="github-btn">⭐ GitHub</a>
        </div>
    </footer>
</div>

<!-- Модальное окно настройки сдвига времени -->
<div class="modal-overlay" id="offset-modal" onclick="closeOffsetModal(event)">
    <div class="modal" onclick="event.stopPropagation()">
        <div class="modal-title">⏱️ Сдвиг времени</div>
        <div class="modal-subtitle" id="modal-channel-name">Канал</div>
        <div class="offset-selector">
            <button class="offset-btn" onclick="changeOffset(-1)">−</button>
            <div class="offset-value" id="offset-value">0ч</div>
            <button class="offset-btn" onclick="changeOffset(1)">+</button>
        </div>
        <div class="offset-hint">
            Если передачи идут раньше — уменьшите.<br>
            Если позже — увеличьте.
        </div>
        <div class="modal-buttons">
            <button class="modal-btn" onclick="resetOffset()">Сбросить</button>
            <button class="modal-btn primary" onclick="saveOffset()">Сохранить</button>
        </div>
    </div>
</div>

<!-- PWA Install Banner (Android/Desktop) -->
<div class="pwa-install-banner" id="pwa-banner">
    <div class="pwa-install-icon">📺</div>
    <div class="pwa-install-text">
        <div class="pwa-install-title">Установить TV RU 42E</div>
        <div class="pwa-install-desc">Добавьте на главный экран для быстрого доступа</div>
    </div>
    <button class="pwa-install-btn" id="pwa-install-btn">Установить</button>
    <button class="pwa-install-close" onclick="closePwaBanner()">×</button>
</div>

<!-- iOS Install Modal -->
<div class="ios-modal-overlay" id="ios-modal" onclick="closeIosModal(event)">
    <div class="ios-modal" onclick="event.stopPropagation()">
        <div class="ios-modal-header">
            <div class="ios-modal-icon">📺</div>
            <div class="ios-modal-title">Установить TV RU 42E</div>
        </div>
        <div class="ios-modal-steps">
            <div class="ios-step">
                <div class="ios-step-num">1</div>
                <div class="ios-step-text">Нажмите кнопку <strong>Поделиться</strong></div>
                <div class="ios-step-icon">⬆️</div>
            </div>
            <div class="ios-step">
                <div class="ios-step-num">2</div>
                <div class="ios-step-text">Пролистайте вниз и нажмите <strong>«На экран Домой»</strong></div>
                <div class="ios-step-icon">➕</div>
            </div>
            <div class="ios-step">
                <div class="ios-step-num">3</div>
                <div class="ios-step-text">Нажмите <strong>«Добавить»</strong></div>
                <div class="ios-step-icon">✓</div>
            </div>
        </div>
        <button class="ios-modal-close" onclick="closeIosModal()">Понятно</button>
    </div>
</div>

<script>
const weekdays = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

let currentDate    = new Date().toISOString().split('T')[0];
let currentChannel = 'all';
let channelsData   = {};

// Сдвиги времени для каналов (в часах)
let channelOffsets = {};
const OFFSETS_STORAGE_KEY = 'tv_guide_channel_offsets';

// Текущий редактируемый канал
let editingChannelId = null;
let editingOffset = 0;

// Загрузка сохранённых сдвигов из localStorage
function loadOffsets() {
    try {
        const saved = localStorage.getItem(OFFSETS_STORAGE_KEY);
        if (saved) {
            channelOffsets = JSON.parse(saved);
        }
    } catch (e) {
        console.error('Ошибка загрузки сдвигов:', e);
    }
}

// Сохранение сдвигов в localStorage
function saveOffsets() {
    try {
        localStorage.setItem(OFFSETS_STORAGE_KEY, JSON.stringify(channelOffsets));
    } catch (e) {
        console.error('Ошибка сохранения сдвигов:', e);
    }
}

// Получить сдвиг для канала (в секундах)
function getOffsetSeconds(channelId) {
    return (channelOffsets[channelId] || 0) * 3600;
}

// Открыть модальное окно настройки сдвига
function openOffsetModal(channelId, channelName) {
    editingChannelId = channelId;
    editingOffset = channelOffsets[channelId] || 0;
    
    document.getElementById('modal-channel-name').textContent = channelName;
    updateOffsetDisplay();
    document.getElementById('offset-modal').classList.add('active');
}

// Закрыть модальное окно
function closeOffsetModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('offset-modal').classList.remove('active');
    editingChannelId = null;
}

// Изменить сдвиг
function changeOffset(delta) {
    editingOffset = Math.max(-12, Math.min(12, editingOffset + delta));
    updateOffsetDisplay();
}

// Обновить отображение сдвига
function updateOffsetDisplay() {
    const sign = editingOffset > 0 ? '+' : '';
    document.getElementById('offset-value').textContent = sign + editingOffset + 'ч';
}

// Сбросить сдвиг
function resetOffset() {
    editingOffset = 0;
    updateOffsetDisplay();
}

// Сохранить сдвиг
function saveOffset() {
    if (editingChannelId) {
        if (editingOffset === 0) {
            delete channelOffsets[editingChannelId];
        } else {
            channelOffsets[editingChannelId] = editingOffset;
        }
        saveOffsets();
        loadData(); // Перерендерить программу
    }
    closeOffsetModal();
}

// Применить сдвиг к времени (возвращает новую строку времени HH:MM)
function applyOffsetToTime(timeStr, offsetHours) {
    if (!timeStr || offsetHours === 0) return timeStr;
    
    const [hours, minutes] = timeStr.split(':').map(Number);
    let newHours = hours + offsetHours;
    
    // Нормализация часов (0-23)
    while (newHours < 0) newHours += 24;
    while (newHours >= 24) newHours -= 24;
    
    return String(newHours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
}

const cacheInfo = <?= json_encode($cache_info, JSON_UNESCAPED_UNICODE) ?>;

document.addEventListener('DOMContentLoaded', () => {
    loadOffsets(); // Загружаем сохранённые сдвиги
    addLog('Проверяем состояние кэша...');

    const cacheStatusEl = document.getElementById('cache-status');
    const startBtn      = document.getElementById('start-btn');

    if (cacheInfo.exists) {
        cacheStatusEl.innerHTML =
            `<span style="color: var(--success)">✓ Существует</span> | ` +
            `Возраст: ${cacheInfo.age_human} | ` +
            `Размер: ${cacheInfo.size_kb} KB | ` +
            `Валидный: ${cacheInfo.valid ? '✓' : '✗'}`;

        if (cacheInfo.valid) {
            addLog('✅ Найден валидный кэш! Загружаем программу…', 'success');
            startBtn.style.display = 'none';
            setTimeout(showMainContent, 500);
        } else {
            addLog('⚠️ Кэш устарел, запускаем обновление...', 'warn');
            startLoading(true);
        }
    } else {
        cacheStatusEl.innerHTML = `<span style="color: var(--error)">✗ Не найден</span>`;
        addLog('⚠️ Кэш не найден, запускаем загрузку EPG...', 'warn');
        startLoading(true);
    }
});

function addLog(msg, type = '') {
    const logContent = document.getElementById('log-content');
    const time = new Date().toLocaleTimeString('ru-RU');

    // убрать старый курсор
    logContent.querySelectorAll('.log-cursor').forEach(c => c.remove());

    const line = document.createElement('div');
    line.className = 'log-line';
    line.innerHTML = `
        <span class="log-time">[${time}]</span>
        <span class="log-msg ${type}">${msg}<span class="log-cursor"></span></span>
    `;
    logContent.appendChild(line);
    logContent.scrollTop = logContent.scrollHeight;
}

async function startLoading(auto = false) {
    const btn = document.getElementById('start-btn');
    btn.disabled = true;

    addLog('Запускаем загрузку EPG с iptvx.one...');
    addLog('Это может занять 1-2 минуты, не закрывайте страницу!', 'warn');

    try {
        const response = await fetch('?action=update_cache');
        const text     = await response.text();

        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            addLog('❌ Ошибка: сервер вернул невалидный JSON', 'error');
            addLog('Фрагмент ответа сервера:', 'error');
            addLog('...', 'error');
            if (!auto) {
                btn.disabled = false;
                btn.textContent = '🔄 Попробовать снова';
            }
            return;
        }

        if (result.log && Array.isArray(result.log)) {
            result.log.forEach(item => {
                let type = '';
                if (item.error) type = 'error';
                else if (item.warn) type = 'warn';
                else if (item.msg.includes('✅') || item.msg.includes('🎉')) type = 'success';
                addLog(item.msg, type);
            });
        }

        if (result.success) {
            addLog('Переходим к программе передач...', 'success');
            setTimeout(showMainContent, 1000);
        } else {
            btn.disabled = false;
            btn.textContent = '🔄 Попробовать снова';
        }
    } catch (e) {
        addLog('❌ Ошибка сети: ' + e.message, 'error');
        btn.disabled = false;
        btn.textContent = '🔄 Попробовать снова';
    }
}

function showMainContent() {
    document.getElementById('loading').classList.add('hidden');
    document.getElementById('main-content').classList.add('visible');
    renderDates();
    loadData();
    initPwa(); // Инициализация PWA промптов
}

async function loadData() {
    try {
        const response = await fetch(`?action=get_data&date=${currentDate}&channel=${currentChannel}`);
        const data = await response.json();

        if (!data.success) {
            document.getElementById('channels-grid').innerHTML =
                '<div class="empty">😔 Нет данных. Попробуйте обновить EPG.</div>';
            return;
        }

        channelsData = data.channels || {};
        renderChannelFilter();
        renderPrograms(data.programs || {});

        if (data.updated) {
            const updateDate = new Date(data.updated * 1000);
            document.getElementById('update-time').textContent =
                'Обновлено: ' + updateDate.toLocaleString('ru-RU');
        }
    } catch (e) {
        console.error(e);
    }
}

function renderDates() {
    const nav = document.getElementById('date-nav');
    nav.innerHTML = '';

    for (let i = -1; i <= 7; i++) {
        const d = new Date();
        d.setDate(d.getDate() + i);
        const dateStr = d.toISOString().split('T')[0];
        const isToday = (i === 0);

        const btn = document.createElement('button');
        btn.className = 'date-btn' + (dateStr === currentDate ? ' active' : '');
        btn.innerHTML = `
            <span class="day">${d.getDate()}</span>
            <span class="weekday">${isToday ? 'Сегодня' : weekdays[d.getDay()]}</span>
        `;
        btn.onclick = () => {
            currentDate = dateStr;
            renderDates();
            loadData();
        };
        nav.appendChild(btn);
    }
}

function renderChannelFilter() {
    const filter = document.getElementById('channel-filter');
    filter.innerHTML = '';

    const allBtn = document.createElement('button');
    allBtn.className = 'filter-btn' + (currentChannel === 'all' ? ' active' : '');
    allBtn.textContent = 'Все';
    allBtn.onclick = () => {
        currentChannel = 'all';
        renderChannelFilter();
        loadData();
    };
    filter.appendChild(allBtn);

    Object.values(channelsData).forEach(ch => {
        const btn = document.createElement('button');
        btn.className = 'filter-btn' + (currentChannel === ch.id ? ' active' : '');
        btn.textContent = ch.display_name;
        btn.onclick = () => {
            currentChannel = ch.id;
            renderChannelFilter();
            loadData();
        };
        filter.appendChild(btn);
    });
}

function renderPrograms(programs) {
    const grid = document.getElementById('channels-grid');
    grid.innerHTML = '';

    const now = Math.floor(Date.now() / 1000);

    const channelIds = Object.keys(programs);
    if (!channelIds.length) {
        grid.innerHTML = '<div class="empty">😔 Нет программ на выбранную дату</div>';
        return;
    }

    channelIds.forEach(channelId => {
        const ch = channelsData[channelId];
        if (!ch) return;

        const progs = programs[channelId] || [];
        if (!progs.length) return;

        // Получаем сдвиг для этого канала
        const offsetHours = channelOffsets[channelId] || 0;
        const offsetSeconds = offsetHours * 3600;

        const past   = [];
        const future = [];

        progs.forEach(p => {
            // Применяем сдвиг к временным меткам для определения текущей программы
            const adjustedStart = p.start_ts + offsetSeconds;
            const adjustedStop = (p.stop_ts || p.start_ts) + offsetSeconds;
            
            if (adjustedStop < now) past.push(p);
            else future.push(p);
        });

        let iconHtml = '';
        if (ch.icon) {
            iconHtml = `<img src="${ch.icon}" class="channel-icon" onerror="this.style.display='none'">`;
        } else {
            const firstLetter = ch.display_name ? ch.display_name.charAt(0) : '?';
            iconHtml = `<div class="channel-icon-placeholder">${escapeHtml(firstLetter)}</div>`;
        }

        // Бейдж со сдвигом и кнопка настроек
        const offsetSign = offsetHours > 0 ? '+' : '';
        const offsetBadge = offsetHours !== 0 
            ? `<span class="time-offset-badge has-offset">${offsetSign}${offsetHours}ч</span>`
            : '';
        
        const settingsBtn = `<button class="settings-btn" onclick="openOffsetModal('${channelId}', '${escapeHtml(ch.display_name)}')" title="Настроить сдвиг времени">⚙️</button>`;

        let pastHtml = '';
        if (past.length) {
            pastHtml += `<button class="past-toggle" onclick="togglePast('${channelId}')">Прошедшие сеансы (${past.length})</button>`;
            pastHtml += `<div class="past-programs" id="past-${channelId}" style="display:none;">`;
            past.forEach(p => {
                pastHtml += renderProgramItem(p, now, true, offsetHours);
            });
            pastHtml += `</div>`;
        }

        let futureHtml = '';
        future.forEach(p => {
            futureHtml += renderProgramItem(p, now, false, offsetHours);
        });

        const card = document.createElement('div');
        card.className = 'channel-card';
        card.innerHTML = `
            <div class="channel-header">
                ${iconHtml}
                <div>
                    <div class="channel-name">${escapeHtml(ch.display_name)}</div>
                    <div class="channel-original">${escapeHtml(ch.name)}</div>
                </div>
                <div class="channel-header-right">
                    ${offsetBadge}
                    ${settingsBtn}
                </div>
            </div>
            <div class="programs-list">
                ${pastHtml}
                ${futureHtml}
            </div>
        `;
        grid.appendChild(card);
    });
}

function renderProgramItem(p, now, isPast, offsetHours = 0) {
    const offsetSeconds = offsetHours * 3600;
    
    // Применяем сдвиг к отображаемому времени
    const displayTime = applyOffsetToTime(p.start || '', offsetHours);
    
    // Применяем сдвиг к временным меткам для определения "СЕЙЧАС"
    const adjustedStart = p.start_ts + offsetSeconds;
    const adjustedStop = p.stop_ts + offsetSeconds;
    const isCurrent = !isPast && now >= adjustedStart && now < adjustedStop;

    return `
        <div class="program-item ${isCurrent ? 'current' : ''}">
            <div class="program-time">${escapeHtml(displayTime)}</div>
            <div class="program-info">
                <div class="program-title">
                    ${escapeHtml(p.title || '')}
                    ${isCurrent ? '<span class="now-badge">СЕЙЧАС</span>' : ''}
                </div>
                ${p.desc ? `<div class="program-desc">${escapeHtml(p.desc)}</div>` : ''}
                ${p.category ? `<span class="program-category">${escapeHtml(p.category)}</span>` : ''}
            </div>
        </div>
    `;
}

function togglePast(channelId) {
    const block = document.getElementById(`past-${channelId}`);
    if (!block) return;
    block.style.display = (block.style.display === 'none' || block.style.display === '') ? 'block' : 'none';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function forceRefresh() {
    // Показать снова загрузочный экран и запустить обновление
    document.getElementById('main-content').classList.remove('visible');
    document.getElementById('loading').classList.remove('hidden');
    const btn = document.getElementById('start-btn');
    btn.style.display = 'inline-block';
    btn.disabled = false;
    btn.textContent = '🚀 Загрузить EPG';
    addLog('--- Принудительное обновление ---');
    startLoading(false);
}

// ==================== PWA Install ====================

let deferredPrompt = null;
const PWA_DISMISSED_KEY = 'tv_guide_pwa_dismissed';
const PWA_INSTALLED_KEY = 'tv_guide_pwa_installed';

// Проверка iOS
function isIos() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}

// Проверка standalone режима (уже установлено)
function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || 
           window.navigator.standalone === true;
}

// Проверка, что баннер был закрыт недавно (24 часа)
function wasDismissedRecently() {
    const dismissed = localStorage.getItem(PWA_DISMISSED_KEY);
    if (!dismissed) return false;
    const dismissedTime = parseInt(dismissed, 10);
    const hoursSinceDismissed = (Date.now() - dismissedTime) / (1000 * 60 * 60);
    return hoursSinceDismissed < 24;
}

// Показать баннер установки (Android/Desktop)
function showPwaBanner() {
    if (isStandalone() || wasDismissedRecently()) return;
    document.getElementById('pwa-banner').classList.add('show');
}

// Закрыть баннер
function closePwaBanner() {
    document.getElementById('pwa-banner').classList.remove('show');
    localStorage.setItem(PWA_DISMISSED_KEY, Date.now().toString());
}

// Показать iOS модалку
function showIosModal() {
    if (isStandalone() || wasDismissedRecently()) return;
    document.getElementById('ios-modal').classList.add('active');
}

// Закрыть iOS модалку
function closeIosModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('ios-modal').classList.remove('active');
    localStorage.setItem(PWA_DISMISSED_KEY, Date.now().toString());
}

// Обработка события beforeinstallprompt (Android/Desktop Chrome)
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    
    // Показываем баннер через 3 секунды после загрузки
    setTimeout(showPwaBanner, 3000);
});

// Кнопка установки
document.getElementById('pwa-install-btn')?.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    
    if (outcome === 'accepted') {
        localStorage.setItem(PWA_INSTALLED_KEY, 'true');
    }
    
    deferredPrompt = null;
    closePwaBanner();
});

// Обработка успешной установки
window.addEventListener('appinstalled', () => {
    localStorage.setItem(PWA_INSTALLED_KEY, 'true');
    closePwaBanner();
});

// Показ iOS инструкции
function initIosPrompt() {
    if (!isIos() || isStandalone() || wasDismissedRecently()) return;
    
    // Показываем через 5 секунд
    setTimeout(showIosModal, 5000);
}

// Инициализация PWA при загрузке главного контента
function initPwa() {
    if (isIos()) {
        initIosPrompt();
    }
}

// Регистрация Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then((registration) => {
                console.log('SW registered:', registration.scope);
            })
            .catch((error) => {
                console.log('SW registration failed:', error);
            });
    });
}
</script>
</body>
</html>
