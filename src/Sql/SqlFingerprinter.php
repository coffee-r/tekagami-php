<?php

namespace CoffeeR\Tekagami\Sql;

/**
 * SQL の意味レベル（層B）フィンガープリントを生成する内部クラス。
 *
 * 正規化 SQL 文字列そのものではなく、操作種別・対象テーブル・絞り込み列・書込列から
 * フレームワーク移行後も比較しやすい署名を作る。best-effort 実装。
 */
class SqlFingerprinter
{
    /** @var SqlValueExtractor */
    private $valueExtractor;

    /**
     * @param SqlValueExtractor|null $valueExtractor
     */
    public function __construct($valueExtractor = null)
    {
        $this->valueExtractor = $valueExtractor ?: new SqlValueExtractor();
    }

    /**
     * SQL の層Bフィンガープリントを生成する。
     *
     * @param string      $statement 生 SQL または statement_normalized
     * @param string      $operation extractOperation() の出力
     * @param string[]    $tables    extractTables() の出力
     * @param string|null $dialect
     * @return array
     */
    public function fingerprint($statement, $operation, array $tables, $dialect = null)
    {
        $op = (string)$operation;
        $normalizedTables = $this->normalizeTables($tables);

        try {
            $columns = $this->valueExtractor->extractColumns($statement, $tables);
            $filterColumns = $this->normalizeColumns(
                isset($columns['filter_columns']) ? $columns['filter_columns'] : []
            );
            $writeColumns = $this->normalizeColumns(
                isset($columns['write_columns']) ? $columns['write_columns'] : []
            );
        } catch (\Throwable $e) {
            $filterColumns = [];
            $writeColumns = [];
        }

        if ($dialect === 'oracle') {
            $filterColumns = $this->excludeOraclePseudoColumns($filterColumns);
            $writeColumns  = $this->excludeOraclePseudoColumns($writeColumns);
        }

        return [
            'op'             => $op,
            'tables'         => $normalizedTables,
            'filter_columns' => $filterColumns,
            'write_columns'  => $writeColumns,
            'fp_hash'        => $this->hashFingerprint($op, $normalizedTables, $filterColumns, $writeColumns),
        ];
    }

    /**
     * @param string[] $tables
     * @return string[]
     */
    private function normalizeTables(array $tables)
    {
        $result = [];
        foreach ($tables as $table) {
            $table = strtoupper(trim((string)$table));
            if ($table === '') {
                continue;
            }
            $result[] = $table;
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param string[] $columns
     * @return string[]
     */
    private function normalizeColumns(array $columns)
    {
        $result = [];
        foreach ($columns as $column) {
            $column = strtoupper(trim((string)$column));
            if ($column === '') {
                continue;
            }
            if (strpos($column, '.') !== false) {
                $parts = explode('.', $column);
                $column = end($parts);
            }
            $result[] = $column;
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param string[] $columns
     * @return string[]
     */
    private function excludeOraclePseudoColumns(array $columns)
    {
        return array_values(array_filter($columns, function ($c) {
            return $c !== 'ROWNUM' && $c !== 'ROWID';
        }));
    }

    /**
     * @param string   $op
     * @param string[] $tables
     * @param string[] $filterColumns
     * @param string[] $writeColumns
     * @return string
     */
    private function hashFingerprint($op, array $tables, array $filterColumns, array $writeColumns)
    {
        $canonicalTables = [];
        foreach ($tables as $table) {
            $canonicalTables[] = $this->tailSegment($table);
        }
        $canonicalTables = array_values(array_unique($canonicalTables));
        sort($canonicalTables, SORT_STRING);

        $canonical = [
            'op'             => $op,
            'tables'         => $canonicalTables,
            'filter_columns' => $filterColumns,
            'write_columns'  => $writeColumns,
        ];

        return 'fp1:' . hash(
            'sha256',
            json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param string $name
     * @return string
     */
    private function tailSegment($name)
    {
        if (strpos($name, '.') === false) {
            return $name;
        }
        $parts = explode('.', $name);
        return end($parts);
    }
}
