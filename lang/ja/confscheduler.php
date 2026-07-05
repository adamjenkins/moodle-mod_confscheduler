<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Language strings for mod_confscheduler (Japanese).
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['addroom'] = '会場を追加';
$string['addspanblock'] = 'スパンブロックを追加';
$string['alldays'] = 'All days（全日程）';
$string['autoscheduler'] = '自動スケジューラー';
$string['autoschedulerclearfirst'] = 'この時間帯の既存のスケジュールを先にクリアする';
$string['autoschedulerclearfirst_help'] = 'チェックすると、自動スケジューラーが新しく配置を行う前に、選択した時間帯内に既に配置されているすべてのブロックが削除されます。時間帯の外にあるブロックは影響を受けません。チェックしない場合（デフォルト）は、自動スケジューラーは既に配置されている内容との衝突を避けながら配置します。';
$string['autoschedulernofit'] = '選択した時間帯には、配置の衝突なくこの応募を収められる会場・時間の組み合わせがありません。';
$string['autoschedulernoroomsconfigured'] = 'このスケジューラーにはまだ会場が設定されていません。';
$string['autoschedulerrun'] = '自動スケジューラーを実行';
$string['autoschedulersummary'] = '{$a->scheduled}件を配置しました。{$a->skipped}件は配置できませんでした。';
$string['autoschedulerwindowend'] = '時間帯の終了';
$string['autoschedulerwindowstart'] = '時間帯の開始';
$string['conferenceend'] = '開催終了日時';
$string['conferenceend_help'] = '開催イベントの終了日時です。必須項目です——スケジュール画面で選択できる日（「All days（全日程）」を含む）、自動スケジューラーの初期の時間帯、そしてこの時刻以降をグレー表示にしてスケジュールできないようにする挙動は、すべてこの設定を基準にしています。';
$string['conferencestart'] = '開催開始日時';
$string['conferencestart_help'] = '開催イベントの開始日時です。必須項目です——スケジュール画面で選択できる日（「All days（全日程）」を含む）、自動スケジューラーの初期の時間帯、そしてこの時刻より前をグレー表示にしてスケジュールできないようにする挙動は、すべてこの設定を基準にしています。';
$string['confirmdeleteroom'] = 'この会場を削除しますか？ 元に戻すことはできません。';
$string['confprogramcmid'] = 'Conference Program アクティビティ';
$string['confprogramcmid_help'] = 'この Conference Scheduler インスタンスがスケジュール対象とする、このコース内の Conference Program アクティビティの採択済み応募です。';
$string['confscheduler:addinstance'] = 'Conference Scheduler アクティビティを新規追加する';
$string['confscheduler:favourite'] = '配置済みの発表の「My timetable」を切り替える';
$string['confscheduler:manageschedule'] = 'スケジュールを管理する（ドラッグ＆ドロップでの配置、会場管理、自動スケジューラー）';
$string['confscheduler:viewschedule'] = 'カンファレンスのスケジュールを閲覧する';
$string['day'] = '日程';
$string['deleteroom'] = '会場を削除';
$string['editroom'] = '会場を編集';
$string['editspanblock'] = 'スパンブロックを編集';
$string['error:conferenceendbeforestart'] = '開催終了日時は開催開始日時より後にしてください。';
$string['error:gapviolation'] = 'この配置は同じ会場の別の発表と近すぎます。設定されている最小間隔を満たしていません。';
$string['error:invalidcolour'] = '会場の色は空欄にするか、6桁の16進数カラーコード（例: #3366cc）で指定してください。';
$string['error:invalidconfprogramcmid'] = 'このコース内の Conference Program アクティビティを選択してください。';
$string['error:invalidnumber'] = '0以上の整数を入力してください。';
$string['error:invalidpxperhour'] = '行の高さは60から480の間の整数（1時間あたりのピクセル数）で指定してください。';
$string['error:invalidroom'] = '選択された会場のうち、1つ以上が見つかりませんでした。';
$string['error:invalidslot'] = 'この配置済みブロックが見つかりませんでした。';
$string['error:invalidsubmission'] = 'この応募はここには配置できません。';
$string['error:invalidtimerange'] = '終了時刻は開始時刻より後にしてください。';
$string['error:labelrequired'] = 'このブロックのラベルを入力してください。';
$string['error:noconfprogram'] = 'このコースにはまだ Conference Program アクティビティがありません。先に追加してください。';
$string['error:notaspanblock'] = 'この操作は、発表を含まない列またがりのブロックにのみ適用できます。';
$string['error:outsideconferencedates'] = 'この配置は開催開始日時・終了日時の範囲外です。';
$string['error:roomhasslots'] = 'この会場にはまだ配置済みのブロックがあります。先に配置を解除してください。';
$string['error:roomnamerequired'] = '会場名を入力してください。';
$string['error:timeoverlap'] = 'この配置は同じ会場に既に配置されている別のブロックと重なっています。';
$string['favourite'] = 'My timetable に追加';
$string['filterbytrack'] = 'トラックで絞り込んだプログラムを表示: {$a}';
$string['fullscreen'] = '全画面表示';
$string['gapminutes'] = 'SnapGap の最小間隔（分）';
$string['gapminutes_help'] = 'スケジュール画面でドラッグ操作を行う際、同じ会場に配置された発表の間に確保される最小間隔（分）です。0 にすると最小間隔は適用されません。';
$string['landscape'] = '横向き';
$string['modulename'] = 'Conference Scheduler';
$string['modulename_help'] = 'Conference Scheduler アクティビティは、Conference Program アクティビティから採択済みの応募を取り込み、主催者がドラッグ＆ドロップで会場×時間のブロックスケジュールを作成できるようにします。';
$string['modulenameplural'] = 'Conference Scheduler';
$string['movecolumn'] = '列を移動';
$string['mytimetable'] = 'My timetable';
$string['nocolour'] = '色なし';
$string['noinstances'] = 'このコースにはまだ Conference Scheduler アクティビティがありません。';
$string['orientation'] = '向き';
$string['papersize'] = '用紙サイズ';
$string['pluginadministration'] = 'Conference Scheduler の管理';
$string['pluginname'] = 'Conference Scheduler';
$string['portrait'] = '縦向き';
$string['print'] = '印刷';
$string['printbw'] = '白黒';
$string['printcolour'] = 'カラー';
$string['printcolourmode'] = '印刷時の色モード';
$string['privacy:metadata'] = 'Conference Scheduler プラグインは個人情報を保存しません。そのテーブルには会場・列の設定と配置済みの時間ブロックのデータのみが保存されます（いずれもプラグイン間参照の応募IDまたは単なるテキストラベルを参照するのみで、ユーザーそのものは参照しません）。また、切り替え対象となる「my timetable」のお気に入り状態は、すべて Conference Program プラグイン側で保存されます。';
$string['pxperhour'] = '行の高さ（1時間あたりのピクセル数）';
$string['pxperhour_help'] = 'グリッド上で1時間分がどれくらいの高さで表示されるかを設定します。短い発表でタイトルや発表者名が他のブロックと重ならずに収まらない場合は大きくし、1日分を画面に収めてスクロールを減らしたい場合は小さくしてください。';
$string['roomcolour'] = 'カラーテーマ';
$string['roomname'] = '会場名';
$string['schedulingsettings'] = 'スケジュール設定';
$string['spanblockcolour'] = 'カラーテーマ';
$string['spanblockend'] = '終了時刻';
$string['spanblockendroom'] = '終了会場';
$string['spanblocklabel'] = 'ラベル';
$string['spanblockstart'] = '開始時刻';
$string['spanblockstartroom'] = '開始会場';
$string['unschedule'] = '配置を解除';
$string['unscheduledheading'] = '未配置';
