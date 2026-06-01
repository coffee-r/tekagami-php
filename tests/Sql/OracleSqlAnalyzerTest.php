<?php

namespace CoffeeR\Tekagami\Tests\Sql;

use CoffeeR\Tekagami\Sql\OracleSqlAnalyzer;
use PHPUnit\Framework\TestCase;

class OracleSqlAnalyzerTest extends TestCase
{
    /** @var OracleSqlAnalyzer */
    private $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new OracleSqlAnalyzer();
    }

    // -------------------------------------------------------------------------
    // 文字列リテラル（'' エスケープ・代替引用符・各国語）
    // -------------------------------------------------------------------------

    public function testNormalizeDoubledQuoteIsSingleLiteral()
    {
        // 'it''s' は単一の文字列リテラル → {parameter} 1個
        $this->assertSame(
            'SELECT * FROM t WHERE name = {parameter}',
            $this->analyzer->normalize("SELECT * FROM t WHERE name = 'it''s'")
        );
    }

    public function testNormalizeQQuoteBracket()
    {
        $this->assertSame(
            'SELECT * FROM t WHERE x = {parameter}',
            $this->analyzer->normalize("SELECT * FROM t WHERE x = q'[a'b]'")
        );
    }

    public function testNormalizeQQuoteBrace()
    {
        $this->assertSame(
            'INSERT INTO t (c) VALUES ({parameter})',
            $this->analyzer->normalize("INSERT INTO t (c) VALUES (q'{he said ''hi''}')")
        );
    }

    public function testNormalizeNationalString()
    {
        $this->assertSame(
            'SELECT * FROM t WHERE name = {parameter}',
            $this->analyzer->normalize("SELECT * FROM t WHERE name = N'Jose'")
        );
    }

    // -------------------------------------------------------------------------
    // テーブル抽出
    // -------------------------------------------------------------------------

    public function testTablesExcludesDual()
    {
        $this->assertSame([], $this->analyzer->extractTables('SELECT 1 FROM dual'));
    }

    public function testAnalysisWarnsForDualSelect()
    {
        $analysis = $this->analyzer->buildAnalysis('SELECT shop_orders_seq.NEXTVAL FROM dual', 'SELECT', [], 'intercepted');
        $this->assertContains('oracle_dual_select', $analysis['warnings']);
    }

    public function testAnalysisWarnsForRownumFilter()
    {
        $sql = 'select * from (select * from "SHOP_PRODUCTS" where "CODE" = ?) where rownum = ?';
        $analysis = $this->analyzer->buildAnalysis($sql, 'SELECT', ['SHOP_PRODUCTS'], 'app');
        $this->assertContains('oracle_rownum_bounded', $analysis['warnings']);
    }

    public function testAnalysisNoRownumWarningWhenAbsent()
    {
        $sql = 'SELECT * FROM shop_products WHERE code = ?';
        $analysis = $this->analyzer->buildAnalysis($sql, 'SELECT', ['SHOP_PRODUCTS'], 'app');
        $this->assertNotContains('oracle_rownum_bounded', $analysis['warnings']);
    }

    public function testAnalysisRownumInInlineFilter()
    {
        $sql = 'SELECT 1 AS found FROM shop_cart_items WHERE cart_id = ? AND ROWNUM = ?';
        $analysis = $this->analyzer->buildAnalysis($sql, 'SELECT', ['SHOP_CART_ITEMS'], 'app');
        $this->assertContains('oracle_rownum_bounded', $analysis['warnings']);
    }

    public function testTablesWithHintComment()
    {
        $this->assertSame(['T'], $this->analyzer->extractTables('/*+ INDEX(t idx) */ SELECT * FROM t'));
    }

    public function testTablesDblinkStripped()
    {
        $this->assertSame(['ORDERS'], $this->analyzer->extractTables('SELECT * FROM orders@remote'));
    }

    public function testTablesSchemaQualifiedDblinkStripped()
    {
        $this->assertSame(['SHOP.ORDERS'], $this->analyzer->extractTables('SELECT * FROM shop.orders@remote WHERE id = 1'));
    }

    public function testTablesMergeInto()
    {
        $sql = 'MERGE INTO targets t USING sources s ON (t.id = s.id) WHEN MATCHED THEN UPDATE SET t.v = s.v';
        $this->assertContains('TARGETS', $this->analyzer->extractTables($sql));
    }

    // -------------------------------------------------------------------------
    // 操作判定
    // -------------------------------------------------------------------------

    public function testOperationHintThenSelect()
    {
        $this->assertSame('SELECT', $this->analyzer->extractOperation('/*+ FULL(t) */ SELECT * FROM t'));
    }

    public function testOperationBeginBlockIsCall()
    {
        $this->assertSame('CALL', $this->analyzer->extractOperation('BEGIN my_proc(1); END;'));
    }

    public function testOperationDeclareBlockIsCall()
    {
        $this->assertSame('CALL', $this->analyzer->extractOperation('DECLARE x NUMBER; BEGIN my_proc(x); END;'));
    }

    public function testOperationMerge()
    {
        $this->assertSame('MERGE', $this->analyzer->extractOperation('MERGE INTO t USING s ON (1=1)'));
    }

    // -------------------------------------------------------------------------
    // analysis.dialect
    // -------------------------------------------------------------------------

    public function testAnalysisDialect()
    {
        $analysis = $this->analyzer->buildAnalysis('SELECT * FROM t', 'SELECT', ['T'], 'intercepted');
        $this->assertSame('oracle', $analysis['dialect']);
        $this->assertSame('regex', $analysis['analyzer']);
    }
}
