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
$string['autoschedulerignorepreferreddates'] = '希望日程を無視する';
$string['autoschedulerignorepreferreddates_help'] = 'デフォルトでは、応募者が希望する開催日を登録している応募は、そのいずれかの日にのみ配置されます——希望日のどれにも空きがない場合、その応募は配置されずに「配置できませんでした」として報告されます（希望していない日に配置されることはありません）。このオプションをチェックすると、希望日程をあくまで「希望」として扱うようになります。自動スケジューラーはまず希望日への配置を試みますが、希望日のどれにも空きがない場合は、時間帯内の別の日への配置にフォールバックします。';
$string['autoschedulernofit'] = '選択した時間帯には、配置の衝突なくこの応募を収められる会場・時間の組み合わせがありません。';
$string['autoschedulernopreferreddatefit'] = '選択した時間帯内で、この応募の希望日程のいずれにも空きのある会場・時間がありません。別の日への配置を許可するには「希望日程を無視する」にチェックを入れてください。';
$string['autoschedulernoroomsconfigured'] = 'このスケジューラーにはまだ会場が設定されていません。';
$string['autoschedulerrun'] = '自動スケジューラーを実行';
$string['autoschedulersummary'] = '{$a->scheduled}件を配置しました。{$a->skipped}件は配置できませんでした。';
$string['autoschedulerwindowend'] = '時間帯の終了';
$string['autoschedulerwindowstart'] = '時間帯の開始';
$string['blackandwhite'] = '白黒';
$string['blocknonpreferredday'] = '応募者が希望していなかった日にスケジュールされています。';
$string['blockoverbooked'] = 'この発表のお気に入り登録者数が、この会場の定員を超えています（お気に入り数／定員）:';
$string['colour'] = 'カラー';
$string['colourmode'] = '色モード';
$string['conferenceend'] = '開催終了日時';
$string['conferenceend_help'] = '開催イベントの終了日時です。必須項目です——スケジュール画面で選択できる日（「All days（全日程）」を含む）、自動スケジューラーの初期の時間帯、そしてこの時刻以降をグレー表示にしてスケジュールできないようにする挙動は、すべてこの設定を基準にしています。';
$string['conferencestart'] = '開催開始日時';
$string['conferencestart_help'] = '開催イベントの開始日時です。必須項目です——スケジュール画面で選択できる日（「All days（全日程）」を含む）、自動スケジューラーの初期の時間帯、そしてこの時刻より前をグレー表示にしてスケジュールできないようにする挙動は、すべてこの設定を基準にしています。';
$string['confirmdeleteroom'] = 'この会場を削除しますか？ 元に戻すことはできません。';
$string['confirmsendnotifications'] = '前回の通知以降にスケジュール情報が変更されたすべての発表に、スケジュール変更通知を送信しますか？ 現在 {$a} 件の発表に未通知の変更があります。';
$string['confprogramcmid'] = 'Conference Program アクティビティ';
$string['confprogramcmid_help'] = 'この Conference Scheduler インスタンスがスケジュール対象とする、このコース内の Conference Program アクティビティの採択済み応募です。';
$string['confscheduler:addinstance'] = 'Conference Scheduler アクティビティを新規追加する';
$string['confscheduler:favourite'] = '配置済みの発表の「My timetable」を切り替える';
$string['confscheduler:managenotifications'] = 'スケジュール変更通知テンプレートを管理する';
$string['confscheduler:manageschedule'] = 'スケジュールを管理する（ドラッグ＆ドロップでの配置、会場管理、自動スケジューラー）';
$string['confscheduler:viewschedule'] = 'カンファレンスのスケジュールを閲覧する';
$string['day'] = '日程';
$string['daybounds_automatic'] = '自動';
$string['dayend'] = '表示終了時刻';
$string['dayend_help'] = 'スケジュールグリッドが既定で表示する最も遅い時刻です。表示開始・終了時刻の範囲外にスケジュールされた発表も全体が表示されます(グリッドがそれを含むように広がります)が、設定範囲外の部分は既存の「会議時間外」の帯と同様にグレー表示されます。';
$string['daystart'] = '表示開始時刻';
$string['daystart_help'] = 'スケジュールグリッドが既定で表示する最も早い時刻です。「自動」のままにすると、この設定が追加される前と同様に、実際にスケジュールされている内容から表示範囲が決まります。';
$string['deleteroom'] = '会場を削除';
$string['editroom'] = '会場を編集';
$string['editspanblock'] = 'スパンブロックを編集';
$string['error:conferenceendbeforestart'] = '開催終了日時は開催開始日時より後にしてください。';
$string['error:gapviolation'] = 'この配置は同じ会場の別の発表と近すぎます。設定されている最小間隔を満たしていません。';
$string['error:invalidcapacity'] = '会場の定員は空欄（無制限）にするか、0以上の整数で指定してください。';
$string['error:invalidcolour'] = '会場の色は空欄にするか、6桁の16進数カラーコード（例: #3366cc）で指定してください。';
$string['error:invalidconfprogramcmid'] = 'このコース内の Conference Program アクティビティを選択してください。';
$string['error:invaliddaybounds'] = '表示終了時刻は表示開始時刻より後にしてください。両方とも時刻(00:00〜23:59)で指定し、両方をまとめて設定するか、両方とも「自動」のままにしてください。';
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
$string['exportmytimetable'] = 'マイタイムテーブルをエクスポート (.ics)';
$string['favourite'] = 'My timetable に追加';
$string['filterbytrack'] = 'トラックで絞り込んだプログラムを表示: {$a}';
$string['fullscreen'] = '全画面表示';
$string['gapminutes'] = 'SnapGap の最小間隔（分）';
$string['gapminutes_help'] = 'スケジュール画面でドラッグ操作を行う際、同じ会場に配置された発表の間に確保される最小間隔（分）です。0 にすると最小間隔は適用されません。';
$string['hour'] = '時';
$string['managenotifications'] = '通知の管理';
$string['messageprovider:scheduleupdated'] = 'あなたが発表者に含まれる発表のスケジュール情報が変更されたとき';
$string['minute'] = '分';
$string['modulename'] = 'Conference Scheduler';
$string['modulename_help'] = 'Conference Scheduler アクティビティは、Conference Program アクティビティから採択済みの応募を取り込み、主催者がドラッグ＆ドロップで会場×時間のブロックスケジュールを作成できるようにします。';
$string['modulenameplural'] = 'Conference Scheduler';
$string['month'] = '月';
$string['movecolumn'] = '列を移動';
$string['mytimetable'] = 'My timetable';
$string['nocolour'] = '色なし';
$string['noinstances'] = 'このコースにはまだ Conference Scheduler アクティビティがありません。';
$string['notifbody'] = '本文';
$string['notifbody_help'] = '通知メールの本文です。主催者がスケジュール編集画面で「通知を送信」をクリックしたときに、Moodle 自体の通知システム（既定でメール送信も行われます）を通じて各発表者へ送信されます。[[fullname]]、[[submissiontitle]]、[[coursename]]、[[roomnames]]、[[starttime]]、[[endtime]] を使用できます。';
$string['notifdefaultbody:scheduled'] = '<p>[[fullname]] 様</p><p>[[coursename]] における、あなたの発表「[[submissiontitle]]」のスケジュール情報が変更されました。現在は [[roomnames]] にて [[starttime]] から [[endtime]] までの予定です。</p>';
$string['notifdefaultsubject:scheduled'] = 'スケジュール変更のお知らせ: [[submissiontitle]]';
$string['notificationsenabled'] = '通知を有効にする';
$string['notificationsenabled_help'] = 'このアクティビティのマスタースイッチです。チェックを外すと、下記のテンプレート設定や未通知の変更件数にかかわらず、このインスタンスからスケジュール変更通知が一切送信されなくなります。';
$string['notifplaceholders'] = '利用可能なプレースホルダー: {$a}。';
$string['notifsubject'] = '件名';
$string['notifsubject_help'] = '通知メールの件名です。下の本文と同じプレースホルダーが使用できます。';
$string['notiftemplatesaved'] = '通知テンプレートを保存しました。';
$string['pluginadministration'] = 'Conference Scheduler の管理';
$string['pluginname'] = 'Conference Scheduler';
$string['print'] = '印刷';
$string['privacy:metadata'] = 'Conference Scheduler プラグインは個人情報を保存しません。そのテーブルには会場・列の設定と配置済みの時間ブロックのデータのみが保存されます（いずれもプラグイン間参照の応募IDまたは単なるテキストラベルを参照するのみで、ユーザーそのものは参照しません）。また、切り替え対象となる「my timetable」のお気に入り状態は、すべて Conference Program プラグイン側で保存されます。';
$string['pxperhour'] = '行の高さ（1時間あたりのピクセル数）';
$string['pxperhour_help'] = 'グリッド上で1時間分がどれくらいの高さで表示されるかを設定します。短い発表でタイトルや発表者名が他のブロックと重ならずに収まらない場合は大きくし、1日分を画面に収めてスクロールを減らしたい場合は小さくしてください。';
$string['removeschedule'] = 'スケジュール（すべての配置済みスロット）を削除';
$string['roomcapacity'] = '定員';
$string['roomcapacity_help'] = 'この会場の最大収容人数です。設定すると、スケジュールされた発表の mod_confprogram でのお気に入り登録数がこの定員を超えた場合、編集モードのグリッドで過剰予約の可能性として強調表示されます。空欄のままにすると無制限となり、警告は表示されません。';
$string['roomcolour'] = 'カラーテーマ';
$string['roomname'] = '会場名';
$string['schedulingsettings'] = 'スケジュール設定';
$string['sendnotifications'] = '通知を送信';
$string['sendnotificationsnonepending'] = '通知が必要なスケジュール変更のある発表はありません。';
$string['sendnotificationssummary'] = '{$a} 件の発表に通知しました。';
$string['spanblockcolour'] = 'カラーテーマ';
$string['spanblockend'] = '終了時刻';
$string['spanblockendroom'] = '終了会場';
$string['spanblocklabel'] = 'ラベル';
$string['spanblockstart'] = '開始時刻';
$string['spanblockstartroom'] = '開始会場';
$string['unschedule'] = '配置を解除';
$string['unscheduledheading'] = '未配置';
$string['year'] = '年';
