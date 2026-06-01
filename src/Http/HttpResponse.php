<?php

namespace CoffeeR\Tekagami\Http;

/**
 * HTTP レスポンスデータの値オブジェクト。HttpInput と対称。
 * Collector::finish() に渡す。アダプタがレスポンス送出後に構築する。
 * views はリクエスト中にアダプタがローカルで蓄積し、finish() 時にまとめて渡す。
 */
class HttpResponse
{
    /** @var int|null  HTTP レスポンスステータスコード */
    public $status = null;

    /**
     * @var string|null  レスポンス種別。
     *                   'json'  = JSON ボディを responseBodyRaw に持つ
     *                   'html'  = HTML レスポンス。ボディは記録せず views[] を使う
     *                   'other' = その他
     *                   null    = 不明
     */
    public $responseKind = null;

    /** @var string|null  レスポンス Content-Type ヘッダ値 */
    public $contentType = null;

    /**
     * @var array|null  レスポンスヘッダのキー/値マップ。
     *                  null = レスポンスヘッダを記録しない。
     */
    public $responseHeadersRaw = null;

    /**
     * @var mixed  JSON レスポンスボディの生の値 (json_decode 済み)。
     *             Collector が shape を生成する。
     *             null = 非JSONレスポンス、または記録しない。
     */
    public $responseBodyRaw = null;

    /**
     * @var array  レンダリングされた HTML ビューの記録。responseKind='html' のとき使う。
     *             アダプタがレンダリング発生のたびにエントリを追加し、finish() 時に渡す。
     *             各エントリのキー:
     *               'template' string  テンプレートファイルパス (views ルートからの相対パス)
     *               'vars_raw' mixed   ビューに渡した変数の生の値。Collector が shape を生成する。
     *             seq は Collector が採番するため、各エントリに渡さない。
     */
    public $views = [];
}
