# FC2 BLOG to WordPress Importer - 仕様書

## 概要

FC2ブログのページをスクレイピングして WordPress にインポートする WP-CLI プラグイン。
ameblo2wp と同様のアーキテクチャ・コマンド体系を採用する。

サンプルブログ: https://recordeurasia.blog.fc2.com/

---

## プラグイン情報

| 項目 | 内容 |
|------|------|
| Plugin Name | FC2 BLOG to WordPress Importer |
| Text Domain | fc2blog2wp |
| WP-CLI コマンド | `wp fc2 import` |
| 実行方法 | WP-CLIのみ（管理画面UIなし） |

---

## WP-CLI コマンド仕様

### 基本構文

```bash
wp fc2 import <blog_url> [--with-images] [--with-comments] [--reset]
```

### 引数・オプション

| 引数/オプション | 説明 | 必須 |
|---------------|------|------|
| `<blog_url>` | FC2ブログのURL (例: `https://example.blog.fc2.com/`) | 必須 |
| `--with-images` | 記事内の画像をダウンロードしてメディアライブラリに登録する | 任意 |
| `--with-comments` | コメントをインポートする | 任意 |
| `--reset` | 進捗をリセットして最初からインポートし直す | 任意 |

### 使用例

```bash
# 記事のみインポート
wp fc2 import https://example.blog.fc2.com/

# 画像も含めてインポート
wp fc2 import https://example.blog.fc2.com/ --with-images

# 画像・コメントも含めてインポート
wp fc2 import https://example.blog.fc2.com/ --with-images --with-comments

# 進捗をリセットして最初から実行
wp fc2 import https://example.blog.fc2.com/ --with-images --reset
```

---

## FC2ブログ HTML 構造（スクレイピング対象）

### 調査対象: https://recordeurasia.blog.fc2.com/

### 記事一覧ページ

- 月別アーカイブURL: `/blog-date-[YYYYMM].html`
- サイドバーに月別アーカイブリンク一覧あり（`href` に `blog-date-` を含む）
- 記事一覧の各記事: `.entryList_item`
- 記事リンク: `.entryTitle` の `href` → `/blog-entry-[N].html`

### 個別記事ページ

| データ | CSS セレクタ |
|--------|------------|
| タイトル | `h1.entryTitle` |
| 日付 | `.entryDate` → `.entryDate_y` / `.entryDate_m` / `.entryDate_d` |
| カテゴリー | `.entryCat` |
| 本文 | `.l-entryBody` |
| タグ | `.entryTag_list a` |
| コメント | `.commentList .commentList_item` |

### 日付フォーマット

```html
<span class="entryDate">
  <span class="entryDate_y">2026</span>
  <span class="entryDate_m">03</span>
  <span class="entryDate_d">01</span>
</span>
```

### 画像URL パターン

```
https://blog-imgs-[number].fc2.com/[path]/[filename].jpg
```

### コメント HTML 構造

```html
<div class="commentList">
  <div class="commentList_item">
    <span class="commentList_author">投稿者名</span>
    <span class="commentList_date">2026/03/01</span>
    <div class="commentList_text">コメント本文</div>
  </div>
</div>
```

---

## 処理フロー

```
1. ブログURL検証・ユーザーID抽出
   └── fc2.com URL かチェック

2. 進捗ファイル確認（再開対応）

3. 月別アーカイブURL取得
   └── メインページのサイドバーから blog-date-*.html を収集

4. 全記事URL取得
   └── 各月別アーカイブページから blog-entry-*.html を収集
   └── ページネーション対応 (?page=N&more)

5. 記事ループ処理
   ├── 記事ページ取得・パース
   │   ├── タイトル (.entryTitle)
   │   ├── 日付 (.entryDate_y/.entryDate_m/.entryDate_d)
   │   ├── カテゴリー (.entryCat)
   │   ├── 本文 (.l-entryBody) → Gutenbergブロック変換
   │   └── タグ (.entryTag_list a)
   ├── wp post create で記事作成
   ├── カテゴリー・タグ設定
   ├── [--with-images] 画像処理
   │   ├── blog-imgs-*.fc2.com の img src を抽出
   │   ├── wp media import でダウンロード・メディア登録
   │   └── 本文内URLを新URLに置換 + wp:image ブロック化
   ├── [--with-comments] コメント処理
   │   └── wp comment create でコメント登録
   └── 進捗ファイルに完了記録

6. 完了サマリー表示
```

---

## ファイル構成

```
fc2blog2wp/
├── fc2blog2wp.php              # メインプラグインファイル（WP-CLI登録）
├── class/
│   ├── fc2blog2wp_class.php    # コアインポートロジック
│   ├── fc2blog2wp_command.php  # WP-CLIコマンド実装
│   └── fc2_html_parser.php     # FC2ブログ HTMLパーサー
└── SPEC.md                     # 本仕様書
```

---

## ameblo2wpとの対応関係

| fc2blog2wp | ameblo2wp | 役割 |
|---|---|---|
| `fc2blog2wp.php` | `ameblo2wp.php` | プラグイン本体・WP-CLI登録 |
| `class/fc2blog2wp_command.php` | `class/ameblo2wp_command.php` | WP-CLIコマンド |
| `class/fc2blog2wp_class.php` | `class/ameblo2wp_class.php` | コアロジック |
| `class/fc2_html_parser.php` | `class/ameblo_html_parser.php` | HTMLパーサー |
| `wp fc2 import <blog_url>` | `wp ameblo import <blog_url>` | WP-CLIコマンド |

---

## ブロック変換仕様

本文 HTML（`.l-entryBody`）を Gutenberg ブロックに変換する。

| HTML要素 | Gutenbergブロック |
|---------|-----------------|
| `<p>` | `<!-- wp:paragraph -->` |
| `<img>` | `<!-- wp:image -->` |
| `<h1>`〜`<h6>` | `<!-- wp:heading -->` |
| `<ul>`, `<ol>` | `<!-- wp:list -->` |
| `<blockquote>` | `<!-- wp:quote -->` |
| その他のHTML | `<!-- wp:html -->` |

---

## 進捗管理

- 保存先: `/wp-content/fc2blog2wp/[blog-id]/progress.json`
- 内容: `{ "total_posts": N, "completed_posts": ["url", ...] }`
- 中断・再開対応（`--reset` で初期化）
