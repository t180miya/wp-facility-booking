=== WP Plugin Starter ===
Contributors: your-name
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
管理画面で設定したメッセージと、現在月のグラフィカルなカレンダーをショートコードで表示します。

== Installation ==
1. `wpplugin` フォルダーを `wp-content/plugins/` に配置します。
2. WordPress管理画面の「プラグイン」から「WP Plugin Starter」を有効化します。
3. 「設定」→「WP Plugin Starter」でメッセージを設定します。
	同じ画面で施設名、利用料金、利用可能開始・終了時刻、施設情報を設定できます。複数施設は施設一覧(JSON)で設定できます。これらは利用者画面では編集できません。
4. 投稿や固定ページに `[wpplugin_message]` を追加します。
5. 投稿や固定ページに `[wpplugin_calendar]` を追加すると、現在月のカレンダーを表示できます。
6. ログイン済みユーザーがカレンダーの日付をクリックすると、予約内容の入力フォームが表示されます。
7. 予約を保存すると、PCでは該当日のセル内、モバイルでは日付順の一覧に予約時刻とタイトルが表示されます。

== Reservation form ==
日付、開始時刻、終了時刻、予約タイトル、予約者名、詳細を入力できます。予約者名にはログイン中のWordPressユーザー名が初期入力されます。

== Changelog ==
= 1.0.0 =
* 初回リリース