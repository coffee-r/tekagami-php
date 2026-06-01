<?php

namespace CoffeeR\Tekagami;

/**
 * 任意のフロー相関情報の値オブジェクト。
 * 開発者や QA が明示した調査用の流れを、複数の HTTP リクエスト間で紐づける。
 */
class Flow
{
    /**
     * @var string|null  任意の相関識別子。未指定の場合は null。
     *                   例: 調査用ヘッダ由来のトークン、QA シナリオ ID
     */
    public $flowId = null;

    /**
     * @var int|null  明示されたフロー内のステップ番号 (1-based)。未設定の場合は null。
     *                例: browse=1, add-to-cart=2, order=3
     */
    public $seq = null;

    /**
     * @param string|null $flowId
     * @param int|null    $seq
     */
    public function __construct($flowId = null, $seq = null)
    {
        $this->flowId = $flowId;
        $this->seq    = $seq;
    }
}
