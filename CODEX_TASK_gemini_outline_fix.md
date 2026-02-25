# Codex指示書: Gemini 2.5 Pro アウトライン空レスポンス修正

## 背景
Gemini 2.5 Proでの記事アウトライン生成が `「アウトラインが空です」` エラーで失敗する。
HTTP自体は成功（`success => 1`）だが、本文抽出結果が空文字になっている。
原因は Gemini 2.5 Pro の **Thinking Mode** により、レスポンスの `parts` 構造が変わったこと。

## 修正対象ファイル（3ファイル）

### 修正1: Geminiレスポンス抽出の堅牢化（Critical）

**ファイル:** `includes/class-blog-poster-gemini-client.php`
**行:** 115-127

**現在のコード:**
```php
// レスポンスからテキストとトークン数を抽出
$text = '';
$tokens = 0;

if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
    $text = $response['candidates'][0]['content']['parts'][0]['text'];
}

if ( isset( $response['usageMetadata']['totalTokenCount'] ) ) {
    $tokens = $response['usageMetadata']['totalTokenCount'];
}

return $this->success_response( $text, $tokens );
```

**問題:**
- `parts[0]` のみ参照。Gemini 2.5 Pro の Thinking Mode では `parts[0]` が思考パート（`thought: true`）で、実際の回答テキストは `parts[1]` 以降にある
- textが空でも `success_response()` を返してしまう

**修正内容:**
以下のロジックに置き換える：

```php
// レスポンスからテキストとトークン数を抽出
$text = '';
$tokens = 0;

// finishReason チェック（SAFETY/RECITATION等のブロック検出）
if ( isset( $response['candidates'][0]['finishReason'] ) ) {
    $finish_reason = $response['candidates'][0]['finishReason'];
    if ( in_array( $finish_reason, array( 'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT' ), true ) ) {
        error_log( 'Blog Poster Gemini: Response blocked. finishReason=' . $finish_reason );
        return $this->error_response(
            sprintf( __( 'Geminiがコンテンツをブロックしました（理由: %s）', 'blog-poster' ), $finish_reason )
        );
    }
}

// 全partsをループし、thought以外のtextパートを連結
if ( isset( $response['candidates'][0]['content']['parts'] ) && is_array( $response['candidates'][0]['content']['parts'] ) ) {
    $parts = $response['candidates'][0]['content']['parts'];
    $text_parts = array();
    foreach ( $parts as $part ) {
        // thought: true のパート（Thinking Mode の思考過程）はスキップ
        if ( ! empty( $part['thought'] ) ) {
            continue;
        }
        if ( isset( $part['text'] ) && '' !== $part['text'] ) {
            $text_parts[] = $part['text'];
        }
    }
    $text = implode( '', $text_parts );
}

if ( isset( $response['usageMetadata']['totalTokenCount'] ) ) {
    $tokens = $response['usageMetadata']['totalTokenCount'];
}

// 空レスポンスはエラーとして返す
if ( empty( trim( $text ) ) ) {
    error_log( 'Blog Poster Gemini: Empty text after parsing. Raw response: ' . wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) );
    return $this->error_response( __( 'Geminiからの応答テキストが空です。', 'blog-poster' ) );
}

return $this->success_response( $text, $tokens );
```

---

### 修正2: モデル設定キーの統一（Medium）

**ファイル:** `admin/class-blog-poster-admin.php`
**行:** 529

**現在のコード:**
```php
$ai_model = isset( $settings[ $ai_provider . '_model' ] ) ? $settings[ $ai_provider . '_model' ] : '';
```

**問題:**
- 旧キー `gemini_model` / `claude_model` / `openai_model` を参照している
- generator.php（176行）、rewriter.php（356行）、settings.php では新キー `$settings['default_model']['gemini']` を使用
- このため、admin.php経由の呼び出し時にモデル指定が空になり得る

**修正内容:**
```php
$ai_model = isset( $settings['default_model'][ $ai_provider ] ) ? $settings['default_model'][ $ai_provider ] : '';
```

---

### 修正3: generator.php のエラーログ改善（Low, 任意）

**ファイル:** `includes/class-blog-poster-generator.php`
**行:** 340

**現在のコード:**
```php
error_log( 'Blog Poster: Outline response empty. Raw response: ' . print_r( $response, true ) );
```

**修正内容:**
修正1により空レスポンスは `success: false` で返るようになるため、ここに到達するケースは減る。
ただし防御的に、レスポンス構造の詳細をログに含めるよう改善：
```php
error_log( 'Blog Poster: Outline response empty. Response keys: ' . wp_json_encode( array_keys( $response ), JSON_UNESCAPED_UNICODE ) . ' | data type: ' . gettype( isset( $response['data'] ) ? $response['data'] : null ) );
```

---

## テスト観点

1. **Gemini 2.5 Pro でアウトライン生成** → 正常にアウトラインが取得できること
2. **空レスポンス時** → `success: false` + エラーメッセージが返ること（「アウトラインが空です」ではなく「Geminiからの応答テキストが空です」）
3. **SAFETYブロック時** → 適切なエラーメッセージが返ること
4. **admin.php経由のモデル指定** → `default_model` から正しくモデル名が取得されること
5. **Claude/OpenAI プロバイダー** → 既存動作に影響がないこと（回帰テストなし）

## ブランチ

`feat-gemini-outline-stability` ブランチで作業すること（現在のブランチ）。
