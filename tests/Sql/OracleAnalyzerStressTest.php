<?php

namespace CoffeeR\Tekagami\Tests\Sql;

use CoffeeR\Tekagami\Sql\OracleSqlAnalyzer;
use PHPUnit\Framework\TestCase;

// OracleSqlAnalyzer のストレステスト（80ケース）。
// 方針: regex ベースの best-effort 実装の限界を含めて文書化する。
// assertSame で operation、assertContains で必須テーブルの存在を確認。
// normalize() はクラッシュしないことだけを保証する。
//
// 既知の制約:
//   [制約1] FROM a, b, c（カンマ区切り）→ 最初の a のみ取得（b, c に JOIN/FROM が前置されない）
//   [制約2] WITH ... SELECT（CTE）→ operation = UNKNOWN（WITH キーワード未認識）
//   [制約3] CREATE OR REPLACE FUNCTION → operation = UNKNOWN
//   [制約4] 先頭が -- 行コメントの SQL → operation = UNKNOWN（ブロックコメントのみスキップ）
//   [制約5] UPDATE + インラインヒント + table → ヒントがキーワードとテーブル名の間にある場合テーブル未取得
//   [制約6] TRUNCATE TABLE → operation = UNKNOWN、tables = []
class OracleAnalyzerStressTest extends TestCase
{
    /** @var OracleSqlAnalyzer */
    private $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new OracleSqlAnalyzer();
    }

    /**
     * @dataProvider stressSqlProvider
     */
    public function testStressCase(string $id, string $sql, string $expectedOp, array $requiredTables): void
    {
        $this->assertSame($expectedOp, $this->analyzer->extractOperation($sql), "[$id] operation");

        $tables = $this->analyzer->extractTables($sql);
        foreach ($requiredTables as $t) {
            $this->assertContains($t, $tables, "[$id] missing table: $t");
        }

        $normalized = $this->analyzer->normalize($sql);
        $this->assertIsString($normalized, "[$id] normalize must return string");
        $this->assertNotEmpty($normalized, "[$id] normalize must not be empty");
    }

    public function stressSqlProvider(): array
    {
        return [

            // =====================================================================
            // cat=whitespace
            // =====================================================================

            'id=01 whitespace varied indent and blank line' => ['01',
                "SELECT  c.customer_id ,\n        c.name,\n\n        SUM(o.amount)      AS  total\n   FROM customers   c\n        JOIN orders  o   ON  o.customer_id = c.customer_id\n  WHERE  c.status = 'ACTIVE'\n  GROUP  BY\n         c.customer_id , c.name",
                'SELECT', ['CUSTOMERS', 'ORDERS'],
            ],

            'id=02 whitespace one-liner with subquery' => ['02',
                "SELECT a,b,c FROM t1 t WHERE t.a=1 AND t.b IN(SELECT x FROM t2 WHERE y>0) ORDER BY a DESC",
                'SELECT', ['T1', 'T2'],
            ],

            'id=03 whitespace tab mixed schema qualified' => ['03',
                "SELECT\t\tcol1,\n\tcol2\nFROM            schema1   .   tbl1\nWHERE     col1     =     'x'\n      AND col2\n          BETWEEN 1   AND   10",
                'SELECT', ['SCHEMA1.TBL1'],
            ],

            // =====================================================================
            // cat=join
            // =====================================================================

            'id=04 join 8-table chain' => ['04',
                "SELECT o.order_id, c.name, p.product_name, s.shipped_at, w.warehouse_code,\n       cat.category_name, pr.price, t.tax_rate\nFROM orders o\nJOIN customers c   ON c.customer_id = o.customer_id\nJOIN order_lines ol ON ol.order_id = o.order_id\nJOIN products p    ON p.product_id = ol.product_id\nJOIN categories cat ON cat.category_id = p.category_id\nJOIN prices pr     ON pr.product_id = p.product_id AND pr.valid_to IS NULL\nJOIN shipments s   ON s.order_id = o.order_id\nJOIN warehouses w  ON w.warehouse_id = s.warehouse_id\nJOIN tax t         ON t.region_id = c.region_id",
                'SELECT', ['ORDERS', 'CUSTOMERS', 'PRODUCTS', 'TAX'],
            ],

            'id=05 join INNER LEFT RIGHT FULL OUTER CROSS mix' => ['05',
                "SELECT *\nFROM a\nINNER JOIN b ON b.aid = a.id\nLEFT OUTER JOIN c ON c.bid = b.id\nRIGHT OUTER JOIN d ON d.cid = c.id\nFULL OUTER JOIN e ON e.did = d.id\nCROSS JOIN calendar cal",
                'SELECT', ['A', 'B', 'C', 'D', 'E', 'CALENDAR'],
            ],

            'id=06 join self-join dedup' => ['06',
                "SELECT e.emp_id, e.name AS emp, m.name AS mgr, mm.name AS mgr_of_mgr\nFROM employees e\nLEFT JOIN employees m  ON m.emp_id  = e.manager_id\nLEFT JOIN employees mm ON mm.emp_id = m.manager_id",
                'SELECT', ['EMPLOYEES'],
            ],

            'id=07 join complex ON with subquery' => ['07',
                "SELECT *\nFROM a\nJOIN b ON (a.k1 = b.k1 AND (a.k2 = b.k2 OR b.k2 IS NULL))\n       AND NVL(a.flag,'N') = 'Y'\n       AND a.dt = (SELECT MAX(dt) FROM b2 WHERE b2.k1 = a.k1)",
                'SELECT', ['A', 'B', 'B2'],
            ],

            // [制約1] FROM a, b: DEPARTMENTS はカンマ区切りのため未取得
            'id=08 join legacy plus outer join' => ['08',
                "SELECT e.name, d.dept_name\nFROM employees e, departments d\nWHERE e.dept_id = d.dept_id(+)\n  AND d.loc_id(+) = 1700",
                'SELECT', ['EMPLOYEES'],
            ],

            'id=09 join NATURAL JOIN USING' => ['09',
                "SELECT order_id, customer_id, name\nFROM orders\nNATURAL JOIN customers\nJOIN regions USING (region_id)",
                'SELECT', ['ORDERS', 'CUSTOMERS', 'REGIONS'],
            ],

            // =====================================================================
            // cat=oracle
            // =====================================================================

            'id=10 oracle CONNECT BY hierarchy' => ['10',
                "SELECT LEVEL,\n       LPAD(' ', 2*(LEVEL-1)) || e.name AS tree,\n       CONNECT_BY_ROOT e.name AS root_name,\n       SYS_CONNECT_BY_PATH(e.name, '/') AS path,\n       CONNECT_BY_ISLEAF AS is_leaf\nFROM employees e\nSTART WITH e.manager_id IS NULL\nCONNECT BY PRIOR e.emp_id = e.manager_id\nORDER SIBLINGS BY e.name",
                'SELECT', ['EMPLOYEES'],
            ],

            'id=11 oracle CONNECT BY NOCYCLE' => ['11',
                "SELECT emp_id, manager_id, LEVEL\nFROM employees\nCONNECT BY NOCYCLE PRIOR emp_id = manager_id\nSTART WITH manager_id IS NULL",
                'SELECT', ['EMPLOYEES'],
            ],

            'id=12 oracle MERGE INTO WHEN MATCHED' => ['12',
                "MERGE INTO target t\nUSING (SELECT id, val FROM staging) s\n   ON (t.id = s.id)\nWHEN MATCHED THEN\n  UPDATE SET t.val = s.val, t.updated_at = SYSDATE\n  WHERE t.val <> s.val\n  DELETE WHERE s.val IS NULL\nWHEN NOT MATCHED THEN\n  INSERT (id, val, created_at) VALUES (s.id, s.val, SYSDATE)",
                'MERGE', ['TARGET', 'STAGING'],
            ],

            'id=13 oracle MODEL clause' => ['13',
                "SELECT region, year, sales\nFROM yearly_sales\nMODEL\n  PARTITION BY (region)\n  DIMENSION BY (year)\n  MEASURES (sales)\n  RULES (\n    sales[2026] = sales[2025] * 1.1,\n    sales[2027] = sales[2026] + sales[2025]\n  )",
                'SELECT', ['YEARLY_SALES'],
            ],

            'id=14 oracle PIVOT' => ['14',
                "SELECT *\nFROM (SELECT product, quarter, amount FROM sales)\nPIVOT (\n  SUM(amount) AS amt\n  FOR quarter IN ('Q1' AS q1, 'Q2' AS q2, 'Q3' AS q3, 'Q4' AS q4)\n)",
                'SELECT', ['SALES'],
            ],

            'id=15 oracle UNPIVOT' => ['15',
                "SELECT product, quarter, amount\nFROM quarterly\nUNPIVOT (amount FOR quarter IN (q1 AS 'Q1', q2 AS 'Q2', q3 AS 'Q3', q4 AS 'Q4'))",
                'SELECT', ['QUARTERLY'],
            ],

            'id=16 oracle ROWNUM paging 3-nested' => ['16',
                "SELECT *\nFROM (\n  SELECT inner_q.*, ROWNUM rn\n  FROM (\n    SELECT id, name FROM big_table ORDER BY id\n  ) inner_q\n  WHERE ROWNUM <= 40\n)\nWHERE rn > 20",
                'SELECT', ['BIG_TABLE'],
            ],

            'id=17 oracle OFFSET FETCH 12c' => ['17',
                "SELECT id, name\nFROM big_table\nORDER BY id\nOFFSET 20 ROWS FETCH FIRST 20 ROWS ONLY",
                'SELECT', ['BIG_TABLE'],
            ],

            // FROM DUAL は除外される → requiredTables = []
            'id=18 oracle DUAL NEXTVAL SYSDATE' => ['18',
                "SELECT my_seq.NEXTVAL AS id, my_seq.CURRVAL AS cur, SYSDATE, SYSTIMESTAMP\nFROM DUAL",
                'SELECT', [],
            ],

            // [制約1] FROM orders o, customers c: CUSTOMERS はカンマ区切りのため未取得
            'id=19 oracle optimizer hints' => ['19',
                "SELECT /*+ LEADING(o c) USE_NL(c) INDEX(o idx_orders_dt) PARALLEL(4) */\n       o.order_id, c.name\nFROM orders o, customers c\nWHERE o.customer_id = c.customer_id",
                'SELECT', ['ORDERS'],
            ],

            'id=20 oracle flashback AS OF' => ['20',
                "SELECT *\nFROM accounts AS OF TIMESTAMP (SYSTIMESTAMP - INTERVAL '5' MINUTE)\nWHERE balance < 0",
                'SELECT', ['ACCOUNTS'],
            ],

            'id=21 oracle partition select' => ['21',
                "SELECT *\nFROM sales PARTITION (sales_2026_q1)\nWHERE region = 'JP'",
                'SELECT', ['SALES'],
            ],

            'id=22 oracle TABLE CAST MULTISET' => ['22',
                "SELECT t.column_value AS id\nFROM TABLE(CAST(MULTISET(SELECT id FROM ids) AS sys.odcinumberlist)) t",
                'SELECT', ['IDS'],
            ],

            // [制約1] FROM a, b, c: A のみ取得
            'id=23 oracle legacy plus both sides' => ['23',
                "SELECT a.id, b.id, c.id\nFROM a, b, c\nWHERE a.id = b.aid(+)\n  AND b.cid(+) = c.id\n  AND a.dt(+) >= DATE '2026-01-01'",
                'SELECT', ['A'],
            ],

            'id=24 oracle KEEP DENSE_RANK' => ['24',
                "SELECT dept_id,\n       MAX(salary) KEEP (DENSE_RANK LAST ORDER BY hire_date) AS latest_sal,\n       MIN(salary) KEEP (DENSE_RANK FIRST ORDER BY hire_date) AS first_sal\nFROM employees\nGROUP BY dept_id",
                'SELECT', ['EMPLOYEES'],
            ],

            'id=25 oracle LISTAGG WITHIN GROUP' => ['25',
                "SELECT dept_id,\n       LISTAGG(name, ', ') WITHIN GROUP (ORDER BY name) AS members\nFROM employees\nGROUP BY dept_id",
                'SELECT', ['EMPLOYEES'],
            ],

            'id=26 oracle hierarchy plus analytic' => ['26',
                "SELECT emp_id, name, LEVEL,\n       ROW_NUMBER() OVER (PARTITION BY LEVEL ORDER BY name) AS rn_in_level,\n       SUM(salary) OVER (ORDER BY LEVEL) AS running_sal\nFROM employees\nSTART WITH manager_id IS NULL\nCONNECT BY PRIOR emp_id = manager_id",
                'SELECT', ['EMPLOYEES'],
            ],

            'id=27 oracle ROLLUP CUBE GROUPING SETS' => ['27',
                "SELECT region, product, GROUPING_ID(region, product) AS gid, SUM(amount)\nFROM sales\nGROUP BY GROUPING SETS ((region, product), ROLLUP(region), CUBE(product))",
                'SELECT', ['SALES'],
            ],

            // [制約2] WITH ... SELECT → operation = UNKNOWN
            'id=28 oracle WITH recursive CTE' => ['28',
                "WITH RECURSIVE_BOM (part_id, parent_id, lvl) AS (\n  SELECT part_id, parent_id, 1 FROM bom WHERE parent_id IS NULL\n  UNION ALL\n  SELECT b.part_id, b.parent_id, r.lvl + 1\n  FROM bom b JOIN RECURSIVE_BOM r ON b.parent_id = r.part_id\n)\nSELECT * FROM RECURSIVE_BOM ORDER BY lvl",
                'UNKNOWN', ['BOM'],
            ],

            'id=29 oracle MATCH_RECOGNIZE' => ['29',
                "SELECT *\nFROM ticker\nMATCH_RECOGNIZE (\n  PARTITION BY symbol\n  ORDER BY tstamp\n  MEASURES STRT.tstamp AS start_t, LAST(DOWN.tstamp) AS bottom_t, LAST(UP.tstamp) AS end_t\n  ONE ROW PER MATCH\n  AFTER MATCH SKIP TO LAST UP\n  PATTERN (STRT DOWN+ UP+)\n  DEFINE\n    DOWN AS DOWN.price < PREV(DOWN.price),\n    UP   AS UP.price   > PREV(UP.price)\n)",
                'SELECT', ['TICKER'],
            ],

            'id=30 oracle JSON_TABLE JSON_VALUE' => ['30',
                "SELECT jt.*\nFROM docs d,\n     JSON_TABLE(d.payload, '$.items[*]'\n       COLUMNS (\n         item_id   NUMBER       PATH '$.id',\n         item_name VARCHAR2(100) PATH '$.name',\n         tags      VARCHAR2(400) FORMAT JSON PATH '$.tags'\n       )) jt\nWHERE JSON_VALUE(d.payload, '$.status') = 'OK'",
                'SELECT', ['DOCS'],
            ],

            'id=31 oracle XMLTABLE' => ['31',
                "SELECT x.id, x.title\nFROM books b,\n     XMLTABLE('/catalog/book' PASSING b.xml_data\n       COLUMNS id    NUMBER       PATH '@id',\n               title VARCHAR2(200) PATH 'title') x",
                'SELECT', ['BOOKS'],
            ],

            'id=32 oracle INTERVAL TIMESTAMP DATE literals' => ['32',
                "SELECT *\nFROM events\nWHERE ts BETWEEN TIMESTAMP '2026-01-01 00:00:00.000'\n             AND TIMESTAMP '2026-01-01 00:00:00' + INTERVAL '1 12:30:00' DAY TO SECOND\n  AND d > DATE '2025-12-31'",
                'SELECT', ['EVENTS'],
            ],

            // =====================================================================
            // cat=quote
            // =====================================================================

            'id=34 quote doubled single quote escape' => ['34',
                "SELECT * FROM customers WHERE name = 'O''Brien' OR note = 'it''s a ''test'''",
                'SELECT', ['CUSTOMERS'],
            ],

            'id=35 quote q-quote various delimiters FROM DUAL' => ['35',
                "SELECT q'[ it's fine; SELECT 1; -- not a comment ]' AS a,\n       q'{ a'b'c }' AS b,\n       q'<tag attr='x'>value</tag>' AS c,\n       q'!100% done; really!' AS d\nFROM DUAL",
                'SELECT', [],
            ],

            'id=36 quote national string nq-quote FROM DUAL' => ['36',
                "SELECT N'日本語ＮＣＨＡＲ' AS a, nq'#こんにちは'世界'#' AS b\nFROM DUAL",
                'SELECT', [],
            ],

            'id=37 quote double-quoted identifier with space' => ['37',
                'SELECT "My Column", "select" AS "from", t."Order Date"' . "\nFROM \"Weird Table Name\" t\nWHERE t.\"Order Date\" > DATE '2026-01-01'",
                'SELECT', ['WEIRD TABLE NAME'],
            ],

            'id=38 quote double-quoted identifier escaped quote' => ['38',
                'SELECT col AS "He said ""Hello"" today"' . "\nFROM dummy_table",
                'SELECT', ['DUMMY_TABLE'],
            ],

            'id=39 quote string contains semicolon dashes block comment FROM DUAL' => ['39',
                "SELECT 'value with ; semicolon and -- dashes and /* not a comment */' AS s,\n       'line1\nline2 with '' quote'  AS multiline\nFROM DUAL",
                'SELECT', [],
            ],

            'id=40 quote multiline string UPDATE' => ['40',
                "UPDATE templates\nSET body = 'Dear customer,\n\nThank you for your order; please wait.\nRegards.'\nWHERE id = 1",
                'UPDATE', ['TEMPLATES'],
            ],

            'id=41 quote concat CHR q-quote mix' => ['41',
                "SELECT 'a' || CHR(10) || q'(b'c)' || '''d''' || CHR(9) || \"Col Name\"\nFROM t",
                'SELECT', ['T'],
            ],

            // =====================================================================
            // cat=comment
            // =====================================================================

            // [制約4] 先頭が -- 行コメント → operation = UNKNOWN
            // extractTables は operation と独立して動作するため SAFE_TABLE は取得できる
            'id=42 comment leading line comment causes UNKNOWN operation' => ['42',
                "-- this isn't a real statement; SELECT DROP TABLE 'oops';\nSELECT id FROM safe_table WHERE flag = 'Y'  -- trailing comment with ; and '",
                'UNKNOWN', ['SAFE_TABLE'],
            ],

            'id=43 comment block comment contains quote and semicolon' => ['43',
                "SELECT /* comment with 'quote' and ; and a star * but not end */ col1,\n       /* multi\n          line\n          comment */ col2\nFROM t",
                'SELECT', ['T'],
            ],

            'id=44 comment tail comment no trailing newline FROM DUAL' => ['44',
                "SELECT 1 FROM DUAL /* tail comment, no trailing newline */",
                'SELECT', [],
            ],

            'id=45 comment hint vs normal comment' => ['45',
                "SELECT /*+ FULL(t) */ /* normal comment */ t.id\nFROM big_table t  -- end\nWHERE t.x = 1",
                'SELECT', ['BIG_TABLE'],
            ],

            // =====================================================================
            // cat=plsql
            // =====================================================================

            // DECLARE → CALL、カーソル内 FROM orders は取得できる
            'id=46 plsql anonymous block DECLARE' => ['46',
                "DECLARE\n  v_total NUMBER := 0;\n  CURSOR c IS SELECT amount FROM orders WHERE status = 'OPEN';\nBEGIN\n  FOR r IN c LOOP\n    v_total := v_total + r.amount;\n    IF r.amount > 1000 THEN\n      DBMS_OUTPUT.PUT_LINE('big; one: ' || r.amount);\n    END IF;\n  END LOOP;\n  COMMIT;\nEND;\n/",
                'CALL', ['ORDERS'],
            ],

            // [制約3] CREATE OR REPLACE → operation = UNKNOWN
            'id=47 plsql CREATE OR REPLACE FUNCTION' => ['47',
                "CREATE OR REPLACE FUNCTION fn_tax(p_amount IN NUMBER, p_rate IN NUMBER DEFAULT 0.1)\nRETURN NUMBER\nIS\n  v_tax NUMBER;\nBEGIN\n  v_tax := p_amount * p_rate;\n  RETURN ROUND(v_tax, 2);\nEND fn_tax;\n/",
                'UNKNOWN', [],
            ],

            // =====================================================================
            // cat=binds
            // =====================================================================

            'id=48 binds named positional substitution variable' => ['48',
                "SELECT *\nFROM orders\nWHERE customer_id = :cust_id\n  AND status      = :1\n  AND region      = '&region'\n  AND amount      > :min_amount",
                'SELECT', ['ORDERS'],
            ],

            // =====================================================================
            // cat=setop
            // =====================================================================

            'id=49 setop UNION ALL INTERSECT MINUS' => ['49',
                "SELECT id, 'A' src FROM a\nUNION ALL\nSELECT id, 'B' src FROM b\nINTERSECT\nSELECT id, src FROM c\nMINUS\nSELECT id, src FROM blacklist\nORDER BY id",
                'SELECT', ['A', 'B', 'C', 'BLACKLIST'],
            ],

            // =====================================================================
            // cat=boss
            // =====================================================================

            // [制約2] WITH ... → operation = UNKNOWN
            'id=50 boss all-in-one CTE analytic PIVOT CASE hint q-quote' => ['50',
                "WITH base AS (  /* 集計の元 */\n  SELECT /*+ MATERIALIZE */ o.region, o.product, o.quarter, o.amount,\n         CASE WHEN o.amount >= 1000 THEN q'[high; tier]'\n              WHEN o.amount >= 100  THEN 'mid'\n              ELSE 'low''tier'  -- 末尾comment ;\n         END AS tier\n  FROM orders o\n  WHERE o.created_at >= DATE '2026-01-01'\n),\nranked AS (\n  SELECT b.*,\n         RANK() OVER (PARTITION BY b.region ORDER BY b.amount DESC) AS rnk,\n         SUM(b.amount) OVER (PARTITION BY b.region) AS region_total\n  FROM base b\n)\nSELECT *\nFROM (SELECT region, product, quarter, amount FROM ranked WHERE rnk <= 10)\nPIVOT (SUM(amount) FOR quarter IN ('Q1' q1, 'Q2' q2, 'Q3' q3, 'Q4' q4))\nORDER BY region",
                'UNKNOWN', ['ORDERS'],
            ],

            // =====================================================================
            // cat=insert
            // =====================================================================

            'id=51 insert basic single VALUES' => ['51',
                "INSERT INTO customers (customer_id, name, status, created_at)\nVALUES (1001, 'Yamada', 'ACTIVE', SYSDATE)",
                'INSERT', ['CUSTOMERS'],
            ],

            'id=52 insert VALUES with functions NULL DEFAULT TO_DATE' => ['52',
                "INSERT INTO orders (order_id, customer_id, amount, order_date, memo, flag)\nVALUES (order_seq.NEXTVAL, :cust_id, ROUND(123.456, 2),\n        TO_DATE('2026-01-15', 'YYYY-MM-DD'), NULL, DEFAULT)",
                'INSERT', ['ORDERS'],
            ],

            'id=53 insert SELECT with JOINs' => ['53',
                "INSERT INTO sales_summary (region, product, total)\nSELECT c.region, p.product_name, SUM(ol.amount)\nFROM orders o\nJOIN customers c   ON c.customer_id = o.customer_id\nJOIN order_lines ol ON ol.order_id = o.order_id\nJOIN products p    ON p.product_id = ol.product_id\nWHERE o.created_at >= DATE '2026-01-01'\nGROUP BY c.region, p.product_name",
                'INSERT', ['SALES_SUMMARY', 'ORDERS', 'CUSTOMERS', 'ORDER_LINES', 'PRODUCTS'],
            ],

            'id=54 insert with CTE' => ['54',
                "INSERT INTO top_products (product_id, rnk)\nWITH ranked AS (\n  SELECT product_id, RANK() OVER (ORDER BY SUM(amount) DESC) rnk\n  FROM order_lines GROUP BY product_id\n)\nSELECT product_id, rnk FROM ranked WHERE rnk <= 10",
                'INSERT', ['TOP_PRODUCTS', 'ORDER_LINES'],
            ],

            'id=55 insert INSERT ALL unconditional' => ['55',
                "INSERT ALL\n  INTO audit_a (id, val) VALUES (id, val)\n  INTO audit_b (id, val) VALUES (id, val)\nSELECT id, val FROM staging",
                'INSERT', ['AUDIT_A', 'AUDIT_B', 'STAGING'],
            ],

            'id=56 insert INSERT FIRST conditional' => ['56',
                "INSERT FIRST\n  WHEN region = 'JP' THEN INTO sales_jp VALUES (id, region, amount)\n  WHEN region = 'US' THEN INTO sales_us VALUES (id, region, amount)\n  ELSE INTO sales_other VALUES (id, region, amount)\nSELECT id, region, amount FROM staging_sales",
                'INSERT', ['SALES_JP', 'SALES_US', 'SALES_OTHER', 'STAGING_SALES'],
            ],

            'id=57 insert RETURNING INTO' => ['57',
                "INSERT INTO invoices (invoice_id, amount)\nVALUES (invoice_seq.NEXTVAL, :amount)\nRETURNING invoice_id, created_at INTO :new_id, :created",
                'INSERT', ['INVOICES'],
            ],

            'id=58 insert direct load hint APPEND PARALLEL' => ['58',
                "INSERT /*+ APPEND PARALLEL(t, 4) */ INTO archive t\nSELECT * FROM live_data WHERE created_at < ADD_MONTHS(SYSDATE, -12)",
                'INSERT', ['ARCHIVE', 'LIVE_DATA'],
            ],

            'id=59 insert VALUES with tricky string literals' => ['59',
                "INSERT INTO logs (id, msg, body)\nVALUES (\n  log_seq.NEXTVAL,\n  'error; code=''42'' -- not a comment /* nope */',\n  q'[line1; with ' quote\nline2 -- still string]'\n)",
                'INSERT', ['LOGS'],
            ],

            'id=60 insert double-quoted table and column names' => ['60',
                "INSERT INTO \"Weird Table Name\" (\"Order Date\", \"select\", \"col with space\")\nVALUES (DATE '2026-02-01', 'X', 'Y')",
                'INSERT', ['WEIRD TABLE NAME'],
            ],

            'id=61 insert INSERT ALL with DUAL dedup' => ['61',
                "INSERT ALL\n  INTO codes (code, label) VALUES ('A', 'Alpha')\n  INTO codes (code, label) VALUES ('B', 'Beta')\n  INTO codes (code, label) VALUES ('C', 'Gamma')\nSELECT 1 FROM DUAL",
                'INSERT', ['CODES'],
            ],

            // =====================================================================
            // cat=update
            // =====================================================================

            'id=62 update basic single column WHERE' => ['62',
                "UPDATE customers SET status = 'INACTIVE' WHERE last_login < ADD_MONTHS(SYSDATE, -24)",
                'UPDATE', ['CUSTOMERS'],
            ],

            'id=63 update multiple columns' => ['63',
                "UPDATE orders\nSET amount     = amount * 1.08,\n    tax        = amount * 0.08,\n    updated_at = SYSTIMESTAMP\nWHERE region = 'JP'",
                'UPDATE', ['ORDERS'],
            ],

            'id=64 update SET with correlated subquery' => ['64',
                "UPDATE products p\nSET p.avg_price = (SELECT AVG(pr.price) FROM prices pr WHERE pr.product_id = p.product_id)\nWHERE EXISTS (SELECT 1 FROM prices pr WHERE pr.product_id = p.product_id)",
                'UPDATE', ['PRODUCTS', 'PRICES'],
            ],

            'id=65 update tuple assignment SET a b = SELECT' => ['65',
                "UPDATE employees e\nSET (e.dept_id, e.salary) = (\n  SELECT d.dept_id, d.base_salary\n  FROM departments d WHERE d.name = 'Sales')\nWHERE e.emp_id = :id",
                'UPDATE', ['EMPLOYEES', 'DEPARTMENTS'],
            ],

            'id=66 update WHERE EXISTS NOT IN subquery' => ['66',
                "UPDATE invoices i\nSET i.status = 'PAID'\nWHERE EXISTS (SELECT 1 FROM payments p WHERE p.invoice_id = i.invoice_id)\n  AND i.invoice_id NOT IN (SELECT invoice_id FROM disputes)",
                'UPDATE', ['INVOICES', 'PAYMENTS', 'DISPUTES'],
            ],

            'id=67 update SET with CASE expression' => ['67',
                "UPDATE orders\nSET grade = CASE\n              WHEN amount >= 10000 THEN 'A'\n              WHEN amount >= 1000  THEN 'B'\n              ELSE 'C'\n            END\nWHERE status = 'CLOSED'",
                'UPDATE', ['ORDERS'],
            ],

            'id=68 update inline view' => ['68',
                "UPDATE (\n  SELECT e.salary, e.dept_id, d.budget\n  FROM employees e JOIN departments d ON e.dept_id = d.dept_id\n  WHERE d.budget > 0) v\nSET v.salary = v.salary * 1.1",
                'UPDATE', ['EMPLOYEES', 'DEPARTMENTS'],
            ],

            'id=69 update RETURNING INTO' => ['69',
                "UPDATE accounts\nSET balance = balance - :amt\nWHERE account_id = :acc\nRETURNING balance INTO :new_balance",
                'UPDATE', ['ACCOUNTS'],
            ],

            'id=70 update SET value contains semicolon dashes q-quote' => ['70',
                "UPDATE templates\nSET subject = 'Re: it''s done; thanks -- ok',\n    body    = q'{Hello;\nGoodbye /* not a comment */ -- still text}'\nWHERE id = :id",
                'UPDATE', ['TEMPLATES'],
            ],

            // [制約5] UPDATE /*+ hint */ table → ヒントがテーブル名の前にあるためテーブル未取得
            'id=71 update hint between keyword and table' => ['71',
                "UPDATE /*+ INDEX(o idx_status) */ orders o\nSET o.flag = 'Y'\nWHERE o.status = 'NEW' AND ROWNUM <= 1000",
                'UPDATE', [],
            ],

            'id=72 update bind variables only' => ['72',
                "UPDATE stock\nSET qty = qty + :delta, updated_by = :user\nWHERE warehouse_id = :wh AND product_id = :pid AND qty + :delta >= 0",
                'UPDATE', ['STOCK'],
            ],

            // =====================================================================
            // cat=delete
            // =====================================================================

            'id=73 delete basic WHERE' => ['73',
                "DELETE FROM sessions WHERE expires_at < SYSTIMESTAMP",
                'DELETE', ['SESSIONS'],
            ],

            'id=74 delete WHERE EXISTS correlated' => ['74',
                "DELETE FROM order_lines ol\nWHERE EXISTS (\n  SELECT 1 FROM orders o WHERE o.order_id = ol.order_id AND o.status = 'CANCELLED')",
                'DELETE', ['ORDER_LINES', 'ORDERS'],
            ],

            'id=75 delete IN subquery' => ['75',
                "DELETE FROM customers\nWHERE customer_id IN (\n  SELECT customer_id FROM blacklist WHERE reason IS NOT NULL)",
                'DELETE', ['CUSTOMERS', 'BLACKLIST'],
            ],

            'id=76 delete inline view' => ['76',
                "DELETE FROM (\n  SELECT * FROM stale_logs WHERE created_at < ADD_MONTHS(SYSDATE, -6))",
                'DELETE', ['STALE_LOGS'],
            ],

            'id=77 delete RETURNING INTO' => ['77',
                "DELETE FROM cart_items\nWHERE cart_id = :cart_id\nRETURNING item_id INTO :deleted_id",
                'DELETE', ['CART_ITEMS'],
            ],

            'id=78 delete ROWNUM batch' => ['78',
                "DELETE FROM huge_log WHERE log_date < DATE '2025-01-01' AND ROWNUM <= 5000",
                'DELETE', ['HUGE_LOG'],
            ],

            'id=79 delete WHERE value contains tricky strings' => ['79',
                "DELETE FROM notes\nWHERE body = 'DROP TABLE x; -- gotcha'\n   /* これはコメント; DELETE all? いいえ */\n  AND tag = q'!a;b'c!'",
                'DELETE', ['NOTES'],
            ],

            // =====================================================================
            // cat=truncate
            // =====================================================================

            // [制約6] TRUNCATE は operation キーワード未認識 → UNKNOWN、tables = []
            'id=80 truncate TRUNCATE TABLE' => ['80',
                "TRUNCATE TABLE staging_orders DROP STORAGE",
                'UNKNOWN', [],
            ],

            // =====================================================================
            // cat=insert (id=33 はオリジナル50ケースに含まれていたものを再掲)
            // =====================================================================

            'id=33 oracle INSERT ALL conditional WHEN' => ['33',
                "INSERT ALL\n  WHEN amount > 1000 THEN INTO big_orders (id, amount) VALUES (id, amount)\n  WHEN amount <= 1000 THEN INTO small_orders (id, amount) VALUES (id, amount)\nSELECT id, amount FROM staging_orders",
                'INSERT', ['BIG_ORDERS', 'SMALL_ORDERS', 'STAGING_ORDERS'],
            ],

        ];
    }
}
