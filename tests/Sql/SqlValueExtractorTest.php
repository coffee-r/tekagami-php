<?php

namespace CoffeeR\Tekagami\Tests\Sql;

use CoffeeR\Tekagami\Sql\SqlValueExtractor;
use PHPUnit\Framework\TestCase;

class SqlValueExtractorTest extends TestCase
{
    /** @var SqlValueExtractor */
    private $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SqlValueExtractor();
    }

    // -------------------------------------------------------------------------
    // allowlist が空 / マッチなし
    // -------------------------------------------------------------------------

    public function testEmptyAllowlistReturnsEmpty()
    {
        $result = $this->extractor->extract(
            "INSERT INTO orders (status) VALUES ('shipped')",
            ['ORDERS'],
            []
        );
        $this->assertSame([], $result);
    }

    public function testNoMatchReturnsEmpty()
    {
        $result = $this->extractor->extract(
            "INSERT INTO orders (status) VALUES ('shipped')",
            ['ORDERS'],
            ['orders.total'] // 別の列
        );
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // INSERT
    // -------------------------------------------------------------------------

    public function testInsertWithQualifiedKey()
    {
        $result = $this->extractor->extract(
            "INSERT INTO orders (user_id, status, qty) VALUES (10, 'shipped', 3)",
            ['ORDERS'],
            ['orders.status']
        );
        $this->assertSame(
            ['ORDERS.STATUS' => ['redacted' => false, 'values' => ['shipped']]],
            $result
        );
    }

    public function testInsertWithUnqualifiedKeyResolvesTable()
    {
        // 列名だけの allowlist でも、テーブルが特定できていれば TABLE.COLUMN キーになる
        $result = $this->extractor->extract(
            "INSERT INTO orders (status) VALUES ('paid')",
            ['ORDERS'],
            ['status']
        );
        $this->assertSame(
            ['ORDERS.STATUS' => ['redacted' => false, 'values' => ['paid']]],
            $result
        );
    }

    // -------------------------------------------------------------------------
    // UPDATE SET
    // -------------------------------------------------------------------------

    public function testUpdateSetExtractsValue()
    {
        $result = $this->extractor->extract(
            "UPDATE orders SET status = 'cancelled' WHERE id = 5",
            ['ORDERS'],
            ['orders.status']
        );
        $this->assertSame(
            ['ORDERS.STATUS' => ['redacted' => false, 'values' => ['cancelled']]],
            $result
        );
    }

    // -------------------------------------------------------------------------
    // WHERE / 型変換 / 重複排除
    // -------------------------------------------------------------------------

    public function testWhereNumericValueTyped()
    {
        $result = $this->extractor->extract(
            "SELECT * FROM orders WHERE total >= 100000",
            ['ORDERS'],
            ['total']
        );
        // WHERE 句はテーブル特定が難しいため、修飾なし列は列名だけのキーになる。
        $this->assertArrayHasKey('TOTAL', $result);
        $this->assertSame([100000], $result['TOTAL']['values']);
        $this->assertIsInt($result['TOTAL']['values'][0]);
    }

    public function testWhereQualifiedColumnKeepsTable()
    {
        // t.col 形式で書かれていればテーブル修飾キーになる
        $result = $this->extractor->extract(
            "SELECT * FROM orders WHERE orders.total >= 100000",
            ['ORDERS'],
            ['orders.total']
        );
        $this->assertArrayHasKey('ORDERS.TOTAL', $result);
        $this->assertSame([100000], $result['ORDERS.TOTAL']['values']);
    }

    public function testDistinctValuesDeduplicated()
    {
        $result = $this->extractor->extract(
            "SELECT * FROM orders WHERE status = 'paid' OR status = 'paid' OR status = 'shipped'",
            ['ORDERS'],
            ['status']
        );
        $this->assertSame(['paid', 'shipped'], $result['STATUS']['values']);
    }

    public function testPlaceholdersIgnored()
    {
        // バインドプレースホルダ（? / :name）は実値ではないので抽出しない
        $result = $this->extractor->extract(
            "INSERT INTO orders (status, user_id) VALUES (?, :uid)",
            ['ORDERS'],
            ['orders.status', 'orders.user_id']
        );
        $this->assertSame([], $result);
    }

    public function testExtractColumnsKeepsBoundInsertColumns()
    {
        $result = $this->extractor->extractColumns(
            "INSERT INTO orders (status, user_id) VALUES (?, :uid)",
            ['ORDERS']
        );

        $this->assertSame([], $result['filter_columns']);
        $this->assertSame(['STATUS', 'USER_ID'], $result['write_columns']);
    }

    public function testExtractColumnsClassifiesSetAndWhereColumns()
    {
        $result = $this->extractor->extractColumns(
            "UPDATE orders SET status = ?, total = {parameter} WHERE id = :id AND orders.user_id >= ?",
            ['ORDERS']
        );

        $this->assertSame(['ID', 'ORDERS.USER_ID'], $result['filter_columns']);
        $this->assertSame(['STATUS', 'TOTAL'], $result['write_columns']);
    }

    public function testExtractColumnsSupportsOnHavingAndSqliteQuotes()
    {
        $result = $this->extractor->extractColumns(
            'SELECT * FROM [orders] o JOIN [users] u ON o.[user_id] = u.[id] HAVING [total] >= {parameter}',
            ['ORDERS', 'USERS']
        );

        $this->assertSame(['O.USER_ID', 'TOTAL'], $result['filter_columns']);
        $this->assertSame([], $result['write_columns']);
    }

    public function testQuotedColumnAndEscapedQuoteValue()
    {
        $result = $this->extractor->extract(
            "UPDATE orders SET status = 'it''s done' WHERE id = 1",
            ['ORDERS'],
            ['orders.status']
        );
        $this->assertSame(["it's done"], $result['ORDERS.STATUS']['values']);
    }
}
