<?php
/**
 * send_order.php
 * 注文フォーム／業務相談フォームの送信を受け取り、担当者へメール通知します。
 *
 * このスクリプトは個人情報をサーバーにファイル保存・ログ保存しません(メール送信のみ)。
 * 販売分析(注文履歴の一覧化)が必要になった場合は、別途データ保存の仕組みを検討してください。
 */

// 本番利用者にPHPの内部エラー・パス等を表示しない(サーバー既定値に依存させない)。
// エラー自体はサーバーの標準エラーログには記録され続ける(error_reporting(0)にはしない)。
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$TO_EMAIL = 'info@olibehouse2019.com';
// 送信元アドレスは実際に使用するドメイン(yanekouji-wagaraku.com)に合わせています。
// このアドレス宛に返信が届くわけではないため、実在するアドレスである必要は基本的にありませんが、
// レンタルサーバーによっては「ドメインが一致しないと送信できない」設定の場合があるため、
// 必ずサイトと同じドメイン(@yanekouji-wagaraku.com)のアドレスにしてください。
$FROM_EMAIL = 'noreply@yanekouji-wagaraku.com';
$SITE_NAME = '株式会社オリーブハウス OEM洗剤販売ページ';

// 正式に存在する商品・セットのみを受け付けるホワイトリスト(名称 => 価格・税込円)。
// ブラウザから送られてきた商品名がこの一覧に完全一致しない場合は改ざんとみなし除外する。
// 価格もこの一覧の値のみを信用し、送信データ側の価格表記は一切信用しない。
$PRODUCT_WHITELIST = [
    '一撃スカッと！(400ml) ¥1,650' => 1650,
    'カビ取り職人(200ml) ¥2,750' => 2750,
    'ヨゴレするん♪(100g) ¥1,650' => 1650,
    'ニオワンダー(100ml) ¥1,320' => 1320,
    'お風呂セット(一撃スカッと！+カビ取り職人) ¥4,400' => 4400,
    '4商品セット(全種類) ¥7,370' => 7370,
];

// 通常注文で選択できる受取方法のホワイトリスト
$DELIVERY_METHODS = ['発送希望', '岡山県内で手渡し希望', '相談したい'];

const MIN_QTY = 1;
const MAX_QTY = 20;

header('Content-Type: application/json; charset=utf-8');

function fail($code, $error){
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

function clean_text($v, $maxlen = 500){
    if(!is_string($v)) return '';
    $v = trim($v);
    $v = str_replace(["\r"], '', $v);
    // ヘッダーインジェクション対策(改行を含む値を件名等に使わない前提だが念のため除去)
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    if(mb_strlen($v) > $maxlen) $v = mb_substr($v, 0, $maxlen);
    return $v;
}

try {

    // POST以外は拒否
    if($_SERVER['REQUEST_METHOD'] !== 'POST'){
        fail(405, 'method_not_allowed');
    }

    // 簡易レートリミット(同一セッションからの短時間連続送信を防ぐ二重送信対策)
    session_start();
    $now = time();
    if(isset($_SESSION['last_submit']) && ($now - $_SESSION['last_submit']) < 10){
        fail(429, 'too_many_requests');
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if(!is_array($data)){
        fail(400, 'invalid_payload');
    }

    // CSRF対策(Double Submit Cookie方式の検証)
    // フロント側(index.html)がページ読込時に生成したトークンをCookieと送信データの両方に含めており、
    // 他サイトからの自動送信ではCookieを読み取れず正しいトークンを送れないため、これが一致しない場合は拒否する。
    $cookieToken = $_COOKIE['csrf_token'] ?? '';
    $bodyToken = is_string($data['csrf_token'] ?? null) ? $data['csrf_token'] : '';
    if($cookieToken === '' || $bodyToken === '' || !hash_equals($cookieToken, $bodyToken)){
        fail(403, 'csrf_failed');
    }

    // honeypot判定(BOT対策)
    // 通常利用者には見えない項目のため、値が入っていれば機械的な送信とみなす。
    // ボットに検知を悟られないよう、成功を装いつつメールは送信しない。
    $honeypot = $data['hp_website'] ?? '';
    if(is_string($honeypot) && $honeypot !== ''){
        echo json_encode(['ok' => true]);
        exit;
    }

    // 送信前最低3秒(BOT対策)
    // ページ読込時にJS側が発行したCookie(page_loaded_at)から経過時間を計算する。
    // Cookie自体が無い/不正な場合も含め、3秒未満での送信はBOT疑いとして拒否する。
    $pageLoadedAtCookie = $_COOKIE['page_loaded_at'] ?? '';
    $pageLoadedAtMs = is_numeric($pageLoadedAtCookie) ? (float) $pageLoadedAtCookie : 0;
    $nowMs = microtime(true) * 1000;
    if($pageLoadedAtMs <= 0 || ($nowMs - $pageLoadedAtMs) < 3000){
        fail(429, 'too_fast');
    }

    $type    = ($data['type'] ?? '') === 'business_inquiry' ? 'business_inquiry' : 'order';
    $name    = clean_text($data['name'] ?? '', 100);
    $company = clean_text($data['company'] ?? '', 100);
    $contact = clean_text($data['contact'] ?? '', 200);
    $industry= clean_text($data['industry'] ?? '', 50);
    $note    = clean_text($data['note'] ?? '', 1000);
    $largeSizeInterest = !empty($data['large_size_interest']);
    $deliveryMethod = clean_text($data['delivery_method'] ?? '', 50);
    $itemsRaw = is_array($data['items'] ?? null) ? $data['items'] : [];

    // 必須項目チェック
    $contactValid = (bool) preg_match('/^[0-9\-+() ]{6,}$/', $contact)
        || (bool) filter_var($contact, FILTER_VALIDATE_EMAIL);

    if($name === '' || !$contactValid){
        fail(422, 'validation_failed');
    }

    // 受取方法は通常注文のみ必須(業者問い合わせ等は任意)
    if($type === 'order' && !in_array($deliveryMethod, $DELIVERY_METHODS, true)){
        fail(422, 'validation_failed');
    }
    if($type !== 'order' && !in_array($deliveryMethod, $DELIVERY_METHODS, true)){
        $deliveryMethod = '';
    }

    // 商品ホワイトリスト検証 + 数量1〜20クランプ(ブラウザ側の価格表記は一切信用しない)
    $items = [];
    foreach($itemsRaw as $item){
        $iname = is_string($item['name'] ?? null) ? $item['name'] : '';
        if(!array_key_exists($iname, $PRODUCT_WHITELIST)) continue;
        $qtyRaw = isset($item['qty']) ? intval($item['qty']) : 1;
        $qty = max(MIN_QTY, min(MAX_QTY, $qtyRaw));
        $items[] = ['name' => $iname, 'qty' => $qty, 'price' => $PRODUCT_WHITELIST[$iname]];
    }
    // 送信された商品がすべてホワイトリスト外(改ざん試行の可能性)だった場合は拒否する
    if(!empty($itemsRaw) && empty($items)){
        fail(422, 'validation_failed');
    }

    // 件名
    $subject = $type === 'business_inquiry'
        ? '【業務利用相談】' . $SITE_NAME
        : '【新規ご注文】' . $SITE_NAME;
    $subject = mb_encode_mimeheader($subject, 'UTF-8');

    // 本文
    $lines = [];
    $lines[] = $type === 'business_inquiry' ? '■ 種別: 業務利用のご相談' : '■ 種別: 商品注文';
    $lines[] = '';
    $lines[] = '■ お名前: ' . $name;
    $lines[] = '■ 会社名: ' . ($company !== '' ? $company : '(未入力)');
    $lines[] = '■ 連絡先: ' . $contact;
    $lines[] = '■ 業種: ' . ($industry !== '' ? $industry : '(未選択)');
    $lines[] = '■ 受取方法: ' . ($deliveryMethod !== '' ? $deliveryMethod : '(未選択)');
    $lines[] = '■ 大容量サイズへの興味: ' . ($largeSizeInterest ? 'あり' : 'なし');
    $lines[] = '';

    if(!empty($items)){
        $lines[] = '■ ご注文商品:';
        foreach($items as $item){
            $lines[] = '  - ' . $item['name'] . ' × ' . $item['qty'];
        }
        $lines[] = '';
    }

    $lines[] = '■ 備考:';
    $lines[] = ($note !== '' ? $note : '(なし)');
    $lines[] = '';
    $lines[] = '送信日時: ' . date('Y-m-d H:i:s');

    $body = implode("\n", $lines);

    $headers = [];
    $headers[] = 'From: ' . $SITE_NAME . ' <' . $FROM_EMAIL . '>';
    // 返信は問い合わせ者の連絡先がメール形式の場合のみReply-Toに設定
    if(filter_var($contact, FILTER_VALIDATE_EMAIL)){
        $headers[] = 'Reply-To: ' . $contact;
    }
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    $sent = mail($TO_EMAIL, $subject, $body, implode("\r\n", $headers));

    if(!$sent){
        fail(500, 'mail_failed');
    }

    $_SESSION['last_submit'] = $now;

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    // 内部エラーの詳細(メッセージ・パス等)は利用者に一切返さない
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit;
}
