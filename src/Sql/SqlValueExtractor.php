<?php

namespace CoffeeR\Tekagami\Sql;

/**
 * SQL 文から allowlist に一致する列の値を抽出する内部クラス。
 *
 * best-effort（完全な SQL パーサではない）。
 * 対応するパターン:
 *   - WHERE / ON  : col = val, col < val など等値・比較系
 *   - INSERT INTO : (col1, col2) VALUES (val1, val2)
 *   - UPDATE SET  : col = val
 *
 * 抽出できなかった列や複雑なサブクエリは無視する（warnings には出さない）。
 */
class SqlValueExtractor
{
    /**
     * SQL 文から allowlist に一致する列の値を抽出する。
     *
     * @param string   $statement  実行された生の SQL（値が埋め込まれた形）
     * @param string[] $tables     SqlAnalyzer::extractTables() の出力（大文字）
     * @param array    $allowlist  ['table.column' または 'column'] 形式の文字列配列（大小無視）
     * @return array   スキーマ observed_values 形式:
     *                 { 'table.column' => ['redacted' => false, 'values' => [...]] }
     */
    public function extract($statement, array $tables, array $allowlist)
    {
        if (empty($allowlist)) {
            return [];
        }

        $pairs = $this->extractAllPairs($statement, $tables);

        if (empty($pairs)) {
            return [];
        }

        return $this->filterByAllowlist($pairs, $tables, $allowlist);
    }

    /**
     * allowlist・値の有無に関係なく、SQL に現れる列を分類して返す。
     *
     * 層Bフィンガープリント用の値非依存抽出。既存の実値抽出とは別経路にして、
     * バインドプレースホルダや {parameter} でも列名を落とさない。
     *
     * @param string   $statement  生 SQL または statement_normalized
     * @param string[] $tables
     * @return array  ['filter_columns' => string[], 'write_columns' => string[]]
     */
    public function extractColumns($statement, array $tables)
    {
        $writeColumns = array_merge(
            $this->columnsFromInsert($statement),
            $this->columnsFromSet($statement)
        );
        $filterColumns = $this->columnsFromWhere($statement);

        return [
            'filter_columns' => $this->uniqueSortedColumns($filterColumns),
            'write_columns'  => $this->uniqueSortedColumns($writeColumns),
        ];
    }

    /**
     * SQL 文からすべての列→値ペアを抽出する（テーブルは関連するものを補完）。
     *
     * @param string   $statement
     * @param string[] $tables
     * @return array  [['column' => string, 'table' => string|null, 'value' => mixed], ...]
     */
    private function extractAllPairs($statement, array $tables)
    {
        $pairs = [];

        // INSERT INTO t (col1, col2, ...) VALUES (val1, val2, ...)
        $insertPairs = $this->extractInsertPairs($statement, $tables);
        $pairs = array_merge($pairs, $insertPairs);

        // UPDATE SET col = val, col2 = val2
        $setPairs = $this->extractSetPairs($statement, $tables);
        $pairs = array_merge($pairs, $setPairs);

        // WHERE / ON col = val, col < val, ...
        $wherePairs = $this->extractWherePairs($statement);
        $pairs = array_merge($pairs, $wherePairs);

        return $pairs;
    }

    /**
     * INSERT INTO t (col1, col2) VALUES (val1, val2) のペアを抽出する。
     *
     * @param string   $statement
     * @param string[] $tables
     * @return array
     */
    private function extractInsertPairs($statement, array $tables)
    {
        // INSERT INTO table_name (col1, col2, ...) VALUES (val1, val2, ...)
        if (!preg_match(
            '/INSERT\s+INTO\s+(?:`[^`]+`|"[^"]+"|[\w.]+)\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/is',
            $statement,
            $m
        )) {
            return [];
        }

        $columns = $this->splitList($m[1]);
        $rawVals = $this->splitList($m[2]);

        if (count($columns) !== count($rawVals)) {
            return [];
        }

        $table = $tables[0] ?? null;
        $pairs = [];
        foreach ($columns as $i => $col) {
            $col   = $this->stripQuotes($col);
            $value = $this->parseLiteral($rawVals[$i]);
            if ($value !== null) {
                $pairs[] = ['column' => strtoupper($col), 'table' => $table, 'value' => $value];
            }
        }
        return $pairs;
    }

    /**
     * INSERT INTO t (col1, col2) の列リストだけを抽出する。
     *
     * @param string $statement
     * @return string[]
     */
    private function columnsFromInsert($statement)
    {
        if (!preg_match(
            '/INSERT\s+INTO\s+(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[\w.]+)\s*\(([^)]+)\)\s*VALUES\b/is',
            $statement,
            $m
        )) {
            return [];
        }

        $columns = [];
        foreach ($this->splitList($m[1]) as $col) {
            $columns[] = $this->normalizeColumnName($col);
        }
        return $columns;
    }

    /**
     * UPDATE ... SET col1 = val1, col2 = val2 のペアを抽出する。
     *
     * @param string   $statement
     * @param string[] $tables
     * @return array
     */
    private function extractSetPairs($statement, array $tables)
    {
        // SET 句を取り出す（WHERE で終わる or 末尾まで）
        if (!preg_match('/\bSET\b(.+?)(?:\bWHERE\b|$)/is', $statement, $m)) {
            return [];
        }

        $table = $tables[0] ?? null;
        return $this->parseAssignments($m[1], $table);
    }

    /**
     * SET 句の代入左辺だけを抽出する。
     *
     * @param string $statement
     * @return string[]
     */
    private function columnsFromSet($statement)
    {
        if (!preg_match('/\bSET\b(.+?)(?:\bWHERE\b|$)/is', $statement, $m)) {
            return [];
        }

        return $this->columnsFromAssignments($m[1]);
    }

    /**
     * WHERE / ON / HAVING の col = val 等を抽出する。
     *
     * テーブルの特定は難しいため table = null で返す（allowlist の列名のみ一致）。
     *
     * @param string $statement
     * @return array
     */
    private function extractWherePairs($statement)
    {
        // WHERE / ON / HAVING 句の後を取り出す
        if (!preg_match('/\b(?:WHERE|ON|HAVING)\b(.+)/is', $statement, $m)) {
            return [];
        }

        return $this->parseAssignments($m[1], null);
    }

    /**
     * WHERE / ON / HAVING 句の比較左辺だけを抽出する。
     *
     * @param string $statement
     * @return string[]
     */
    private function columnsFromWhere($statement)
    {
        if (!preg_match_all('/\b(?:WHERE|ON|HAVING)\b(.+?)(?=\b(?:GROUP\s+BY|ORDER\s+BY|LIMIT|UNION|WHERE|ON|HAVING)\b|$)/is', $statement, $matches)) {
            return [];
        }

        $columns = [];
        foreach ($matches[1] as $section) {
            $columns = array_merge($columns, $this->columnsFromAssignments($section));
        }
        return $columns;
    }

    /**
     * 比較・代入式の左辺識別子を抽出する。
     *
     * @param string $text
     * @return string[]
     */
    private function columnsFromAssignments($text)
    {
        $ident = '(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[a-zA-Z_][a-zA-Z0-9_$#]*)';
        $qualified = $ident . '(?:\s*\.\s*' . $ident . ')?';
        $pattern = '/(' . $qualified . ')\s*(?:=|<>|!=|<=|>=|<|>)\s*/i';

        if (!preg_match_all($pattern, $text, $matches)) {
            return [];
        }

        $columns = [];
        foreach ($matches[1] as $col) {
            $columns[] = $this->normalizeColumnName($col);
        }
        return $columns;
    }

    /**
     * 識別子クォートを外して大文字化する。テーブル修飾は呼び出し側で扱う。
     *
     * @param string $name
     * @return string
     */
    private function normalizeColumnName($name)
    {
        $parts = preg_split('/\s*\.\s*/', trim($name));
        $normalized = [];
        foreach ($parts as $part) {
            $normalized[] = strtoupper($this->stripQuotes($part));
        }
        return implode('.', $normalized);
    }

    /**
     * @param string[] $columns
     * @return string[]
     */
    private function uniqueSortedColumns(array $columns)
    {
        $result = [];
        foreach ($columns as $column) {
            if ($column === '') {
                continue;
            }
            $result[] = $column;
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * "col = val, col2 = val2, ..." 形式の文字列からペアを抽出する。
     *
     * @param string      $text
     * @param string|null $table
     * @return array
     */
    private function parseAssignments($text, $table)
    {
        $pairs = [];
        // col = 'string' | col = 123 | col = 123.45 のパターン
        $pattern = '/(?:`([^`]+)`|"([^"]+)"|([a-zA-Z_]\w*(?:\.[a-zA-Z_]\w*)?))' // column
                 . '\s*[=<>!]{1,2}\s*'                                            // operator
                 . '(\'(?:[^\']|\'\')*\'|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/i';     // value（'' エスケープ）

        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $m) {
            $col   = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : $m[3]);
            $col   = strtoupper($col);
            // テーブル修飾 (t.column) がある場合はテーブル部分を分離
            $colTable = null;
            if (strpos($col, '.') !== false) {
                list($colTable, $col) = explode('.', $col, 2);
            } else {
                $colTable = $table;
            }
            $value = $this->parseLiteral($m[4]);
            if ($value !== null) {
                $pairs[] = ['column' => $col, 'table' => $colTable, 'value' => $value];
            }
        }

        return $pairs;
    }

    /**
     * allowlist に一致する列のみ observed_values 形式に変換する。
     *
     * @param array    $pairs      extractAllPairs() の出力
     * @param string[] $tables     テーブル名（大文字）
     * @param array    $allowlist
     * @return array
     */
    private function filterByAllowlist(array $pairs, array $tables, array $allowlist)
    {
        // allowlist を正規化（大文字）
        $normalizedAllowlist = array_map('strtoupper', $allowlist);

        $result = [];
        foreach ($pairs as $pair) {
            $col   = $pair['column'];
            $tbl   = $pair['table'];
            $value = $pair['value'];

            // 'TABLE.COLUMN' または 'COLUMN' でマッチするか確認
            $qualifiedKey   = $tbl ? ($tbl . '.' . $col) : null;
            $unqualifiedKey = $col;

            $matchKey = null;
            if ($qualifiedKey && in_array($qualifiedKey, $normalizedAllowlist, true)) {
                $matchKey = $qualifiedKey;
            } elseif (in_array($unqualifiedKey, $normalizedAllowlist, true)) {
                // テーブル名が特定できている場合は 'TABLE.COLUMN' をキーにする
                $matchKey = $tbl ? ($tbl . '.' . $col) : $col;
            }

            if ($matchKey === null) {
                continue;
            }

            if (!isset($result[$matchKey])) {
                $result[$matchKey] = ['redacted' => false, 'values' => []];
            }
            // 重複しない値のみ追加
            if (!in_array($value, $result[$matchKey]['values'], true)) {
                $result[$matchKey]['values'][] = $value;
            }
        }

        return $result;
    }

    /**
     * リテラル文字列を PHP 値に変換する。
     * - 'string' → string
     * - 123 → int
     * - 3.14 → float
     * - プレースホルダ / NULL / 変換不能 → null
     *
     * @param string $raw
     * @return mixed|null
     */
    private function parseLiteral($raw)
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === '?' || strtoupper($raw) === 'NULL') {
            return null;
        }
        // 名前付きバインド
        if (preg_match('/^:[a-zA-Z_]/', $raw)) {
            return null;
        }

        // シングルクォート文字列（'' エスケープを ' に戻す）
        if (preg_match("/^'((?:[^']|'')*)'$/", $raw, $m)) {
            return str_replace("''", "'", $m[1]);
        }

        // 数値
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int)$raw;
        }
        if (preg_match('/^-?\d+\.\d+(?:[eE][+-]?\d+)?$/', $raw)) {
            return (float)$raw;
        }

        return null;
    }

    /**
     * カンマ区切りリストをトリム付きで分割する（括弧内のカンマは無視しない簡易版）。
     *
     * @param string $text
     * @return string[]
     */
    private function splitList($text)
    {
        return array_map('trim', explode(',', $text));
    }

    /**
     * バッククォート・ダブルクォート・SQLite 角括弧の識別子クォートを除去する。
     *
     * @param string $name
     * @return string
     */
    private function stripQuotes($name)
    {
        $name = trim($name);
        if (
            (substr($name, 0, 1) === '`' && substr($name, -1) === '`') ||
            (substr($name, 0, 1) === '"' && substr($name, -1) === '"') ||
            (substr($name, 0, 1) === '[' && substr($name, -1) === ']')
        ) {
            return substr($name, 1, -1);
        }
        return $name;
    }
}
