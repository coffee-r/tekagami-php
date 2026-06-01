<?php

namespace CoffeeR\Tekagami;

/**
 * Collector の設定値オブジェクト。コンストラクタで Collector に注入する。
 */
class Config
{
    /** @var string|null  HMAC-SHA256 の共有シークレット。設定時に *_tokens フィールドを記録。null = トークン化なし */
    public $secret = null;

    /**
     * @var array  実値を残すキー名の白リスト（完全一致・大小無視）。
     *             query/request body で使う。デフォルトは空＝実値を一切残さない。
     *             ここに明示したキーだけが *_values に実値として記録される。
     */
    public $keepKeys = [];

    /**
     * @var array  記録する HTTP ヘッダ名の白リスト（完全一致・大小無視）。
     *             デフォルトは空＝ヘッダの存在情報も記録しない。
     */
    public $keepHeaderKeys = [];

    /**
     * @var array  記録する HTTP レスポンスヘッダ名の白リスト（完全一致・大小無視）。
     *             デフォルトは空＝レスポンスヘッダの存在情報も記録しない。
     */
    public $keepResponseHeaderKeys = [];

    /**
     * @var array  SQL の列値を残す白リスト。
     *             フォーマット: 'table.column' または 'column'。デフォルトは空。
     *             ここに明示した列だけが observed_values に実値として記録される。
     */
    public $sqlValueAllowlist = [];

    /** @var bool  生SQLテキストを statement_text フィールドに保存するか */
    public $captureText = false;

    /** @var bool  write ops から effects[] を集計するか */
    public $captureEffects = true;

    /** @var int  HMAC トークンの hex 桁数 */
    public $tokenHmacLength = 12;

    /** @var int  shape 生成の再帰深さ上限。超えたら '...' を返す */
    public $maxDepth = 10;

    /**
     * @var int  shape 生成の総ノード訪問数上限（深さ・幅の両方を制限）。
     *           超えたら '...' で打ち切る。大きな JSON レスポンスのメモリ対策。
     */
    public $maxShapeNodes = 10000;

    /**
     * @var int|null  timeline の最大イベント数。デフォルト 500。null = 無制限。
     *                超えた場合は以降のイベントを無視し errors[] に capture_failure を追記する。
     *                N+1 等の暴走リクエストで 1 行の JSONL が肥大化するのを防ぐ。
     */
    public $maxTimelineSize = 500;

    /**
     * @var bool  false で Collector の全メソッドを即時スキップ（shape 生成・HMAC 等も行わない）。
     *            NullSink より低コストな無効化手段。デフォルト true。
     */
    public $enabled = true;

    /**
     * @param string|null $secret
     * @param array       $options  上記パブリックプロパティへの上書きマップ
     */
    public function __construct($secret = null, array $options = [])
    {
        $this->secret = $secret;

        $known = [
            'keepKeys', 'keepHeaderKeys', 'keepResponseHeaderKeys', 'sqlValueAllowlist',
            'captureText', 'captureEffects',
            'tokenHmacLength', 'maxDepth', 'maxShapeNodes', 'maxTimelineSize',
            'enabled',
        ];
        foreach ($options as $key => $value) {
            if (in_array($key, $known, true)) {
                $this->$key = $value;
            }
        }
    }
}
