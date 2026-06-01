<?php

namespace CoffeeR\Tekagami;

use CoffeeR\Tekagami\Flow;
use CoffeeR\Tekagami\Http\HttpInput;
use CoffeeR\Tekagami\Http\HttpResponse;

/**
 * トレースのライフサイクルを管理する主要公開インターフェース。
 *
 * 1リクエスト = 1トレース。コールシーケンス:
 *   start() → [addSql() / addExpandedSql() / addCustom() / addError()] → finish()
 *
 * 外部 HTTP 呼び出しの記録は addCustom('http_call', ...) で代替する。
 *
 * SinkInterface は実装クラスのコンストラクタに注入する。
 * アプリ本体への影響ゼロが原則: 書き込み失敗を含むいかなるエラーも例外として外に出さない。
 */
interface CollectorInterface
{
    /**
     * リクエスト開始時に一度だけ呼ぶ。アクティブなトレースを初期化する。
     *
     * start() を finish() の前に再度呼んだ場合の動作は実装依存とする
     * （ログ相関が主目的のため、ネストしたトレースはサポートしない）。
     *
     * @param HttpInput $http  HTTPリクエスト入力データ
     * @param Flow|null $flow  任意のフロー相関情報。未指定時は flow_id/seq を null として記録する。
     */
    public function start(HttpInput $http, $flow = null);

    /**
     * アクティブなトレースの trace_id を返す。トレースがなければ null。
     *
     * アダプタがレスポンスヘッダ (X-Trace-Id 等) やアプリログの相関に使う。
     * trace_id はログ相関に使える。
     *
     * @return string|null
     */
    public function getActiveTraceId();

    /**
     * 実行された SQL 文をタイムラインに追記する（高信頼入力）。
     *
     * Collector が正規化・HMAC トークン化・テーブル抽出・effects 集計を内部で行う。
     * アクティブトレースなしの場合は黙って無視。seq は Collector が全イベント共通のカウンタで採番する。
     *
     * @param string $sql        プレースホルダ付き SQL 文字列。
     * @param array  $binds      バインドパラメータ値の配列。
     * @param array  $options    追加メタ。source に取得元識別子を指定できる。
     */
    public function addSql($sql, array $binds = [], array $options = []);

    /**
     * 実行後 SQL / last_query / query_history をタイムラインに追記する（低信頼入力）。
     *
     * bind 分離が失われた SQL は statement_hash が分裂しやすいため、
     * analysis.input_quality と warnings に低信頼であることを記録する。
     *
     * @param string $sql      bind 展開済み SQL 文字列。
     * @param array  $options  追加メタ。source に取得元識別子を指定できる。
     */
    public function addExpandedSql($sql, array $options = []);

    /**
     * 任意の手動計装イベントをタイムラインに追記する。
     *
     * キャッシュ・ファイル・キュー・外部 HTTP 呼び出しなど SQL 以外の操作に使う。
     * Collector が $data の shape を生成する。
     * アクティブトレースなしの場合は黙って無視。seq は Collector が採番する。
     *
     * @param string $label  短い操作名。例: 'cache_read', 'file_put', 'queue_push'
     * @param mixed  $data   操作に紐づく任意のデータ（生の値）。null = データなし。
     */
    public function addCustom($label, $data = null);

    /**
     * 観測したエラー・例外をトレースの errors[] に追加する。
     *
     * 用途:
     *   - tekagami 内部のキャプチャ失敗 (type='capture_failure')
     *   - アダプタが観測したアプリ例外 (type='php_exception')
     *
     * 動作:
     *   アクティブなトレースがある場合にのみ errors[] に追記する。
     *   アクティブトレースなしの場合は黙って無視する。
     *
     * エラーの可観測性:
     *   - errors[] の内容は JSONL レコードとして sink に書き出される。
     *     JSONL が書き出されれば errors[] の中身も確認できる（errors[] が空 = クリーンキャプチャ）。
     *   - sink への書き込み失敗（record が出力されない場合）は PHP の error_log() に出力される。
     *     これが唯一 Apache ログに出力されるケース。
     *
     * @param string      $type     エラー種別。schema: error_item.type
     * @param string|null $message  エラーメッセージ
     * @param string|null $at       発生箇所の目安。例: 'ClassName', 'file.php:42'
     */
    public function addError($type, $message = null, $at = null);

    /**
     * トレースを完了しシンクに書き出す。リクエスト終了時に一度だけ呼ぶ。
     *
     * 内部処理 (finish() 内で順に実行):
     *   1. $response の情報を http エンベロープに統合
     *   2. timeline[type=sql] の write ops から effects[] を集計
     *   3. redaction メタ (tokenized/token_format) を Config から生成
     *   4. SinkInterface::write() を呼び出す
     *   5. write() が例外を投げた場合は握りつぶす（アプリ本体を止めない）
     *
     * アクティブなトレースがなければ何もしない。
     * 完了後 getActiveTraceId() は null を返す。
     *
     * @param HttpResponse $response  HTTPレスポンスデータ
     */
    public function finish(HttpResponse $response);
}
