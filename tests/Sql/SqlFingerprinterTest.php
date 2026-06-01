<?php

namespace CoffeeR\Tekagami\Tests\Sql;

use CoffeeR\Tekagami\Sql\SqlFingerprinter;
use PHPUnit\Framework\TestCase;

class SqlFingerprinterTest extends TestCase
{
    /** @var SqlFingerprinter */
    private $fingerprinter;

    protected function setUp(): void
    {
        $this->fingerprinter = new SqlFingerprinter();
    }

    public function testCi3RawSqlAndEloquentBoundSqlHaveSameFingerprint()
    {
        $ci3 = $this->fingerprinter->fingerprint(
            'SELECT * FROM orders WHERE id = 5',
            'SELECT',
            ['ORDERS'],
            'oracle'
        );
        $eloquent = $this->fingerprinter->fingerprint(
            'select * from "ORDERS" where "id" = ?',
            'SELECT',
            ['ORDERS'],
            'sqlite'
        );

        $this->assertSame($ci3['fp_hash'], $eloquent['fp_hash']);
        $this->assertSame(['ID'], $ci3['filter_columns']);
        $this->assertSame([], $ci3['write_columns']);
        $this->assertSame(['ORDERS'], $ci3['tables']);
    }

    public function testInsertColumnsBecomeWriteColumns()
    {
        $fp = $this->fingerprinter->fingerprint(
            'INSERT INTO orders (user_id, product_id, qty) VALUES (?, ?, ?)',
            'INSERT',
            ['ORDERS'],
            'oracle'
        );

        $this->assertSame([], $fp['filter_columns']);
        $this->assertSame(['PRODUCT_ID', 'QTY', 'USER_ID'], $fp['write_columns']);
        $this->assertStringStartsWith('fp1:', $fp['fp_hash']);
    }

    public function testUpdateSetAndWhereColumnsAreClassified()
    {
        $fp = $this->fingerprinter->fingerprint(
            'UPDATE orders SET status = ?, total = ? WHERE id = ?',
            'UPDATE',
            ['ORDERS'],
            'oracle'
        );

        $this->assertSame(['ID'], $fp['filter_columns']);
        $this->assertSame(['STATUS', 'TOTAL'], $fp['write_columns']);
    }

    public function testSchemaQualifiedTableAndPlainTableHashMatch()
    {
        $schema = $this->fingerprinter->fingerprint(
            'SELECT * FROM SHOP.ORDERS WHERE id = ?',
            'SELECT',
            ['SHOP.ORDERS'],
            'oracle'
        );
        $plain = $this->fingerprinter->fingerprint(
            'SELECT * FROM ORDERS WHERE id = ?',
            'SELECT',
            ['ORDERS'],
            'sqlite'
        );

        $this->assertSame($schema['fp_hash'], $plain['fp_hash']);
        $this->assertSame(['SHOP.ORDERS'], $schema['tables']);
    }

    public function testSqliteBracketQuotedColumnsNormalize()
    {
        $sqlite = $this->fingerprinter->fingerprint(
            'SELECT * FROM [ORDERS] WHERE [id] = ?',
            'SELECT',
            ['ORDERS'],
            'sqlite'
        );
        $plain = $this->fingerprinter->fingerprint(
            'SELECT * FROM ORDERS WHERE id = 1',
            'SELECT',
            ['ORDERS'],
            'oracle'
        );

        $this->assertSame($plain['fp_hash'], $sqlite['fp_hash']);
        $this->assertSame(['ID'], $sqlite['filter_columns']);
    }

    public function testOracleRownumExcludedFromFpButPreservedInNonOracle()
    {
        $withRownum = $this->fingerprinter->fingerprint(
            'select * from (select * from "SHOP_PRODUCTS" where "CODE" = ?) where rownum = ?',
            'SELECT',
            ['SHOP_PRODUCTS'],
            'oracle'
        );
        $withoutRownum = $this->fingerprinter->fingerprint(
            'SELECT * FROM shop_products WHERE code = ?',
            'SELECT',
            ['SHOP_PRODUCTS'],
            'oracle'
        );

        $this->assertSame($withoutRownum['fp_hash'], $withRownum['fp_hash']);
        $this->assertNotContains('ROWNUM', $withRownum['filter_columns']);
    }

    public function testRownumKeptInFilterColumnsForNonOracleDialect()
    {
        $fp = $this->fingerprinter->fingerprint(
            'SELECT * FROM t WHERE id = ? AND rownum = ?',
            'SELECT',
            ['T'],
            'sqlite'
        );

        $this->assertContains('ROWNUM', $fp['filter_columns']);
    }
}
