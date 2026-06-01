<?php

namespace CoffeeR\Tekagami\Tests\Sql;

use CoffeeR\Tekagami\Sql\SqliteSqlAnalyzer;
use PHPUnit\Framework\TestCase;

class SqliteSqlAnalyzerTest extends TestCase
{
    /** @var SqliteSqlAnalyzer */
    private $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SqliteSqlAnalyzer();
    }

    public function testNormalizeDoubledQuoteIsSingleLiteral()
    {
        $this->assertSame(
            'SELECT * FROM t WHERE name = {parameter}',
            $this->analyzer->normalize("SELECT * FROM t WHERE name = 'O''Brien'")
        );
    }

    public function testTablesBracketIdentifier()
    {
        $this->assertSame(['MY TABLE'], $this->analyzer->extractTables('SELECT * FROM [my table]'));
    }

    public function testTablesBacktickIdentifier()
    {
        $this->assertSame(['USERS'], $this->analyzer->extractTables('SELECT * FROM `users`'));
    }

    public function testTablesDoubleQuoteIdentifier()
    {
        $this->assertSame(['USERS'], $this->analyzer->extractTables('SELECT * FROM "users"'));
    }

    public function testInsertOrReplaceOperation()
    {
        $this->assertSame('INSERT', $this->analyzer->extractOperation('INSERT OR REPLACE INTO t (id) VALUES (1)'));
    }

    public function testInsertOrReplaceTable()
    {
        $this->assertSame(['T'], $this->analyzer->extractTables('INSERT OR REPLACE INTO t (id) VALUES (1)'));
    }

    public function testReplaceOperation()
    {
        $this->assertSame('REPLACE', $this->analyzer->extractOperation('REPLACE INTO t (id) VALUES (1)'));
    }

    public function testAnalysisDialect()
    {
        $analysis = $this->analyzer->buildAnalysis('SELECT * FROM t', 'SELECT', ['T'], 'intercepted');
        $this->assertSame('sqlite', $analysis['dialect']);
    }
}
