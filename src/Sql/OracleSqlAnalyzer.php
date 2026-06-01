<?php

namespace CoffeeR\Tekagami\Sql;

/**
 * Oracle 方言のアナライザ。
 *
 * 基底 {@see AbstractSqlAnalyzer} の標準 SQL 処理に加えて次を扱う:
 *   - 代替引用符 q'[...]'  q'{...}'  q'(...)'  q'<...>'
 *   - 各国語文字列リテラル N'...'
 *   - schema.table@dblink の dblink 部分を無視
 *   - PL/SQL 無名ブロック BEGIN ... / DECLARE ... を CALL 扱い
 *   - /∗+ hint ∗/ は先頭コメントスキップで吸収（extractOperation）
 *   - FROM dual はテーブルとして除外（基底の skip リスト）
 * 識別子は Oracle が暗黙に大文字化するため、抽出結果の大文字化と整合する。
 */
class OracleSqlAnalyzer extends AbstractSqlAnalyzer
{
    protected function dialectName()
    {
        return 'oracle';
    }

    protected function getStringLiteralPattern()
    {
        // 代替引用符（括弧4種）を標準/各国語リテラルより先に評価する。
        return "[qQ]'\\[[\\s\\S]*?\\]'"
             . "|[qQ]'\\{[\\s\\S]*?\\}'"
             . "|[qQ]'\\([\\s\\S]*?\\)'"
             . "|[qQ]'<[\\s\\S]*?>'"
             . "|[Nn]?'(?:[^']|'')*'";
    }

    protected function getOperationKeywords()
    {
        return parent::getOperationKeywords() . '|BEGIN|DECLARE';
    }

    protected function mapOperation($keyword)
    {
        if (in_array($keyword, ['BEGIN', 'DECLARE'], true)) {
            return 'CALL';
        }
        return parent::mapOperation($keyword);
    }

    public function buildAnalysis($statement, $operation, array $tables, $source)
    {
        $analysis = parent::buildAnalysis($statement, $operation, $tables, $source);
        if ($this->containsRownumFilter($statement)) {
            $analysis['warnings'][] = 'oracle_rownum_bounded';
        }
        return $analysis;
    }

    protected function containsRownumFilter($statement)
    {
        return (bool)preg_match('/\\bROWNUM\\b/i', $statement);
    }
}
