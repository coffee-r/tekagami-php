<?php

namespace CoffeeR\Tekagami\Sql;

/**
 * SQL 文の正規化・分析を行う方言別アナライザの契約。
 *
 * Collector はこのインターフェースにのみ依存する。RDBMS ごとの差異
 * （文字列リテラルのエスケープ規則・識別子クォート・操作キーワード）は
 * 実装側に閉じ込める。同梱実装は {@see OracleSqlAnalyzer} / {@see SqliteSqlAnalyzer}。
 * 他の RDBMS を扱う場合は本インターフェース（または {@see AbstractSqlAnalyzer}）を
 * 実装して Collector に注入する。
 */
interface SqlAnalyzerInterface
{
    /**
     * SQL のすべてのリテラル・プレースホルダを {parameter} に置換する。
     *
     * @param string $statement
     * @return string
     */
    public function normalize($statement);

    /**
     * SQL のリテラルをコールバックで置換する（トークン化に使う）。
     *
     * @param string   $statement
     * @param callable $replacer  function(string $matched): string
     * @return string
     */
    public function replaceWithCallback($statement, callable $replacer);

    /**
     * @param string $normalized  normalize() の出力
     * @return string  'sha256:<hex>'
     */
    public function hash($normalized);

    /**
     * 先頭コメントを読み飛ばして最初の操作キーワードを検出する。
     *
     * @param string $statement
     * @return string  'SELECT' | 'INSERT' | ... | 'CALL' | 'UNKNOWN'
     */
    public function extractOperation($statement);

    /**
     * FROM / JOIN / INTO / UPDATE の後のテーブル名を best-effort で抽出する。
     *
     * @param string $statement
     * @return string[]  大文字・重複排除済みのテーブル名配列
     */
    public function extractTables($statement);

    /**
     * SQL イベントの analysis メタデータを生成する。
     *
     * @param string   $statement
     * @param string   $operation  extractOperation() の出力
     * @param string[] $tables     extractTables() の出力
     * @param string   $source     SQL の取得元識別子
     * @return array
     */
    public function buildAnalysis($statement, $operation, array $tables, $source);
}
