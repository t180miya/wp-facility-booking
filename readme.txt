=== 施設予約 ===
Contributors: t180miya
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
施設管理、予約カレンダー、予約管理を提供します。

== Installation ==
1. WordPress管理画面の「プラグイン」→「新規プラグインを追加」→「プラグインのアップロード」から `wp-facility-booking.zip` を選択し、インストールします。
2. インストール後、「施設予約」を有効化します。
3. 管理画面の「施設予約」→「施設管理」で施設の追加・編集・削除と表示順の変更ができます。
	「施設予約」→「予約」ではカレンダーから予約でき、「予約管理」では予約一覧の確認とキャンセルができます。管理画面の施設管理・予約・予約管理は管理者および管理会社ロール向けです。
4. 投稿や固定ページに `[wpplugin_message]` を追加します。
5. 投稿や固定ページに `[wpplugin_calendar]` を追加すると、現在月のカレンダーを表示できます。
6. ログイン済みユーザーがカレンダーの日付をクリックすると、予約内容の入力フォームが表示されます。
7. 予約を保存すると、PCでは該当日のセル内、モバイルでは日付順の一覧に予約時刻とタイトルが表示されます。

== Reservation form ==
日付、開始時刻、終了時刻、予約タイトル、予約者名、詳細を入力できます。予約者名にはログイン中のWordPressユーザー名が初期入力されます。

== Changelog ==
= 1.0.0 =
* 初回リリース