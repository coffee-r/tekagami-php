<?php

namespace CoffeeR\Tekagami\Sink;

/**
 * 観測レコードの永続化先を表すインターフェース。
 * 実装例: JsonlSink (JSONL ファイル書き出し)、NullSink (テスト用), etc.
 */
interface SinkInterface
{
    /**
     * 1件の完了した観測レコードを永続化する。
     *
     * Collector が組み立てた tekagami-v1 準拠の配列が渡される。
     * 実装は JSONL への追記・ネットワーク送信・その他の手段で永続化する。
     *
     * 失敗時は例外を throw すること。Collector が catch してアプリには伝播させない。
     * 実装が黙ってエラーを捨てると損失が検知できなくなるため、必ず throw する。
     *
     * @param array $trace  tekagami-v1 準拠の完全なレコード。
     *                      必須トップレベルキー:
     *                        'schema_version' int(1)
     *                        'trace_id'       string
     *                        'started_at'     string  ISO 8601 date-time
     *                        'flow'           array   {flow_id: string|null, seq: int|null}
     *                        'redaction'      array   {tokenized: bool, token_format: string|null}
     *                        'http'           array
     *                        'timeline'       array
     *                        'effects'        array
     *                        'errors'         array
     * @throws \RuntimeException  書き込み不能時
     */
    public function write(array $trace);
}
