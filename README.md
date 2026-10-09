# panic_handler

PHP の未捕捉例外や Fatal Error が起きたときに、スタックトレースやファイルパスを訪問者に見せず、
案内画面(JSON API へのリクエストなら JSON のエラー応答)を返す「パンク画面」です。
詳細はサーバー側のログ(JSONL)にだけ記録し、画面には識別コードだけを表示します。

## 使い方

入口(index.php など)の先頭で読み込むだけで有効になります。

```php
require_once __DIR__ . '/panic_handler.php';

// 画面に出すプロダクト名を変えたいとき(任意)
pusyuuSetPanicProductName('プシューメール');
```

- `set_exception_handler` / `set_error_handler` / `register_shutdown_function` を使うので、呼び忘れが起きません
- `E_WARNING` などの致命的でないエラーはログに残すだけで、PHP 標準の処理に任せます(`display_errors` などの設定は変えません)

## 設定

| 定数 | 既定値 | 内容 |
| --- | --- | --- |
| `PUSYUU_PANIC_LOG_PATH` | `__DIR__ . '/../../documents/panicLog.jsonl'` | ログの保存先 |
| `PUSYUU_PANIC_LOG_MAX_LINES` | `1000` | ログに残す最大件数 |

ログには内部情報が含まれます。公開ディレクトリから見えない場所に置くか、アクセスを禁止してください。

## ライセンス

[MIT License](LICENSE) © 2026 ISAMI ABE
