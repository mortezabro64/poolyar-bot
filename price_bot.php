<?php

date_default_timezone_set('Asia/Tehran');

$BALE_BOT_TOKEN = getenv('BALE_BOT_TOKEN');
$BALE_CHAT_ID   = getenv('BALE_CHAT_ID');

if (!$BALE_BOT_TOKEN || !$BALE_CHAT_ID) {
    die("ERROR: GitHub Secrets are missing.\n");
}

/* =========================
   HTTP
========================= */

function getUrl($url)
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (PoolYar Market Bot)'
    ]);

    $data = curl_exec($ch);

    if ($data === false) {
        $error = curl_error($ch);
        curl_close($ch);
        die("CURL ERROR: " . $error . "\n");
    }

    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http < 200 || $http >= 300) {
        die("HTTP ERROR $http for $url\n");
    }

    return $data;
}

function getJson($url)
{
    $data = getUrl($url);

    $json = json_decode($data, true);

    if (!is_array($json)) {
        die("JSON ERROR for $url\n");
    }

    return $json;
}

/* =========================
   Persian numbers
========================= */

function fa($text)
{
    return strtr((string)$text, [
        '0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴',
        '5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'
    ]);
}

/* =========================
   Format numbers
========================= */

function money($number)
{
    return number_format((float)$number, 0, '.', ',');
}

function cryptoPrice($number)
{
    $number = (float)$number;

    if ($number >= 1000) {
        return number_format($number, 0, '.', ',');
    }

    if ($number >= 1) {
        return number_format($number, 2, '.', '');
    }

    return number_format($number, 4, '.', '');
}

/* =========================
   Gregorian -> Jalali
========================= */

function gregorianToJalali($gy, $gm, $gd)
{
    $g_d_m = [
        0,31,59,90,120,151,181,212,243,273,304,334
    ];

    if ($gy > 1600) {
        $jy = 979;
        $gy -= 1600;
    } else {
        $jy = 0;
        $gy -= 621;
    }

    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;

    $days = (365 * $gy)
          + floor(($gy2 + 3) / 4)
          - floor(($gy2 + 99) / 100)
          + floor(($gy2 + 399) / 400)
          - 80
          + $gd
          + $g_d_m[$gm - 1];

    $jy += 33 * floor($days / 12053);
    $days %= 12053;

    $jy += 4 * floor($days / 1461);
    $days %= 1461;

    if ($days > 365) {
        $jy += floor(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }

    if ($days < 186) {
        $jm = 1 + floor($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + floor(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }

    return [$jy, $jm, $jd];
}

/* =========================
   Current Tehran date/time
========================= */

$now = new DateTime('now', new DateTimeZone('Asia/Tehran'));

$hour   = (int)$now->format('H');
$minute = (int)$now->format('i');

list($jy, $jm, $jd) = gregorianToJalali(
    (int)$now->format('Y'),
    (int)$now->format('m'),
    (int)$now->format('d')
);

$jalaliMonths = [
    1 => 'فروردین',
    2 => 'اردیبهشت',
    3 => 'خرداد',
    4 => 'تیر',
    5 => 'مرداد',
    6 => 'شهریور',
    7 => 'مهر',
    8 => 'آبان',
    9 => 'آذر',
    10 => 'دی',
    11 => 'بهمن',
    12 => 'اسفند'
];

$timeText = fa(sprintf('%02d:%02d', $hour, $minute));
$dateText = fa($jd) . ' ' . $jalaliMonths[$jm] . ' ' . fa($jy);

/* =========================
   Crypto - CoinGecko
========================= */

$cryptoUrl =
    'https://api.coingecko.com/api/v3/simple/price' .
    '?ids=bitcoin,ethereum,binancecoin,solana,dogecoin,ripple' .
    '&vs_currencies=usd';

$crypto = getJson($cryptoUrl);

$btc  = $crypto['bitcoin']['usd'] ?? 0;
$eth  = $crypto['ethereum']['usd'] ?? 0;
$bnb  = $crypto['binancecoin']['usd'] ?? 0;
$sol  = $crypto['solana']['usd'] ?? 0;
$doge = $crypto['dogecoin']['usd'] ?? 0;
$xrp  = $crypto['ripple']['usd'] ?? 0;

if (!$btc || !$eth || !$bnb || !$sol || !$doge || !$xrp) {
    die("ERROR: Crypto prices incomplete.\n");
}

/* =========================
   Dollar - TGJU proxy
========================= */

$currencyUrl =
    'https://tgju-api-go.onrender.com/api/price/currency';

$currency = getJson($currencyUrl);

$dollarRial = null;

if (isset($currency['data']) && is_array($currency['data'])) {
    foreach ($currency['data'] as $item) {
        if (
            isset($item['key']) &&
            $item['key'] === 'price_dollar_rl'
        ) {
            $dollarRial = $item['price'] ?? null;
            break;
        }
    }
}

if ($dollarRial === null) {
    foreach ($currency as $item) {
        if (
            is_array($item) &&
            isset($item['key']) &&
            $item['key'] === 'price_dollar_rl'
        ) {
            $dollarRial = $item['price'] ?? null;
            break;
        }
    }
}

if ($dollarRial === null) {
    die("ERROR: Dollar price not found.\n");
}

$dollarToman = (float)str_replace(',', '', $dollarRial) / 10;

/* =========================
   Gold 18K - TGJU
========================= */

$goldHtml = getUrl(
    'https://www.tgju.org/profile/geram18'
);

$goldRial = null;

/*
   Current TGJU page contains:
   نرخ فعلی: 241,246,000
*/

if (preg_match(
    '/نرخ فعلی.{0,100}?([0-9,]{6,})/u',
    $goldHtml,
    $match
)) {
    $goldRial = str_replace(',', '', $match[1]);
}

if ($goldRial === null) {

    if (preg_match(
        '/نرخ فعلی[^0-9]*([0-9,]{6,})/u',
        $goldHtml,
        $match
    )) {
        $goldRial = str_replace(',', '', $match[1]);
    }
}

if ($goldRial === null) {
    die("ERROR: 18K gold price not found.\n");
}

$gold18Toman = (float)$goldRial / 10;

/* =========================
   World Gold - GoldPrice.dev
========================= */

$worldGoldUrl =
    'https://api.goldprice.dev/v1/prices?symbol=XAU-USD-SPOT';

$worldGold = getJson($worldGoldUrl);

$goldWorld = null;

if (
    isset($worldGold['symbols'][0]['price'])
) {
    $goldWorld = (float)$worldGold['symbols'][0]['price'];
}

if ($goldWorld === null) {
    die("ERROR: World gold price not found.\n");
}

/* =========================
   Message
========================= */

$message =
"📌 #قیمت_لحظه‌ای بازار
🕒 {$timeText} | {$dateText}

🔸 BTC: " . cryptoPrice($btc) . "$
🔸 ETH: " . cryptoPrice($eth) . "$
🔸 BNB: " . cryptoPrice($bnb) . "$
🔹 SOL: " . cryptoPrice($sol) . "$
🔹 DOGE: " . cryptoPrice($doge) . "$
🔹 XRP: " . cryptoPrice($xrp) . "$

🔹 دلار: " . money($dollarToman) . " تومان
🔸 طلای ۱۸ عیار: " . money($gold18Toman) . " تومان
🔸 اونس جهانی طلا: " . number_format($goldWorld, 0, '.', ',') . "$


روزانه قیمت طلا،دلار،ارز و ارز دیجیتال | روشهای واقعی درآمد اینترنتی | تخفیف ها و فرصت های ویژه | اخبار مهم و فوری
⚡️با ما بروز باش، جلوتر باش!

🆔 شناسه:
https://ble.ir/poolyar_channel";

/* =========================
   Send to Bale
========================= */

$sendUrl =
    'https://tapi.bale.ai/bot' .
    $BALE_BOT_TOKEN .
    '/sendMessage';

$postData = [
    'chat_id' => $BALE_CHAT_ID,
    'text'    => $message
];

$ch = curl_init($sendUrl);

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 40,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'PoolYar Market Bot'
]);

$response = curl_exec($ch);

if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);
    die("BALE CURL ERROR: $error\n");
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if (
    $httpCode < 200 ||
    $httpCode >= 300 ||
    !isset($result['ok']) ||
    !$result['ok']
) {
    echo "BALE ERROR\n";
    echo "HTTP: $httpCode\n";
    echo $response . "\n";
    exit(1);
}

echo "====================================\n";
echo "PoolYar message sent successfully!\n";
echo "Time: $timeText\n";
echo "Dollar: " . money($dollarToman) . " Toman\n";
echo "Gold 18K: " . money($gold18Toman) . " Toman\n";
echo "World Gold: " . number_format($goldWorld, 0) . "$\n";
echo "====================================\n";
