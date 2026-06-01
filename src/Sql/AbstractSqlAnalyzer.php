<?php

namespace CoffeeR\Tekagami\Sql;

/**
 * 方言非依存の共通ロジックを集約した基底クラス。
 *
 * 正規化・トークン化・テーブル抽出・analysis 生成のアルゴリズムをここに置き、
 * RDBMS ごとに変わる部分だけを protected フック（{@see getStringLiteralPattern()} 等）
 * として切り出す。デフォルトのフック実装は標準 SQL に従う。
 * 正規表現ベースの best-effort 実装（完全な SQL パーサではない）。
 */
abstract class AbstractSqlAnalyzer implements SqlAnalyzerInterface
{
    // -------------------------------------------------------------------------
    // 方言フック（サブクラスで上書き）
    // -------------------------------------------------------------------------

    /**
     * 文字列リテラルにマッチする正規表現フラグメント（キャプチャグループ禁止）。
     * 標準 SQL ではクォート2連 '' をエスケープとして扱う。
     *
     * @return string
     */
    protected function getStringLiteralPattern()
    {
        return "'(?:[^']|'')*'";
    }

    /**
     * 1 つの識別子（クォート済み or 素）にマッチする正規表現フラグメント。
     * キャプチャグループを含めてはならない（extractTables のグループ番号がずれるため）。
     *
     * @return string
     */
    protected function getIdentTokenPattern()
    {
        // ダブルクォート / バッククォート識別子 または 素の識別子（Oracle 互換で $ # も許可）。
        // バッククォートは標準 SQL ではないが、best-effort 抽出では許容しても無害。
        return '(?:"[^"]+"|`[^`]+`|[\\w$#]+)';
    }

    /**
     * 識別子の囲みクォートを除去する。
     *
     * @param string $raw
     * @return string
     */
    protected function stripIdentifierQuotes($raw)
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2) {
            $first = $raw[0];
            $last  = substr($raw, -1);
            if (($first === '"' && $last === '"') || ($first === '`' && $last === '`')) {
                return substr($raw, 1, -1);
            }
        }
        return $raw;
    }

    /**
     * extractOperation が認識する操作キーワードの正規表現フラグメント。
     *
     * @return string
     */
    protected function getOperationKeywords()
    {
        return 'SELECT|INSERT|UPDATE|DELETE|MERGE|REPLACE|UPSERT|CALL|EXECUTE|EXEC';
    }

    /**
     * 検出した生キーワードを正規化された操作名に写像する。
     *
     * @param string $keyword  大文字のキーワード
     * @return string
     */
    protected function mapOperation($keyword)
    {
        return in_array($keyword, ['EXECUTE', 'EXEC'], true) ? 'CALL' : $keyword;
    }

    /**
     * analysis.dialect に記録する方言識別子。
     *
     * @return string
     */
    abstract protected function dialectName();

    // -------------------------------------------------------------------------
    // SqlAnalyzerInterface 実装（方言非依存）
    // -------------------------------------------------------------------------

    /**
     * 全リテラル・プレースホルダにマッチする統合 regex。
     * 順序が重要: 文字列リテラルを先に評価して数値の二重置換を防ぐ。
     *
     * @return string
     */
    protected function getLiteralPattern()
    {
        return '/' . $this->getStringLiteralPattern()
             . '|' . $this->getDbTimePattern()
             . '|\\b0x[0-9a-fA-F]+\\b'                       // 16進数リテラル
             . '|\\b\\d+(?:\\.\\d+)?(?:[eE][+-]?\\d+)?\\b'   // 数値リテラル
             . '|:[a-zA-Z_][a-zA-Z0-9_]*'                    // 名前付きバインド :name
             . '|\\?'                                        // 位置バインド ?
             . '|\\bNULL\\b/i';                              // 値位置の NULL
    }

    /**
     * DB が実行時に生成する時刻値にマッチする正規表現フラグメント。
     *
     * @return string
     */
    protected function getDbTimePattern()
    {
        return '\\b(?:SYSTIMESTAMP|SYSDATE|CURRENT_DATE|CURRENT_TIMESTAMP(?:\\s*\\(\\s*\\d+\\s*\\))?)\\b'
             . '|\\bNOW\\s*\\(\\s*\\)';
    }

    public function normalize($statement)
    {
        list($protected, $restore) = $this->protectIsNullPredicates($statement);
        $normalized = preg_replace_callback($this->getLiteralPattern(), function ($m) {
            return $this->isDbTimeLiteral($m[0]) ? '{db_time}' : '{parameter}';
        }, $protected);

        return strtr($normalized, $restore);
    }

    public function replaceWithCallback($statement, callable $replacer)
    {
        list($protected, $restore) = $this->protectIsNullPredicates($statement);
        $replaced = preg_replace_callback($this->getLiteralPattern(), function ($m) use ($replacer) {
            return $this->isDbTimeLiteral($m[0]) ? '{db_time}' : $replacer($m[0]);
        }, $protected);

        return strtr($replaced, $restore);
    }

    public function hash($normalized)
    {
        return 'sha256:' . hash('sha256', $normalized);
    }

    public function extractOperation($statement)
    {
        $pattern = '/^\\s*(?:\\/\\*.*?\\*\\/\\s*)*(' . $this->getOperationKeywords() . ')\\b/is';
        if (!preg_match($pattern, $statement, $matches)) {
            return 'UNKNOWN';
        }
        return $this->mapOperation(strtoupper($matches[1]));
    }

    public function extractTables($statement)
    {
        $ident = $this->getIdentTokenPattern();

        // FROM/JOIN/INTO/UPDATE の後の  schema?.table  を抽出する。
        // ident フラグメントはキャプチャグループを含まない契約なので、
        // schema=グループ1 / table=グループ2 が方言を問わず安定する。
        // 末尾の @dblink は消費するが捨てる。
        $pattern = '/\\b(?:FROM|JOIN|INTO|UPDATE)\\s+(' . $ident . ')'
                 . '(?:\\s*\\.\\s*(' . $ident . '))?'
                 . '(?:@\\w+)?/i';

        if (!preg_match_all($pattern, $statement, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $skip   = ['SELECT', 'WHERE', 'SET', 'ON', 'VALUES', 'USING', 'ONLY', 'DUAL'];
        $tables = [];

        foreach ($matches as $m) {
            $schema = strtoupper($this->stripIdentifierQuotes($m[1]));
            $table  = isset($m[2]) && $m[2] !== ''
                ? strtoupper($this->stripIdentifierQuotes($m[2]))
                : '';

            if ($schema === '') {
                continue;
            }

            $name = $table !== '' ? $schema . '.' . $table : $schema;

            if (in_array($name, $skip, true)) {
                continue;
            }

            $tables[] = $name;
        }

        return array_values(array_unique($tables));
    }

    public function buildAnalysis($statement, $operation, array $tables, $source)
    {
        $warnings = [];

        if ($source === 'query_history') {
            $warnings[] = 'query_history_capture_has_no_bind_values';
        }

        if ($this->containsDbTimeLiteral($statement)) {
            $warnings[] = 'db_time_normalized';
        }

        if ($this->containsDualSelect($statement)) {
            $warnings[] = 'oracle_dual_select';
        }

        // サブクエリ検出: 2つ目以降の SELECT を探す
        $hasSubquery = (bool)preg_match('/\\bSELECT\\b.+\\bSELECT\\b/is', $statement);

        $tablesConfidence = 'high';
        if (empty($tables)) {
            $warnings[]       = 'tables_not_detected';
            $tablesConfidence = 'unknown';
        } elseif ($hasSubquery) {
            $tablesConfidence = 'best_effort';
        }

        if ($operation === 'CALL') {
            $warnings[]       = 'stored_procedure_tables_not_detectable';
            $tablesConfidence = 'unknown';
        }

        return [
            'analyzer'             => 'regex',
            'dialect'              => $this->dialectName(),
            'operation_confidence' => $operation !== 'UNKNOWN' ? 'high' : 'unknown',
            'tables_confidence'    => $tablesConfidence,
            'warnings'             => $warnings,
        ];
    }

    /**
     * IS NULL / IS NOT NULL は条件の意味なので NULL 正規化の対象から外す。
     *
     * @param string $statement
     * @return array{0:string,1:array}
     */
    private function protectIsNullPredicates($statement)
    {
        $restore = [];
        $index   = 0;
        $protected = preg_replace_callback('/\\bIS\\s+(?:NOT\\s+)?NULL\\b/i', function ($m) use (&$restore, &$index) {
            $token = '__TEKAGAMIISNULL' . $this->letters($index++) . '__';
            $restore[$token] = $m[0];
            return $token;
        }, $statement);

        return [$protected, $restore];
    }

    /**
     * @param int $index
     * @return string
     */
    private function letters($index)
    {
        $letters = '';
        do {
            $letters = chr(65 + ($index % 26)) . $letters;
            $index = (int)floor($index / 26) - 1;
        } while ($index >= 0);
        return $letters;
    }

    /**
     * @param string $value
     * @return bool
     */
    protected function isDbTimeLiteral($value)
    {
        return (bool)preg_match('/^(?:' . $this->getDbTimePattern() . ')$/i', trim($value));
    }

    /**
     * @param string $statement
     * @return bool
     */
    protected function containsDbTimeLiteral($statement)
    {
        return (bool)preg_match('/' . $this->getDbTimePattern() . '/i', $statement);
    }

    /**
     * @param string $statement
     * @return bool
     */
    protected function containsDualSelect($statement)
    {
        return (bool)preg_match('/\\bFROM\\s+DUAL\\b/i', $statement);
    }
}
