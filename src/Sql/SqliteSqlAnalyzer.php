<?php

namespace CoffeeR\Tekagami\Sql;

/**
 * SQLite 方言のアナライザ。
 *
 * 文字列リテラルは標準 SQL と同じくクォート2連 '' をエスケープとして扱う。
 * 識別子クォートは "x" / `x` / [x] の3種を許可する。
 * INSERT OR REPLACE / REPLACE は基底の操作判定でそのまま INSERT / REPLACE に
 * 落ちる（どちらも effects の書き込み操作として認識される）。
 */
class SqliteSqlAnalyzer extends AbstractSqlAnalyzer
{
    protected function dialectName()
    {
        return 'sqlite';
    }

    protected function getIdentTokenPattern()
    {
        // "x" / `x` / [x] / 素の識別子。キャプチャグループは使わない。
        return '(?:"[^"]+"|`[^`]+`|\\[[^\\]]+\\]|[\\w$#]+)';
    }

    protected function stripIdentifierQuotes($raw)
    {
        $raw = trim($raw);
        if (strlen($raw) < 2) {
            return $raw;
        }
        $first = $raw[0];
        $last  = substr($raw, -1);
        if (($first === '"' && $last === '"')
            || ($first === '`' && $last === '`')
            || ($first === '[' && $last === ']')
        ) {
            return substr($raw, 1, -1);
        }
        return $raw;
    }
}
