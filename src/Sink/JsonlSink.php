<?php

namespace CoffeeR\Tekagami\Sink;

/**
 * tekagami-v1 レコードを JSONL ファイルに追記する Sink。
 * FILE_APPEND | LOCK_EX で並行リクエスト下でも行が混ざらない。
 */
class JsonlSink implements SinkInterface
{
    /** @var string */
    private $filePath;

    /**
     * @param string $filePath  書き出し先ファイルパス（存在しなければ作成される）
     */
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

    public function write(array $trace)
    {
        $json = json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('tekagami: failed to encode JSONL line: ' . json_last_error_msg());
        }

        $line   = $json . "\n";
        // @ で E_WARNING を抑制し、戻り値で失敗を検知して例外に変換する
        $result = @file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            throw new \RuntimeException('tekagami: failed to write to ' . $this->filePath);
        }
    }
}
