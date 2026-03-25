# FC2 BLOG to WordPress Importer

FC2ブログの記事を WordPress へインポートする WP-CLI プラグインです。
記事データ・画像・カテゴリー・タグ・公開日・コメントをまとめてインポートし、本文は Gutenberg ブロックとして取り込みます。

[ameblo2wp](https://github.com/AtsushiA/ameblo2wp) と同様のアーキテクチャ・コマンド体系を採用しています。

## 機能

- FC2ブログの全記事をスクレイピングして WordPress へインポート
- 月別アーカイブを巡回して全記事 URL を自動収集
- 記事本文を Gutenberg ブロック（paragraph / image / heading / list など）に変換
- FC2 CDN 画像（`blog-imgs-*.fc2.com`）を WordPress メディアライブラリへ登録・URL 置換
- カテゴリー・タグ・公開日を保持
- コメントのインポートに対応
- 進捗をファイルに保存し、中断・再開が可能

## 必要環境

- WordPress 5.0 以上
- WP-CLI
- PHP 7.4 以上

## インストール

```bash
cd wp-content/plugins
git clone https://github.com/AtsushiA/fc2blog2wp.git
```

WordPress 管理画面でプラグインを有効化してください。

## 使い方

### 基本構文

```bash
wp fc2 import <blog_url> [--with-images] [--with-comments] [--reset]
```

### オプション

| オプション | 説明 |
|-----------|------|
| `<blog_url>` | FC2ブログの URL（例: `https://example.blog.fc2.com/`） |
| `--with-images` | 画像を WordPress メディアライブラリへインポートする |
| `--with-comments` | コメントをインポートする |
| `--reset` | 進捗をリセットして最初から実行する |

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

## 処理の流れ

1. FC2ブログのトップページからサイドバーの月別アーカイブ URL を収集
2. 各月別アーカイブページから記事 URL（`blog-entry-*.html`）を収集
3. 各記事ページをパースしてタイトル・本文・日付・カテゴリー・タグを取得
4. 本文 HTML を Gutenberg ブロックに変換して `wp post create` で投稿
5. `--with-images` 指定時: FC2 CDN 画像をダウンロードしてメディア登録・URL 置換
6. `--with-comments` 指定時: コメントを `wp comment create` で登録
7. 進捗を `/wp-content/fc2blog2wp/<blog-id>/progress.json` に保存

## ファイル構成

```
fc2blog2wp/
├── fc2blog2wp.php              # メインプラグインファイル（WP-CLI 登録）
├── class/
│   ├── fc2_html_parser.php     # FC2ブログ HTML パーサー
│   ├── fc2blog2wp_class.php    # コアインポートロジック
│   └── fc2blog2wp_command.php  # WP-CLI コマンド実装
├── SPEC.md                     # 仕様書
└── README.md
```

## ライセンス

GPL-2.0-or-later
