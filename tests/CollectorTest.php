<?php

namespace CoffeeR\Tekagami\Tests;

use CoffeeR\Tekagami\Collector;
use CoffeeR\Tekagami\Config;
use CoffeeR\Tekagami\Flow;
use CoffeeR\Tekagami\Http\HttpInput;
use CoffeeR\Tekagami\Http\HttpResponse;
use CoffeeR\Tekagami\Sink\SinkInterface;
use CoffeeR\Tekagami\Sql\OracleSqlAnalyzer;
use CoffeeR\Tekagami\Sql\SqlAnalyzerInterface;
use CoffeeR\Tekagami\Sql\SqliteSqlAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * write() が呼ばれたレコードを $captured に保持するテスト用 Sink。
 */
class CaptureSink implements SinkInterface
{
    /** @var array|null */
    public $captured = null;

    /** @var int */
    public $callCount = 0;

    public function write(array $trace)
    {
        $this->captured  = $trace;
        $this->callCount++;
    }
}

/**
 * write() が常に RuntimeException を投げるテスト用 Sink。
 */
class FailingSink implements SinkInterface
{
    public function write(array $trace)
    {
        throw new \RuntimeException('sink write error');
    }
}

class ThrowingSqlAnalyzer implements SqlAnalyzerInterface
{
    public function normalize($statement)
    {
        throw new \RuntimeException('boom');
    }

    public function replaceWithCallback($statement, callable $replacer)
    {
        return $statement;
    }

    public function hash($normalized)
    {
        return 'sha256:unused';
    }

    public function extractOperation($statement)
    {
        return 'UNKNOWN';
    }

    public function extractTables($statement)
    {
        return [];
    }

    public function buildAnalysis($statement, $operation, array $tables, $source)
    {
        return ['analyzer' => 'throwing', 'operation_confidence' => 'unknown', 'tables_confidence' => 'unknown', 'warnings' => []];
    }
}

class CollectorTest extends TestCase
{
    private function makeCollector(?Config $config = null, $sink = null, $analyzer = null)
    {
        $config   = $config   ?? new Config();
        $sink     = $sink     ?? new CaptureSink();
        $analyzer = $analyzer ?? new SqliteSqlAnalyzer();
        return new Collector($config, $sink, $analyzer);
    }

    private function makeHttp($method = 'GET', $path = '/test')
    {
        return new HttpInput($method, $path);
    }

    private function makeResponse($status = 200)
    {
        $r         = new HttpResponse();
        $r->status = $status;
        return $r;
    }

    // -------------------------------------------------------------------------
    // 基本的なライフサイクル
    // -------------------------------------------------------------------------

    public function testStartSetsTraceId()
    {
        $collector = $this->makeCollector();
        $this->assertNull($collector->getActiveTraceId());
        $collector->start($this->makeHttp(), new Flow());
        $this->assertNotNull($collector->getActiveTraceId());
        $this->assertMatchesRegularExpression('/^[0-9a-f\-]{36}$/', $collector->getActiveTraceId());
    }

    public function testFinishResetsTraceId()
    {
        $collector = $this->makeCollector();
        $collector->start($this->makeHttp(), new Flow());
        $collector->finish($this->makeResponse());
        $this->assertNull($collector->getActiveTraceId());
    }

    public function testFinishWritesObservationV1Record()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp('POST', '/orders'), new Flow('flow-abc', 1));
        $collector->finish($this->makeResponse(201));

        $trace = $sink->captured;
        $this->assertNotNull($trace);

        // 必須トップレベルキーの確認
        $required = ['schema_version','trace_id',
                     'started_at','flow','redaction','http','timeline','effects','errors'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $trace, "Missing key: $key");
        }

        $this->assertSame(1, $trace['schema_version']);
        $this->assertSame('flow-abc', $trace['flow']['flow_id']);
        $this->assertSame(1, $trace['flow']['seq']);
        $this->assertSame('POST', $trace['http']['method']);
        $this->assertSame('/orders', $trace['http']['path']);
        $this->assertSame(201, $trace['http']['status']);
    }

    public function testStartWithoutFlowRecordsNullFlow()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp('GET', '/products'));
        $collector->finish($this->makeResponse(200));

        $this->assertNull($sink->captured['flow']['flow_id']);
        $this->assertNull($sink->captured['flow']['seq']);
    }

    public function testFinishCalledTwiceIsNoOp()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->finish($this->makeResponse());
        $collector->finish($this->makeResponse());  // 2回目は何もしない

        $this->assertSame(1, $sink->callCount);
    }

    // -------------------------------------------------------------------------
    // addSql
    // -------------------------------------------------------------------------

    public function testAddSqlAppendsTimelineEvent()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql("SELECT * FROM users WHERE id = 1");
        $collector->finish($this->makeResponse());

        $timeline = $sink->captured['timeline'];
        $this->assertCount(1, $timeline);
        $this->assertSame('sql', $timeline[0]['type']);
        $this->assertSame('SELECT', $timeline[0]['operation']);
        $this->assertSame('SELECT * FROM users WHERE id = {parameter}', $timeline[0]['statement_normalized']);
        $this->assertSame('bound_sql', $timeline[0]['analysis']['input_quality']);
        $this->assertStringStartsWith('sha256:', $timeline[0]['statement_hash']);
    }

    public function testAddExpandedSqlAppendsLowTrustTimelineEvent()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(new Config('secret'), $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addExpandedSql("SELECT * FROM users WHERE id = 1", ['source' => 'ci3-last_query']);
        $collector->finish($this->makeResponse());

        $event = $sink->captured['timeline'][0];
        $this->assertSame('expanded_sql', $event['analysis']['input_quality']);
        $this->assertContains('expanded_sql_may_fragment_statement_hash', $event['analysis']['warnings']);
        $this->assertContains('last_query_capture_has_no_bind_values', $event['analysis']['warnings']);
        $this->assertArrayHasKey('statement_tokens', $event);
        $this->assertArrayNotHasKey('bind_shape', $event);
        $this->assertArrayNotHasKey('bind_tokens', $event);
    }

    public function testInjectedAnalyzerDialectIsRecorded()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink, new SqliteSqlAnalyzer());
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql('SELECT * FROM users WHERE id = 1');
        $collector->finish($this->makeResponse());

        $this->assertSame('sqlite', $sink->captured['timeline'][0]['analysis']['dialect']);
    }

    public function testOracleAnalyzerInjection()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink, new OracleSqlAnalyzer());
        $collector->start($this->makeHttp(), new Flow());
        // Oracle の '' エスケープと dual 除外が効いていることを確認
        $collector->addSql("SELECT 1 FROM dual WHERE note = 'it''s'");
        $collector->finish($this->makeResponse());

        $event = $sink->captured['timeline'][0];
        $this->assertSame('oracle', $event['analysis']['dialect']);
        $this->assertSame('SELECT {parameter} FROM dual WHERE note = {parameter}', $event['statement_normalized']);
        $this->assertSame([], $event['tables']); // dual は除外
        $this->assertContains('oracle_dual_select', $event['analysis']['warnings']);
    }

    public function testCollectorRequiresAnalyzer()
    {
        // SqlAnalyzerInterface は必須・null 不可（型で強制）
        $this->expectException(\TypeError::class);
        new Collector(new Config(), new CaptureSink(), null);
    }

    public function testAddSqlWithBinds()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql('SELECT * FROM users WHERE id = ?', [42]);
        $collector->finish($this->makeResponse());

        $event = $sink->captured['timeline'][0];
        $this->assertSame(['number'], $event['bind_shape']);
    }

    public function testAddSqlSequenceNumber()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql('SELECT 1');
        $collector->addSql('SELECT 2');
        $collector->finish($this->makeResponse());

        $this->assertSame(1, $sink->captured['timeline'][0]['seq']);
        $this->assertSame(2, $sink->captured['timeline'][1]['seq']);
    }

    public function testObservedValuesEmptyByDefaultIsObject()
    {
        // sqlValueAllowlist 未設定: observed_values は空オブジェクト {}（[] ではない）
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addExpandedSql("INSERT INTO orders (status) VALUES ('shipped')");
        $collector->finish($this->makeResponse());

        $observed = $sink->captured['timeline'][0]['observed_values'];
        $this->assertInstanceOf(\stdClass::class, $observed);
        // json_encode で {} になることを保証（[] だとスキーマ object 違反）
        $this->assertSame('{}', json_encode($observed));
    }

    public function testObservedValuesExtractedWhenAllowlisted()
    {
        $config    = new Config(null, ['sqlValueAllowlist' => ['orders.status']]);
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addExpandedSql("INSERT INTO orders (user_id, status) VALUES (10, 'shipped')");
        $collector->finish($this->makeResponse());

        $observed = $sink->captured['timeline'][0]['observed_values'];
        $this->assertSame(
            ['ORDERS.STATUS' => ['redacted' => false, 'values' => ['shipped']]],
            $observed
        );
    }

    // -------------------------------------------------------------------------
    // addCustom
    // -------------------------------------------------------------------------

    public function testAddCustomAppendsTimelineEvent()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addCustom('cache_read', ['key' => 'product:42', 'hit' => true]);
        $collector->finish($this->makeResponse());

        $timeline = $sink->captured['timeline'];
        $this->assertCount(1, $timeline);
        $this->assertSame('custom', $timeline[0]['type']);
        $this->assertSame('cache_read', $timeline[0]['label']);
        $this->assertSame(['key' => 'string', 'hit' => 'boolean'], $timeline[0]['data_shape']);
    }

    // -------------------------------------------------------------------------
    // effects 集計
    // -------------------------------------------------------------------------

    public function testEffectsAggregatesWriteOps()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql("INSERT INTO orders (user_id) VALUES (1)");
        $collector->addSql("INSERT INTO orders (user_id) VALUES (2)");  // 同じパターン
        $collector->finish($this->makeResponse());

        $effects = $sink->captured['effects'];
        $this->assertNotEmpty($effects);
        $this->assertSame('INSERT', $effects[0]['op']);
        $this->assertSame('ORDERS', $effects[0]['table']);
        $this->assertSame(2, $effects[0]['count']);
    }

    public function testEffectsExcludesSelectOps()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql("SELECT * FROM users WHERE id = 1");
        $collector->finish($this->makeResponse());

        $this->assertEmpty($sink->captured['effects']);
    }

    public function testEffectsDisabledWhenCaptureEffectsFalse()
    {
        $config    = new Config(null, ['captureEffects' => false]);
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql("INSERT INTO t VALUES (1)");
        $collector->finish($this->makeResponse());

        $this->assertEmpty($sink->captured['effects']);
    }

    // -------------------------------------------------------------------------
    // addError
    // -------------------------------------------------------------------------

    public function testAddErrorAppendsToErrors()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addError('php_exception', 'Something failed', 'MyClass');
        $collector->finish($this->makeResponse());

        $errors = $sink->captured['errors'];
        $this->assertCount(1, $errors);
        $this->assertSame('php_exception', $errors[0]['type']);
        $this->assertSame('Something failed', $errors[0]['message']);
    }

    // -------------------------------------------------------------------------
    // timeline 打ち切り
    // -------------------------------------------------------------------------

    public function testTimelineTruncationLimit()
    {
        $config    = new Config(null, ['maxTimelineSize' => 2]);
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql('SELECT 1');
        $collector->addSql('SELECT 2');
        $collector->addSql('SELECT 3');  // 無視されるはず
        $collector->finish($this->makeResponse());

        $this->assertCount(2, $sink->captured['timeline']);
        // errors に capture_failure が入っている
        $errorTypes = array_column($sink->captured['errors'], 'type');
        $this->assertContains('capture_failure', $errorTypes);
    }

    public function testTimelineTruncationErrorAddedOnce()
    {
        $config    = new Config(null, ['maxTimelineSize' => 1]);
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql('SELECT 1');
        $collector->addSql('SELECT 2');  // 超過1回目
        $collector->addSql('SELECT 3');  // 超過2回目
        $collector->finish($this->makeResponse());

        // エラーは1件だけ
        $captureFailures = array_filter(
            $sink->captured['errors'],
            function ($e) { return $e['type'] === 'capture_failure'; }
        );
        $this->assertCount(1, $captureFailures);
    }

    public function testAddSqlAnalyzerFailureDoesNotPropagateException()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink, new ThrowingSqlAnalyzer());
        $collector->start($this->makeHttp(), new Flow());
        $collector->addSql('SELECT secret FROM users WHERE id = 1');
        $collector->finish($this->makeResponse());

        $this->assertSame([], $sink->captured['timeline']);
        $this->assertSame('capture_failure', $sink->captured['errors'][0]['type']);
        $this->assertSame('Collector::addSql', $sink->captured['errors'][0]['at']);
        $this->assertStringNotContainsString('SELECT secret', $sink->captured['errors'][0]['message']);
    }

    // -------------------------------------------------------------------------
    // sink write 失敗時の安全性
    // -------------------------------------------------------------------------

    public function testSinkWriteFailureDoesNotPropagateException()
    {
        $collector = $this->makeCollector(null, new FailingSink());
        $collector->start($this->makeHttp(), new Flow());
        $collector->finish($this->makeResponse());
        // ここまで到達すれば例外が伝播していない
        $this->assertTrue(true);
    }

    public function testAfterSinkFailureTraceIdIsReset()
    {
        $collector = $this->makeCollector(null, new FailingSink());
        $collector->start($this->makeHttp(), new Flow());
        $collector->finish($this->makeResponse());
        $this->assertNull($collector->getActiveTraceId());
    }

    // -------------------------------------------------------------------------
    // shape の深さ制限
    // -------------------------------------------------------------------------

    public function testShapeDepthLimitInResponse()
    {
        $config = new Config(null, ['maxDepth' => 2]);
        $sink   = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);
        $collector->start($this->makeHttp(), new Flow());

        $response                  = $this->makeResponse();
        $response->responseKind    = 'json';
        $response->responseBodyRaw = ['a' => ['b' => ['c' => ['d' => 'deep']]]];
        $collector->finish($response);

        $shape = $sink->captured['http']['response_shape'];
        // depth 2 の時点で '...' になる
        $this->assertSame('...', $shape['a']['b']);
    }

    // -------------------------------------------------------------------------
    // HTTP エンベロープ
    // -------------------------------------------------------------------------

    public function testHttpEnvelopeQueryShape()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $http              = $this->makeHttp('GET', '/search');
        $http->queryRaw    = ['q' => 'coffee', 'page' => 2];
        $collector->start($http, new Flow());
        $collector->finish($this->makeResponse());

        $this->assertSame(
            ['q' => 'string', 'page' => 'number'],
            $sink->captured['http']['query_shape']
        );
    }

    public function testHttpEnvelopePathPattern()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $http               = $this->makeHttp('GET', '/products/123');
        $http->pathPattern  = '/products/{id}';
        $collector->start($http, new Flow());
        $collector->finish($this->makeResponse());

        $this->assertSame('/products/{id}', $sink->captured['http']['path_pattern']);
    }

    public function testHttpEnvelopeViews()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(null, $sink);
        $collector->start($this->makeHttp(), new Flow());

        $response               = $this->makeResponse();
        $response->responseKind = 'html';
        $response->views        = [
            ['template' => 'product/show.php', 'vars_raw' => ['name' => 'Coffee', 'price' => 500]],
        ];
        $collector->finish($response);

        $views = $sink->captured['http']['views'];
        $this->assertCount(1, $views);
        $this->assertSame('product/show.php', $views[0]['template']);
        $this->assertSame(['name' => 'string', 'price' => 'number'], $views[0]['vars_shape']);
        $this->assertSame(1, $views[0]['seq']);
    }

    public function testRequestHeadersAreNotCapturedByDefault()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(new Config('my-secret'), $sink);
        $http = $this->makeHttp('GET', '/headers');
        $http->requestHeadersRaw = [
            'Authorization' => 'Bearer secret',
            'X-Api-Key' => 'api-secret',
        ];

        $collector->start($http, new Flow());
        $collector->finish($this->makeResponse());

        $this->assertArrayNotHasKey('request_headers_shape', $sink->captured['http']);
        $this->assertArrayNotHasKey('request_headers_tokens', $sink->captured['http']);
    }

    public function testRequestHeadersCaptureWhitelistOnly()
    {
        $config    = new Config('my-secret', ['keepHeaderKeys' => ['X-Request-Id']]);
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);
        $http = $this->makeHttp('GET', '/headers');
        $http->requestHeadersRaw = [
            'Authorization' => 'Bearer secret',
            'X-Request-Id' => 'req-123',
        ];

        $collector->start($http, new Flow());
        $collector->finish($this->makeResponse());

        $this->assertSame(['X-Request-Id' => 'string'], $sink->captured['http']['request_headers_shape']);
        $this->assertArrayHasKey('X-Request-Id', $sink->captured['http']['request_headers_tokens']);
        $this->assertArrayNotHasKey('Authorization', $sink->captured['http']['request_headers_shape']);
    }

    public function testResponseHeadersAreNotCapturedByDefault()
    {
        $sink      = new CaptureSink();
        $collector = $this->makeCollector(new Config('my-secret'), $sink);

        $collector->start($this->makeHttp('GET', '/headers'), new Flow());
        $response = $this->makeResponse();
        $response->responseHeadersRaw = [
            'Set-Cookie' => 'sid=secret; HttpOnly',
            'Location' => '/oauth/callback?code=secret',
        ];
        $collector->finish($response);

        $this->assertArrayNotHasKey('response_headers_shape', $sink->captured['http']);
        $this->assertArrayNotHasKey('response_headers_tokens', $sink->captured['http']);
    }

    public function testResponseHeadersCaptureWhitelistOnly()
    {
        $config    = new Config('my-secret', ['keepResponseHeaderKeys' => ['X-Trace-Id']]);
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);

        $collector->start($this->makeHttp('GET', '/headers'), new Flow());
        $response = $this->makeResponse();
        $response->responseHeadersRaw = [
            'Set-Cookie' => 'sid=secret; HttpOnly',
            'Location' => '/oauth/callback?code=secret',
            'X-Trace-Id' => 'trace-123',
        ];
        $collector->finish($response);

        $this->assertSame(['X-Trace-Id' => 'string'], $sink->captured['http']['response_headers_shape']);
        $this->assertArrayHasKey('X-Trace-Id', $sink->captured['http']['response_headers_tokens']);
        $this->assertArrayNotHasKey('Set-Cookie', $sink->captured['http']['response_headers_shape']);
        $this->assertArrayNotHasKey('Location', $sink->captured['http']['response_headers_shape']);
    }

    public function testResponseHeadersWithoutSecretHaveShapeOnly()
    {
        $config    = new Config(null, ['keepResponseHeaderKeys' => ['X-Trace-Id']]);
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);

        $collector->start($this->makeHttp('GET', '/headers'), new Flow());
        $response = $this->makeResponse();
        $response->responseHeadersRaw = ['X-Trace-Id' => 'trace-123'];
        $collector->finish($response);

        $this->assertSame(['X-Trace-Id' => 'string'], $sink->captured['http']['response_headers_shape']);
        $this->assertArrayNotHasKey('response_headers_tokens', $sink->captured['http']);
    }

    public function testShapeTruncationRecordsErrorAndContinues()
    {
        $config    = new Config(null, ['maxShapeNodes' => 3]);
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);
        $http = $this->makeHttp('POST', '/large');
        $http->requestRaw = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];

        $collector->start($http, new Flow());
        $collector->finish($this->makeResponse(200));

        $this->assertSame(200, $sink->captured['http']['status']);
        $this->assertSame('...', $sink->captured['http']['request_shape']['c']);
        $this->assertSame('capture_failure', $sink->captured['errors'][0]['type']);
        $this->assertSame('request_shape', $sink->captured['errors'][0]['at']);
    }

    // -------------------------------------------------------------------------
    // HMAC トークン化
    // -------------------------------------------------------------------------

    public function testTokenizationFieldsPresent()
    {
        $config    = new Config('my-secret');
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);
        $http           = $this->makeHttp('GET', '/products/42');
        $http->queryRaw = ['id' => 42];
        $collector->start($http, new Flow());
        $collector->addSql('SELECT * FROM t WHERE id = ?', [42]);
        $collector->finish($this->makeResponse());

        $trace = $sink->captured;
        $this->assertTrue($trace['redaction']['tokenized']);
        $this->assertStringStartsWith('hmac-sha256:', $trace['redaction']['token_format']);
        $this->assertArrayHasKey('query_tokens', $trace['http']);
        $this->assertArrayHasKey('statement_tokens', $trace['timeline'][0]);
    }

    // -------------------------------------------------------------------------
    // enabled=false
    // -------------------------------------------------------------------------

    public function testDisabledCollectorDoesNothing()
    {
        $config    = new Config(null, ['enabled' => false]);
        $sink      = new CaptureSink();
        $collector = $this->makeCollector($config, $sink);

        $collector->start($this->makeHttp('GET', '/test'));
        $this->assertNull($collector->getActiveTraceId());

        $collector->addSql('SELECT 1 FROM dual');
        $collector->addCustom('event', ['x' => 1]);
        $collector->addError('test_error', 'msg');
        $collector->finish($this->makeResponse());

        $this->assertSame(0, $sink->callCount);
        $this->assertNull($sink->captured);
    }

    public function testEnabledTrueIsDefault()
    {
        $config = new Config(null);
        $this->assertTrue($config->enabled);
    }

    public function testEnabledFalseViaOptions()
    {
        $config = new Config(null, ['enabled' => false]);
        $this->assertFalse($config->enabled);
    }

    public function testKeepResponseHeaderKeysDefaultAndOption()
    {
        $default = new Config(null);
        $this->assertSame([], $default->keepResponseHeaderKeys);

        $configured = new Config(null, ['keepResponseHeaderKeys' => ['X-Trace-Id']]);
        $this->assertSame(['X-Trace-Id'], $configured->keepResponseHeaderKeys);
    }
}
