<?php

namespace CoffeeR\Tekagami\Http;

/**
 * HTTP リクエスト入力データの値オブジェクト。
 * Collector::start() に渡す。アダプタがフレームワークのリクエストオブジェクトから値を取り出して構築する。
 * shape/token 生成は Collector が内部で行うため、ここでは生の値のみを保持する。
 */
class HttpInput
{
    /** @var string  HTTP動詞 (大文字)。例: 'GET' */
    public $method;

    /** @var string  実際のリクエストパス。例: '/products/123' */
    public $path;

    /**
     * @var string|null  マッチしたルートパターン。例: '/products/{id}'。
     *                   アダプタがフレームワークのルート定義から解決する。不明なら null。
     */
    public $pathPattern = null;

    /**
     * @var array|null  URLクエリパラメータの生の値。$_GET 相当。
     *                  null = クエリ文字列なし、または記録しない。
     */
    public $queryRaw = null;

    /**
     * @var mixed  リクエストボディの生の値。JSON は json_decode 済み、form-encoded は配列。
     *             null = body なし、または記録しない。
     */
    public $requestRaw = null;

    /**
     * @var array|null  リクエストヘッダのキー/値マップ。
     *                  null = ヘッダを記録しない。
     */
    public $requestHeadersRaw = null;

    /**
     * @param string $method  HTTP動詞 (大文字)
     * @param string $path    実際のリクエストパス
     */
    public function __construct($method, $path)
    {
        $this->method = $method;
        $this->path   = $path;
    }
}
