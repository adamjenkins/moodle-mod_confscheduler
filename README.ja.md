# mod_confscheduler

**Conference Scheduler**（スケジューラー）— [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) の採択済み応募を、ドラッグ＆ドロップの「時間 × 会場」スケジュールに変換する Moodle アクティビティモジュール。自動配置と印刷／エクスポートに対応します。

*ドキュメント: [English](README.md) · 日本語（このファイル）*

[Conference Tools](https://github.com/adamjenkins/moodle-conference-tools) スイートの一部です:

- [mod_confsubmissions](https://github.com/adamjenkins/moodle-mod_confsubmissions) — 発表募集
- [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) — 査読ワークフロー＋公開プログラム
- **mod_confscheduler**（本プラグイン）— ドラッグ＆ドロップのブロックスケジュール
- [mod_confcheckin](https://github.com/adamjenkins/moodle-mod_confcheckin) — チケット・バッジ・QR チェックイン

## 機能概要

連携先の Conference Program インスタンスから採択済みの発表を読み込む「時間 × 会場」グリッドです。表示されるモードは Moodle のサイト全体の**編集モード**スイッチに従います（編集操作にはさらに `mod/confscheduler:manageschedule` 権限が必要です）。

**編集モード**

- 未配置パネルから採択済みの発表をグリッドへ**ドラッグ**し、グリッド内でドラッグして再配置します。ブロックの配置先はライブでハイライト表示されます。
- **SnapGap** は、無効な配置を拒否する代わりに最も近い有効な位置へ寄せ、発表間に設定可能な最小間隔を保ちます。ブロックは下端のグリップでリサイズでき、発表の初期の長さは応募種別の時間から決まります。
- **会場**は編集・配色・並べ替えが可能で、任意の定員を設定できます。お気に入り数が会場定員を超える発表は、定員超過の可能性として強調表示されます。
- **列をまたぐブロック**（独自の色付き、実際の会場名の代わりに表示する任意のカスタム会場名ラベルも設定可）は全体セッションや休憩に使え、その場で編集できます。列をまたぐブロックは**コンテナ**にもでき、ポスターセッションや基調講演パネルのように、コンテナ自身の時刻・会場を共有する複数の発表を入れ子にできます。「＋」ボタンで追加し、通常の重複チェックの対象外で、等幅の列として表示されます。入れ子になった発表自体のブロックには会場・時刻を繰り返し表示しません（コンテナの行にすでに1回表示されているためです）。
- **自動スケジューラー**は指定した時間帯を自動で埋め、同一トラックの発表をまとめ、応募者の希望日を尊重します（既定では厳守。任意で上書き可能）。
- ツールバーの**クイックコントロール**で、最小間隔・行の高さ・1日の表示時間帯をその場で調整できます。
- **通知を送信**は、前回の通知以降に時刻や会場が変わったすべての発表へスケジュール変更の通知メールを送ります（手動送信で、変更がなければ再送されません）。テンプレートは編集可能です。

**表示モード**

- 同じグリッドの読み取り専用表示です。ブロックから発表の Conference Program ページを開けます（リンク、または JavaScript 有効時はその場でモーダル表示）。
- **マイタイムテーブル**トグルはお気に入りの発表を強調表示します。**エクスポート（.ics）**リンクで iCalendar ファイルとしてダウンロードできます。
- ライブトグルでカラーまたは白黒で印刷できます（CSS のみ。用紙サイズ・向きはブラウザに委ねます）。
- 日付セレクターで、複数日のスケジュールを1日ずつ表示します。

**連携とデータ**

- Conference Program が時刻・会場のために読み込むコントラクト `\mod_confscheduler\api::get_schedule_for_submission()` を実装し、お気に入りは Conference Program へ直接書き込みます（お気に入りの状態はそちらが保持し、本プラグインでは複製しません）。
- カンファレンスの開始・終了日はアクティビティ設定で指定します。その範囲外への配置はサーバー側で拒否され、グリッドでは淡色表示されます。
- **バックアップ／リストアとコースリセット** — 完全対応。リセットはスケジュールを消去しますが、会場は設定として残します。

## 要件

- Moodle 5.2（`2026042000`）以降。
- 同じコースに mod_confprogram（およびそれを通じて mod_confsubmissions）がインストールされていること。

## インストール

```
git clone https://github.com/adamjenkins/moodle-mod_confscheduler.git mod/confscheduler
php admin/cli/upgrade.php
```

## ライセンス

GNU GPL v3 以降。[LICENSE](LICENSE) を参照してください。

## 作者

Adam Jenkins <adam@wisecat.net>
