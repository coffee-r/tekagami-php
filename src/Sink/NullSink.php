<?php

namespace CoffeeR\Tekagami\Sink;

/**
 * 何も書き出さない Sink。テスト・無効化用。
 */
class NullSink implements SinkInterface
{
    public function write(array $trace)
    {
        // no-op
    }
}
