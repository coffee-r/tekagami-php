<?php

namespace CoffeeR\Tekagami\Tests;

use CoffeeR\Tekagami\Collector;
use CoffeeR\Tekagami\Config;
use CoffeeR\Tekagami\Flow;
use CoffeeR\Tekagami\Http\HttpInput;
use CoffeeR\Tekagami\Http\HttpResponse;
use CoffeeR\Tekagami\Sink\SinkInterface;
use CoffeeR\Tekagami\Sql\OracleSqlAnalyzer;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * tekagami-v1.schema.json と「実装が実際に出力する JSON 構造」が一致するかの契約チェック。
 *
 * fixture と、実 Collector が生成したレコードの両方をスキーマ検証する。
 * これにより observed_values の空時 {} (object) なども自動で守られる。
 */
class SchemaConformanceTest extends TestCase
{
    /** @var Validator */
    private $validator;

    /** @var object  デコード済みスキーマ */
    private $schema;

    protected function setUp(): void
    {
        $this->validator = new Validator();
        $path = __DIR__ . '/../../contracts/schema/tekagami-v1.schema.json';
        $this->schema = json_decode(file_get_contents($path));
        $this->assertNotNull($this->schema, 'schema.json could not be decoded');
    }

    /**
     * PHP のレコード配列を JSON のオブジェクト/配列構造（stdClass ツリー）に変換して検証する。
     * Collector は連想配列を返すため、JSON 往復で実際の出力 JSON と同じ型構造に揃える。
     */
    private function assertValidRecord($record, $context)
    {
        $json = json_encode($record);
        $this->assertNotFalse($json, "$context: json_encode failed");

        $data   = json_decode($json);
        $result = $this->validator->validate($data, $this->schema);

        if (!$result->isValid()) {
            $errors = (new ErrorFormatter())->format($result->error());
            $this->fail("$context: schema 違反\n" . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $this->assertTrue($result->isValid(), "$context: valid");
    }

    // -------------------------------------------------------------------------
    // fixture
    // -------------------------------------------------------------------------

    public function testFixtureLinesConformToSchema()
    {
        $path  = __DIR__ . '/fixtures/sample.jsonl';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines);

        foreach ($lines as $i => $line) {
            $data   = json_decode($line);
            $result = $this->validator->validate($data, $this->schema);
            if (!$result->isValid()) {
                $errors = (new ErrorFormatter())->format($result->error());
                $this->fail('fixture line ' . ($i + 1) . " schema 違反\n" . json_encode($errors, JSON_PRETTY_PRINT));
            }
            $this->assertTrue($result->isValid());
        }
    }

    // -------------------------------------------------------------------------
    // 実 Collector 出力
    // -------------------------------------------------------------------------

    private function buildRecord(Config $config, callable $script)
    {
        $sink      = new SchemaCaptureSink();
        $collector = new Collector($config, $sink, new OracleSqlAnalyzer());
        $collector->start(new HttpInput('GET', '/test'), new Flow());
        $script($collector);
        $response               = new HttpResponse();
        $response->status       = 200;
        $response->responseKind = 'json';
        $response->responseBodyRaw = ['ok' => true];
        $collector->finish($response);
        return $sink->captured;
    }

    public function testMinimalRecordConforms()
    {
        $record = $this->buildRecord(new Config(), function (Collector $c) {
            $c->addSql("SELECT * FROM users WHERE id = 1");
        });
        $this->assertArrayHasKey('statement_fingerprint', $record['timeline'][0]);
        $this->assertStringStartsWith('fp1:', $record['timeline'][0]['statement_fingerprint']['fp_hash']);
        $this->assertValidRecord($record, 'minimal');
    }

    public function testTokenizedRecordConforms()
    {
        $config = new Config('shared-secret');
        $record = $this->buildRecord($config, function (Collector $c) {
            $http = new HttpInput('POST', '/orders');
            $http->queryRaw          = ['page' => '2'];
            $http->requestRaw        = ['user_id' => 7, 'qty' => 3];
            $http->requestHeadersRaw = ['Authorization' => 'Bearer x'];
            $http->pathPattern       = '/orders';
            $c->start($http, new Flow('flow-1', 1));
            $c->addSql('SELECT * FROM users WHERE id = ?', [7]);
        });
        $this->assertValidRecord($record, 'tokenized');
    }

    public function testObservedValuesRecordConforms()
    {
        $config = new Config(null, ['sqlValueAllowlist' => ['orders.status']]);
        $record = $this->buildRecord($config, function (Collector $c) {
            $c->addSql("INSERT INTO orders (user_id, status) VALUES (10, 'shipped')");
        });
        $this->assertSame(
            ['ORDERS.STATUS' => ['redacted' => false, 'values' => ['shipped']]],
            $record['timeline'][0]['observed_values']
        );
        $this->assertValidRecord($record, 'observed_values');
    }

    public function testHtmlViewsRecordConforms()
    {
        $sink      = new SchemaCaptureSink();
        $collector = new Collector(new Config(), $sink, new OracleSqlAnalyzer());
        $collector->start(new HttpInput('GET', '/products/1'), new Flow());
        $response               = new HttpResponse();
        $response->status       = 200;
        $response->responseKind = 'html';
        $response->views        = [['template' => 'products/show', 'vars_raw' => ['id' => 1, 'name' => 'x']]];
        $collector->finish($response);
        $this->assertValidRecord($sink->captured, 'html_views');
    }

    public function testResponseHeadersRecordConforms()
    {
        $sink      = new SchemaCaptureSink();
        $config    = new Config('shared-secret', ['keepResponseHeaderKeys' => ['X-Trace-Id']]);
        $collector = new Collector($config, $sink, new OracleSqlAnalyzer());
        $collector->start(new HttpInput('GET', '/headers'), new Flow());

        $response = new HttpResponse();
        $response->status = 200;
        $response->responseKind = 'json';
        $response->responseHeadersRaw = [
            'Set-Cookie' => 'sid=secret; HttpOnly',
            'X-Trace-Id' => 'trace-123',
        ];
        $response->responseBodyRaw = ['ok' => true];
        $collector->finish($response);

        $this->assertArrayHasKey('response_headers_shape', $sink->captured['http']);
        $this->assertArrayHasKey('response_headers_tokens', $sink->captured['http']);
        $this->assertArrayNotHasKey('Set-Cookie', $sink->captured['http']['response_headers_shape']);
        $this->assertValidRecord($sink->captured, 'response_headers');
    }
}

/**
 * このテスト専用の Sink（CollectorTest の CaptureSink への読み込み順依存を避けるため自前定義）。
 */
class SchemaCaptureSink implements SinkInterface
{
    /** @var array|null */
    public $captured = null;

    public function write(array $trace)
    {
        $this->captured = $trace;
    }
}
