<?php

namespace CoffeeR\Tekagami\Tests;

use CoffeeR\Tekagami\Sql\SqliteSqlAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * AbstractSqlAnalyzer の方言非依存ロジックを SqliteSqlAnalyzer 経由で検証する。
 */
class SqlAnalyzerTest extends TestCase
{
    /** @var SqliteSqlAnalyzer */
    private $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SqliteSqlAnalyzer();
    }

    // -------------------------------------------------------------------------
    // normalize
    // -------------------------------------------------------------------------

    public function testNormalizeNumber()
    {
        $this->assertSame(
            'SELECT * FROM users WHERE id = {parameter}',
            $this->analyzer->normalize('SELECT * FROM users WHERE id = 42')
        );
    }

    public function testNormalizeString()
    {
        $this->assertSame(
            'SELECT * FROM products WHERE status = {parameter}',
            $this->analyzer->normalize("SELECT * FROM products WHERE status = 'active'")
        );
    }

    public function testNormalizeDoubledQuoteIsSingleLiteral()
    {
        // 標準 SQL の '' エスケープ: 'it''s' は単一リテラル → {parameter} 1個
        $this->assertSame(
            'SELECT * FROM products WHERE note = {parameter}',
            $this->analyzer->normalize("SELECT * FROM products WHERE note = 'it''s'")
        );
    }

    public function testNormalizeHex()
    {
        $this->assertSame(
            'SELECT * FROM t WHERE id = {parameter}',
            $this->analyzer->normalize('SELECT * FROM t WHERE id = 0xDEAD')
        );
    }

    public function testNormalizePositionalBind()
    {
        $this->assertSame(
            'SELECT * FROM t WHERE id = {parameter}',
            $this->analyzer->normalize('SELECT * FROM t WHERE id = ?')
        );
    }

    public function testNormalizeNamedBind()
    {
        $this->assertSame(
            'SELECT * FROM t WHERE id = {parameter}',
            $this->analyzer->normalize('SELECT * FROM t WHERE id = :user_id')
        );
    }

    public function testNormalizeInsertValues()
    {
        $this->assertSame(
            'INSERT INTO orders (uid, total) VALUES ({parameter}, {parameter})',
            $this->analyzer->normalize('INSERT INTO orders (uid, total) VALUES (123, 450.00)')
        );
    }

    public function testNormalizeInList()
    {
        $this->assertSame(
            'WHERE id IN ({parameter}, {parameter}, {parameter})',
            $this->analyzer->normalize('WHERE id IN (1, 2, 3)')
        );
    }

    public function testNormalizeNullInValuePosition()
    {
        $this->assertSame(
            'INSERT INTO orders (delivery_date, delivery_time) VALUES ({parameter}, {parameter})',
            $this->analyzer->normalize('INSERT INTO orders (delivery_date, delivery_time) VALUES (NULL, NULL)')
        );
    }

    public function testNormalizeIsNullPredicateIsPreserved()
    {
        $this->assertSame(
            'SELECT * FROM users WHERE deleted_at IS NULL AND archived_at IS NOT NULL',
            $this->analyzer->normalize('SELECT * FROM users WHERE deleted_at IS NULL AND archived_at IS NOT NULL')
        );
    }

    public function testNormalizeDbTimeLiterals()
    {
        $this->assertSame(
            'INSERT INTO logs (created_at, updated_at, touched_at) VALUES ({db_time}, {db_time}, {db_time})',
            $this->analyzer->normalize('INSERT INTO logs (created_at, updated_at, touched_at) VALUES (CURRENT_TIMESTAMP, CURRENT_DATE, NOW())')
        );
    }

    public function testNormalizeSequenceReferenceIsPreserved()
    {
        $this->assertSame(
            'SELECT shop_orders_seq.NEXTVAL AS id',
            $this->analyzer->normalize('SELECT shop_orders_seq.NEXTVAL AS id')
        );
    }

    public function testNormalizeWithLeadingComment()
    {
        $result = $this->analyzer->normalize('/* CI3 */SELECT * FROM users WHERE id = 1');
        $this->assertSame('/* CI3 */SELECT * FROM users WHERE id = {parameter}', $result);
    }

    public function testNormalizeStringWithNumberInside()
    {
        // 文字列内の数字は単一の {parameter} に変換される（文字列リテラル全体が対象）
        $result = $this->analyzer->normalize("WHERE name = '2024-01-01'");
        $this->assertSame('WHERE name = {parameter}', $result);
    }

    public function testNormalizeDoubleQuoteIdentifierUnchanged()
    {
        // ダブルクォートは SQL 識別子 → 正規化しない
        $result = $this->analyzer->normalize('SELECT "name" FROM "users" WHERE id = 1');
        $this->assertSame('SELECT "name" FROM "users" WHERE id = {parameter}', $result);
    }

    public function testNormalizeMultilinePreservesNewlines()
    {
        $sql    = "SELECT *\nFROM users\nWHERE id = 1";
        $result = $this->analyzer->normalize($sql);
        $this->assertSame("SELECT *\nFROM users\nWHERE id = {parameter}", $result);
    }

    public function testNormalizeDecimal()
    {
        $this->assertSame(
            'WHERE price > {parameter}',
            $this->analyzer->normalize('WHERE price > 1500.50')
        );
    }

    public function testNormalizeSamePatternSameHash()
    {
        $sql1 = "SELECT * FROM users WHERE id = 1 AND status = 'active'";
        $sql2 = "SELECT * FROM users WHERE id = 99 AND status = 'inactive'";
        $this->assertSame(
            $this->analyzer->hash($this->analyzer->normalize($sql1)),
            $this->analyzer->hash($this->analyzer->normalize($sql2))
        );
    }

    // -------------------------------------------------------------------------
    // extractOperation
    // -------------------------------------------------------------------------

    public function testOperationSelect()
    {
        $this->assertSame('SELECT', $this->analyzer->extractOperation('SELECT * FROM t'));
    }

    public function testOperationInsert()
    {
        $this->assertSame('INSERT', $this->analyzer->extractOperation('  insert into t values (1)'));
    }

    public function testOperationUpdate()
    {
        $this->assertSame('UPDATE', $this->analyzer->extractOperation('/* comment */ UPDATE t SET x = 1'));
    }

    public function testOperationDelete()
    {
        $this->assertSame('DELETE', $this->analyzer->extractOperation('DELETE FROM t WHERE id = 1'));
    }

    public function testOperationReplace()
    {
        $this->assertSame('REPLACE', $this->analyzer->extractOperation('REPLACE INTO t VALUES (1)'));
    }

    public function testOperationMerge()
    {
        $sql = 'MERGE INTO targets t USING sources s ON (t.id = s.id) WHEN MATCHED THEN UPDATE SET t.v = s.v';
        $this->assertSame('MERGE', $this->analyzer->extractOperation($sql));
    }

    public function testOperationCall()
    {
        $this->assertSame('CALL', $this->analyzer->extractOperation('CALL my_procedure(1, 2)'));
    }

    public function testOperationExecuteMapsToCall()
    {
        $this->assertSame('CALL', $this->analyzer->extractOperation('EXECUTE sp_get_user 42'));
    }

    public function testOperationExecMapsToCall()
    {
        $this->assertSame('CALL', $this->analyzer->extractOperation('EXEC sp_get_user 42'));
    }

    public function testOperationMultilineComment()
    {
        $sql = "/* comment\nmultiline */\nSELECT * FROM t";
        $this->assertSame('SELECT', $this->analyzer->extractOperation($sql));
    }

    public function testOperationUnknown()
    {
        $this->assertSame('UNKNOWN', $this->analyzer->extractOperation('unknown sql text'));
    }

    // -------------------------------------------------------------------------
    // extractTables
    // -------------------------------------------------------------------------

    public function testTablesSimpleFrom()
    {
        $this->assertSame(['USERS'], $this->analyzer->extractTables('SELECT * FROM users'));
    }

    public function testTablesJoin()
    {
        $sql    = 'SELECT o.id, u.name FROM orders o LEFT JOIN users u ON o.user_id = u.id';
        $tables = $this->analyzer->extractTables($sql);
        $this->assertContains('ORDERS', $tables);
        $this->assertContains('USERS', $tables);
        $this->assertCount(2, $tables);
    }

    public function testTablesInsertInto()
    {
        $this->assertSame(['ORDER_ITEMS'], $this->analyzer->extractTables('INSERT INTO order_items (id) VALUES (1)'));
    }

    public function testTablesUpdate()
    {
        $this->assertSame(['PRODUCTS'], $this->analyzer->extractTables('UPDATE products SET name = ?'));
    }

    public function testTablesDelete()
    {
        $this->assertSame(['SESSIONS'], $this->analyzer->extractTables('DELETE FROM sessions WHERE id = 1'));
    }

    public function testTablesSchemaQualified()
    {
        $this->assertSame(['SHOP.ORDERS'], $this->analyzer->extractTables('SELECT * FROM shop.orders'));
    }

    public function testTablesOracleSchemaQualified()
    {
        $this->assertSame(['SHOP.ORDERS'], $this->analyzer->extractTables('SELECT * FROM SHOP.ORDERS WHERE id = 1'));
    }

    public function testTablesBacktickStripped()
    {
        $this->assertSame(['USERS'], $this->analyzer->extractTables('SELECT * FROM `users`'));
    }

    public function testTablesDoubleQuoteStripped()
    {
        $this->assertSame(['USERS'], $this->analyzer->extractTables('SELECT * FROM "USERS"'));
    }

    public function testTablesOracleMerge()
    {
        $sql = 'MERGE INTO targets t USING sources s ON (t.id = s.id) WHEN MATCHED THEN UPDATE SET t.v = s.v';
        $this->assertContains('TARGETS', $this->analyzer->extractTables($sql));
    }

    // -------------------------------------------------------------------------
    // buildAnalysis
    // -------------------------------------------------------------------------

    public function testAnalysisQueryHistoryWarning()
    {
        $analysis = $this->analyzer->buildAnalysis('SELECT 1', 'SELECT', ['T'], 'query_history');
        $this->assertContains('query_history_capture_has_no_bind_values', $analysis['warnings']);
    }

    public function testAnalysisDbTimeWarning()
    {
        $analysis = $this->analyzer->buildAnalysis('SELECT CURRENT_TIMESTAMP', 'SELECT', [], 'intercepted');
        $this->assertContains('db_time_normalized', $analysis['warnings']);
    }

    public function testAnalysisTablesNotDetected()
    {
        $analysis = $this->analyzer->buildAnalysis('SELECT 1', 'SELECT', [], 'intercepted');
        $this->assertContains('tables_not_detected', $analysis['warnings']);
        $this->assertSame('unknown', $analysis['tables_confidence']);
    }

    public function testAnalysisStoredProcedureWarning()
    {
        $analysis = $this->analyzer->buildAnalysis('CALL my_proc(1)', 'CALL', [], 'intercepted');
        $this->assertContains('stored_procedure_tables_not_detectable', $analysis['warnings']);
        $this->assertSame('unknown', $analysis['tables_confidence']);
    }

    public function testAnalysisSubqueryBestEffort()
    {
        $sql      = 'SELECT * FROM t WHERE id IN (SELECT id FROM s)';
        $analysis = $this->analyzer->buildAnalysis($sql, 'SELECT', ['T', 'S'], 'intercepted');
        $this->assertSame('best_effort', $analysis['tables_confidence']);
    }

    public function testAnalysisHighConfidence()
    {
        $analysis = $this->analyzer->buildAnalysis('SELECT * FROM t', 'SELECT', ['T'], 'intercepted');
        $this->assertSame('high', $analysis['operation_confidence']);
        $this->assertSame('high', $analysis['tables_confidence']);
        $this->assertEmpty($analysis['warnings']);
    }

    public function testAnalysisUnknownOperation()
    {
        $analysis = $this->analyzer->buildAnalysis('unknown sql', 'UNKNOWN', [], 'intercepted');
        $this->assertSame('unknown', $analysis['operation_confidence']);
    }

    public function testAnalysisDialectAndAnalyzer()
    {
        $analysis = $this->analyzer->buildAnalysis('SELECT * FROM t', 'SELECT', ['T'], 'intercepted');
        $this->assertSame('sqlite', $analysis['dialect']);
        $this->assertSame('regex', $analysis['analyzer']);
    }
}
