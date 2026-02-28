=== WP Media Export ===
Contributors: wpmediaexportteam
Tags: media, export, download, zip, batch
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress のメディアファイルを一括で ZIP ダウンロードできるプラグイン。

== Description ==

WP Media Export は、WordPress のメディアライブラリに保存されたファイル（画像・動画・音声・ドキュメント）を一括でZIPファイルとしてダウンロードできるプラグインです。

= 主な機能 =

* **一括 ZIP ダウンロード** — 選択したメディアファイルをまとめて ZIP としてダウンロード
* **バッチ処理** — 50件ずつ処理するため、大量ファイルでもタイムアウトせずに動作
* **プログレスバー** — リアルタイムで進捗状況を確認可能
* **フィルタ機能** — MIMEタイプ（画像/動画/音声/ドキュメント）や日付で絞り込み
* **全選択機能** — フィルタ条件に一致するすべてのメディアを一括選択
* **オリジナルファイルのみ** — WordPress が自動生成するサムネイル・リサイズ画像は除外

= セキュリティ =

* 全 AJAX リクエストに nonce チェック
* `upload_files` 権限の検証（投稿者以上のみアクセス可能）
* ディレクトリトラバーサル防止
* 単一使用トークンによるダウンロードURL保護

= 必要条件 =

* PHP 7.4 以上
* PHP ZipArchive 拡張モジュール

== Installation ==

1. プラグインフォルダを `/wp-content/plugins/wp-media-export` にアップロードします。
2. WordPress 管理画面の「プラグイン」ページで有効化します。
3. 「メディア」→「メディアエクスポート」からご利用ください。

== Frequently Asked Questions ==

= ZipArchive が必要と表示されます =

サーバーに PHP の ZipArchive 拡張モジュールがインストールされている必要があります。サーバー管理者またはホスティングプロバイダにお問い合わせください。

= 何件までエクスポートできますか？ =

バッチ処理を採用しているため、件数に上限はありません。ただし、生成される ZIP ファイルのサイズはサーバーの空きディスク容量に依存します。

= サムネイルも含まれますか？ =

いいえ。WordPress が自動生成したサムネイル・リサイズ画像は含まれません。オリジナルファイルのみがエクスポートされます。

== Screenshots ==

1. メディアエクスポート画面 — フィルタと一覧テーブル
2. プログレスバーによる進捗表示
3. ダウンロード完了

== Changelog ==

= 1.0.0 =
* 初回リリース
* メディア一覧テーブル（MIMEタイプ・日付フィルタ、検索、ページネーション）
* バッチ処理によるZIPエクスポート
* プログレスバー表示
* 全ページ選択機能
