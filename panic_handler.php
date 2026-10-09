<?php
/*****************************************
  *----------------------------------
  |  ThisScriptVersion: 1.0.0     |
  |  © 2026 By PusyuuWanko/       |
  |  License: MIT License         |
  |  panic_handler (共有ユーティリティ) |
----------------------------------*
  サービス全体(main/toolbox/p-chat等の各独立サイト)向けの共通「パンク画面」。
  想定外の致命的エラー(uncaughtな例外・PHPのFatal Error)が起きたときに、
  スタックトレースやファイルパスなど内部情報を訪問者に見せず、代わりに
  ブランドに沿った案内画面(または、JSON APIへのリクエストならJSON形式の
  エラー応答)を返す。詳細はサーバー側のログにのみ記録する。

  【使い方】
  各プロダクトの入口(index.php等)やAPIエンドポイントの先頭で1行requireする
  だけで有効になる(このファイル自身が読み込み時に自動でインストールする。
  他の共有スクリプトと同様、呼び出し元の階層に依存しないよう__DIR__基準):

    require_once __DIR__ . '/pusyuusystem/scripts/php_scripts/panic_handler.php';

  プロダクト名はデフォルトで$_SERVER['DOCUMENT_ROOT']のディレクトリ名から
  推測するが、表示用のわかりやすい名前にしたい場合は明示的に指定できる:

    pusyuuSetPanicProductName('プシューメール');

  【検討した類似機能・既存実装との違い】
  uwuzu(uwuzu/errorpage/httperror.php・overcapacity.php・serverstop.php)には
  同種の「案内画面」が存在するが、
    - httperror.phpは.htaccessのErrorDocumentでHTTPステータスコード単位に
      紐付くだけで、PHPの未捕捉例外/Fatal Error自体を拾う仕組みではない
      (Fatal Errorでも大抵はステータス500になるため間接的には機能するが、
      buffered出力が既に始まっているケースなどには対応できない)。
    - overcapacity.phpはロードアベレージ超過時にuwuzuUserLogin()内の
      個別の分岐からinclude_onceで手動的に呼ばれているだけで、汎用的な
      グローバルハンドラにはなっていない(呼び忘れがあり得る)。
    - どちらも実行時エラーの詳細をサーバー側ログへ構造化して残す仕組みは
      持っていない。
  このファイルはこれらを踏まえ、
    (1) set_exception_handler/set_error_handler/register_shutdown_function
        で「呼び忘れ」が起きないグローバルな安全網にする
    (2) 起きたエラーは常にJSONL形式でログに残し、画面には識別コードのみ
        見せる(詳細はログを追って調査できるようにする)
    (3) JSON APIへのリクエストかどうかを判定し、HTML画面ではなく
        JSON形式のエラー応答を返す(既存のfetch/XHR呼び出し側の
        JSON.parseを壊さないため)
    という3点を主な差分として設計している。

  【致命的でないエラー(E_WARNING/E_NOTICE等)の扱いについて】
  このハンドラは「パンク画面」を出すための安全網であり、通常のPHPの
  エラー表示設定(display_errors等)を勝手に変えることはしない。
  非致命的なエラーはログにだけ静かに記録し、set_error_handler()からは
  falseを返してPHP標準の処理(画面表示するかどうかはini設定次第)に
  そのまま委ねる。
******************************************/

define('PUSYUU_PANIC_LOG_PATH', __DIR__ . '/../../documents/panicLog.jsonl');
define('PUSYUU_PANIC_LOG_MAX_LINES', 1000); // ログ肥大化防止の上限(件数)

$GLOBALS['__pusyuuPanicProductName'] = null;
$GLOBALS['__pusyuuPanicHandled'] = false;

/**
 * 表示用のプロダクト名を明示的に指定する(省略時はDOCUMENT_ROOTのフォルダ名を使う)。
 * パンク発生時に画面・ログへ出す名前を差し替えたいときに、requireの前後どちらでも呼べる。
 */
function pusyuuSetPanicProductName(string $name): void {
  $GLOBALS['__pusyuuPanicProductName'] = $name;
}

function pusyuuPanicResolveProductName(): string {
  $explicit = $GLOBALS['__pusyuuPanicProductName'] ?? '';
  $docRoot  = $_SERVER['DOCUMENT_ROOT'] ?? '';
  $fromRoot = ($docRoot === '') ? '' : basename(rtrim($docRoot, '/\\'));
  $result   = '';

  if (!empty($explicit)) {
    $result = $explicit;
  } else if ($fromRoot !== '') {
    $result = $fromRoot;
  } else {
    $result = 'unknown';
  }

  return $result;
}

function pusyuuPanicSeverityName(int $severity): string {
  $names = [
    E_ERROR             => 'E_ERROR',
    E_PARSE             => 'E_PARSE',
    E_CORE_ERROR        => 'E_CORE_ERROR',
    E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
    E_USER_ERROR        => 'E_USER_ERROR',
    E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
  ];
  return $names[$severity] ?? ('E_UNKNOWN(' . $severity . ')');
}

/**
 * 現在のリクエストがJSON応答を期待しているとみなせるかを判定する。
 * 完全な判定は不可能なので、既存コード(WebMail等)が実際に使っているパターンに
 * 絞った、誤検知より見逃しを許容するゆるめのヒューリスティック。
 */
function pusyuuPanicWantsJson(): bool {
  // 手がかりを3つ集めてから、1箇所でまとめて判定します。
  // (rate_limiter.php の pusyuuRateLimitWantsJson() と同じ形です。
  //  片方だけ直すと、同じ相手に別々の形式で答えるようになるので揃えてください)
  $alreadySendingJson = false;
  foreach (headers_list() as $sentHeader) {
    if (stripos($sentHeader, 'Content-Type:') === 0 && stripos($sentHeader, 'json') !== false) {
      $alreadySendingJson = true;
    }
  }

  $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
  $accept        = $_SERVER['HTTP_ACCEPT'] ?? '';
  $result        = false;

  if ($alreadySendingJson) {
    $result = true;
  } else if (strcasecmp($requestedWith, 'XMLHttpRequest') === 0) {
    $result = true;
  } else if ($accept !== '' && stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false) {
    $result = true;
  } else {
    $result = false;
  }

  return $result;
}

/**
 * 画面をまるごと差し替えられる状態かどうか。出力バッファが使われている場合は
 * (まだ実際にはクライアントへ送信されていないので)握りつぶしてから描き直せる。
 * 既にヘッダー送信済み(=バッファ無しで部分的に出力済み)の場合は諦める。
 */
function pusyuuPanicCanRenderScreen(): bool {
  while (ob_get_level() > 0) {
    @ob_end_clean();
  }
  return !headers_sent();
}

function pusyuuPanicLog(string $message, string $file, int $line, string $trace, string $kind): string {
  $incidentId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

  $entry = [
    'id'      => $incidentId,
    'time'    => date('Y-m-d H:i:s'),
    'product' => pusyuuPanicResolveProductName(),
    'kind'    => $kind,
    'message' => $message,
    'file'    => $file,
    'line'    => $line,
    'trace'   => $trace,
    'url'     => $_SERVER['REQUEST_URI'] ?? '',
    'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
  ];

  $dir = dirname(PUSYUU_PANIC_LOG_PATH);
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  $fp = @fopen(PUSYUU_PANIC_LOG_PATH, 'c+');

  if ($fp === false) {
    // 記録先を開けなかった。下の error_log() だけが頼りになります。
  } else {
    if (flock($fp, LOCK_EX)) {
      $size    = filesize(PUSYUU_PANIC_LOG_PATH);
      $raw     = $size > 0 ? fread($fp, $size) : '';
      $lines   = ($raw === '' || $raw === false) ? [] : explode("\n", rtrim($raw, "\n"));
      $lines[] = json_encode($entry, JSON_UNESCAPED_UNICODE);

      // 保持件数を超えた古いものから間引く(ファイル肥大化防止)
      if (count($lines) > PUSYUU_PANIC_LOG_MAX_LINES) {
        $lines = array_slice($lines, -PUSYUU_PANIC_LOG_MAX_LINES);
      }

      ftruncate($fp, 0);
      rewind($fp);
      fwrite($fp, implode("\n", $lines) . "\n");
      fflush($fp);
      flock($fp, LOCK_UN);
    }
    fclose($fp);
  }

  // サーバー管理者がtail -f等で即座に気付けるよう、標準のエラーログにも残す
  error_log('[pusyuu_panic:' . $incidentId . '] ' . $kind . ': ' . $message . ' in ' . $file . ':' . $line);

  return $incidentId;
}

function pusyuuPanicRenderJson(string $incidentId): void {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode([
    'error'      => 'サーバーで予期しないエラーが発生しました。時間をおいて再度お試しください。',
    'incidentId' => $incidentId,
  ], JSON_UNESCAPED_UNICODE);
}

function pusyuuPanicRenderHtml(string $incidentId, string $productName): void {
  $safeIncident = htmlspecialchars($incidentId, ENT_QUOTES, 'UTF-8');
  $safeProduct  = htmlspecialchars($productName, ENT_QUOTES, 'UTF-8');
  $currentUrl   = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8');
  $year         = date('Y');

  http_response_code(500);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>システムエラー - プシューサービス</title>
<style>
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    background: #05070a;
    color: #d8ffe0;
    font-family: "Hiragino Sans", "Yu Gothic", "Segoe UI", sans-serif;
  }
  body {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 24px;
  }
  .panic-card {
    max-width: 480px;
    width: 100%;
    background: #0d130d;
    border: 1px solid #1c2b1c;
    border-radius: 10px;
    padding: 32px 28px;
    box-shadow: 0 0 30px rgba(0, 255, 0, 0.08);
  }
  .panic-badge {
    display: inline-block;
    font-size: 12px;
    letter-spacing: .08em;
    color: #04150a;
    background: #39ff6a;
    padding: 3px 10px;
    border-radius: 999px;
    font-weight: bold;
    margin-bottom: 14px;
  }
  h1 {
    font-size: 20px;
    margin: 0 0 12px;
    color: #f5fff5;
  }
  p {
    line-height: 1.7;
    font-size: 14px;
    color: #b7d6bd;
    margin: 0 0 10px;
  }
  .panic-meta {
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid #1c2b1c;
    font-size: 11px;
    color: #6f9678;
    word-break: break-all;
  }
  .panic-actions {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }
  .panic-actions a {
    display: inline-block;
    text-decoration: none;
    border: 1px solid #39ff6a;
    color: #39ff6a;
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 13px;
  }
  .panic-actions a:hover {
    background: #39ff6a;
    color: #04150a;
  }
</style>
</head>
<body>
  <div class="panic-card">
    <span class="panic-badge">SYSTEM ERROR</span>
    <h1>ただいま一時的に処理を行えません</h1>
    <p>ページの読み込み中に予期しない問題が発生しました。お手数ですが、少し時間をおいてから再度お試しください。</p>
    <p>問題が繰り返し発生する場合は、下記の識別コードとあわせてサービス管理者へご連絡いただけますと調査の助けになります。</p>
    <div class="panic-actions">
      <a href="<?php echo $currentUrl; ?>">再読み込み</a>
      <a href="/">トップへ戻る</a>
    </div>
    <div class="panic-meta">
      プロダクト: <?php echo $safeProduct; ?><br>
      識別コード: <?php echo $safeIncident; ?><br>
      &copy; <?php echo $year; ?> PusyuuWanko/
    </div>
  </div>
</body>
</html>
<?php
}

function pusyuuPanicRender(string $message, string $file, int $line, string $trace, string $kind): void {
  // set_error_handler/register_shutdown_functionの両方から同じ致命的エラーに
  // 反応して二重にログ・出力してしまうのを防ぐガード。
  // 2度目に来たときは何も書かずに終わるだけで、終わること自体は1度目と同じです。
  if (!empty($GLOBALS['__pusyuuPanicHandled'])) {
    // 既に1度目が記録も表示も済ませています。ここで足すと画面が二重になります。
  } else {
    $GLOBALS['__pusyuuPanicHandled'] = true;

    $incidentId = pusyuuPanicLog($message, $file, $line, $trace, $kind);

    // 既に本文が送信済みで画面を差し替えられない場合は、ログへの記録のみで
    // それ以上の出力はしない(中途半端な追記はかえって壊れて見えるため)
    if (!pusyuuPanicCanRenderScreen()) {
      // 画面は諦めます。記録は上で済んでいます。
    } else if (pusyuuPanicWantsJson()) {
      pusyuuPanicRenderJson($incidentId);
    } else {
      pusyuuPanicRenderHtml($incidentId, pusyuuPanicResolveProductName());
    }
  }

  exit;
}

function pusyuuPanicHandleException(Throwable $e): void {
  pusyuuPanicRender($e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), get_class($e));
}

function pusyuuPanicHandleError(int $severity, string $message, string $file, int $line): bool {
  $fatalSeverities = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
  $result = false;

  if (!(error_reporting() & $severity)) {
    // @演算子等で意図的に抑制されているエラー。PHP標準の挙動を尊重してそのまま無視する。
    $result = false;
  } else if (($severity & $fatalSeverities) !== 0) {
    // 致命的。ここで画面を出して終わります(pusyuuPanicRender内でexitするため、
    // 実際にはこの下のtrueが返ることはありませんが、この関数の約束としては
    // 「自分で処理した」なのでtrueにしておきます)。
    pusyuuPanicRender($message, $file, $line, '', pusyuuPanicSeverityName($severity));
    $result = true;
  } else {
    // E_WARNING/E_NOTICE等の非致命的なものはログにだけ残し、
    // 画面表示するかどうかは既存のdisplay_errors設定に委ねる(falseを返す)
    pusyuuPanicLog($message, $file, $line, '', pusyuuPanicSeverityName($severity));
    $result = false;
  }

  return $result;
}

function pusyuuPanicHandleShutdown(): void {
  $error = error_get_last();
  // set_error_handlerでは捕まえられない種類の致命的エラー(パースエラー等)のみ対象
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

  if ($error === null) {
    // 何事もなく終わった通常の終了。
  } else if (!in_array($error['type'], $fatalTypes, true)) {
    // 致命的でないエラーが最後に1件あっただけ。画面は差し替えません。
  } else {
    pusyuuPanicRender($error['message'], $error['file'], $error['line'], '', pusyuuPanicSeverityName($error['type']));
  }
}

function pusyuuInstallPanicHandler(): void {
  // CLI(バッチ処理・cron等)ではHTML/JSON画面を返す意味が無いため、
  // PHP標準のエラー出力に任せてこのハンドラでは何もしない
  if (PHP_SAPI === 'cli') {
    // 何も仕掛けません。
  } else {
    set_exception_handler('pusyuuPanicHandleException');
    set_error_handler('pusyuuPanicHandleError');
    register_shutdown_function('pusyuuPanicHandleShutdown');
  }
}

// このファイルをrequire(_once)しただけで有効になるよう、読み込み時に自動インストールする
pusyuuInstallPanicHandler();
