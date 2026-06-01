<?php

namespace CoffeeR\Tekagami;

use CoffeeR\Tekagami\Http\HttpInput;
use CoffeeR\Tekagami\Http\HttpResponse;
use CoffeeR\Tekagami\Redaction\Redactor;
use CoffeeR\Tekagami\Sink\SinkInterface;
use CoffeeR\Tekagami\Sql\SqlAnalyzerInterface;
use CoffeeR\Tekagami\Sql\SqlFingerprinter;
use CoffeeR\Tekagami\Sql\SqlValueExtractor;

/**
 * CollectorInterface の標準実装。
 *
 * コンストラクタで Config / SinkInterface を受け取り、
 * トレースのライフサイクルを管理する。
 * アプリへの例外伝播ゼロが原則。
 */
class Collector implements CollectorInterface
{
    /** @var Config */
    private $config;

    /** @var SinkInterface */
    private $sink;

    /** @var Redactor */
    private $redactor;

    /** @var SqlAnalyzerInterface */
    private $sqlAnalyzer;

    /** @var SqlValueExtractor */
    private $valueExtractor;

    /** @var SqlFingerprinter */
    private $fingerprinter;

    // ---- アクティブトレースの状態 ----

    /** @var string|null */
    private $traceId = null;

    /** @var string|null */
    private $startedAt = null;

    /** @var HttpInput|null */
    private $http = null;

    /** @var Flow|null */
    private $flow = null;

    /** @var array */
    private $timeline = [];

    /** @var array */
    private $errors = [];

    /** @var int  全イベント共通の seq カウンタ */
    private $seq = 0;

    /** @var bool  timeline 打ち切り済みフラグ（エラー追記を1回に限定） */
    private $timelineTruncated = false;

    public function __construct(
        Config $config,
        SinkInterface $sink,
        SqlAnalyzerInterface $sqlAnalyzer
    ) {
        $this->config      = $config;
        $this->sink        = $sink;
        $this->redactor    = new Redactor($config);
        $this->sqlAnalyzer = $sqlAnalyzer;
        $this->valueExtractor = new SqlValueExtractor();
        $this->fingerprinter = new SqlFingerprinter($this->valueExtractor);
    }

    // -------------------------------------------------------------------------
    // CollectorInterface 実装
    // -------------------------------------------------------------------------

    public function start(HttpInput $http, $flow = null)
    {
        if (!$this->config->enabled) {
            return;
        }
        $this->traceId           = $this->generateUuid();
        $this->startedAt         = date('c');
        $this->http              = $http;
        $this->flow              = $flow;
        $this->timeline          = [];
        $this->errors            = [];
        $this->seq               = 0;
        $this->timelineTruncated = false;
    }

    public function getActiveTraceId()
    {
        return $this->traceId;
    }

    public function addSql($sql, array $binds = [], array $options = [])
    {
        $this->addSqlEvent($sql, $binds, $options, 'bound_sql');
    }

    public function addExpandedSql($sql, array $options = [])
    {
        $this->addSqlEvent($sql, [], $options, 'expanded_sql');
    }

    /**
     * @param string $statement
     * @param array  $binds
     * @param array  $options
     * @param string $inputQuality
     * @return void
     */
    private function addSqlEvent($statement, array $binds, array $options, $inputQuality)
    {
        if (!$this->config->enabled) {
            return;
        }
        if ($this->traceId === null) {
            return;
        }
        if ($this->isTimelineFull()) {
            return;
        }

        try {
            $this->seq++;
            $source = isset($options['source']) ? (string)$options['source'] : 'unknown';
            $normalized = $this->sqlAnalyzer->normalize($statement);
            $hash       = $this->sqlAnalyzer->hash($normalized);
            $operation  = $this->sqlAnalyzer->extractOperation($statement);
            $tables     = $this->sqlAnalyzer->extractTables($statement);
            $analysis   = $this->sqlAnalyzer->buildAnalysis($statement, $operation, $tables, $source);
            $analysis   = $this->withInputQuality($analysis, $inputQuality, $source);
            $fingerprint = $this->buildSqlFingerprint($statement, $operation, $tables, $analysis);

            // sqlValueAllowlist にマッチした列の実値を抽出する。
            // 空配列のときは json_encode で [] になりスキーマ（type:object）に違反するため
            // stdClass にフォールバックして {} を出力する。
            $observed = $this->valueExtractor->extract($statement, $tables, $this->config->sqlValueAllowlist);

            $event = [
                'seq'                  => $this->seq,
                'type'                 => 'sql',
                'operation'            => $operation,
                'tables'               => $tables,
                'statement_normalized' => $normalized,
                'statement_hash'       => $hash,
                'statement_fingerprint' => $fingerprint,
                'observed_values'      => empty($observed) ? new \stdClass() : $observed,
                'analysis'             => $analysis,
            ];

            if ($inputQuality === 'bound_sql') {
                $event['bind_shape'] = $this->safeShape($binds, 'bind_shape');
            }

            if ($this->config->secret !== null) {
                $redactor = $this->redactor;
                $event['statement_tokens'] = $this->sqlAnalyzer->replaceWithCallback(
                    $statement,
                    function ($matched) use ($redactor) {
                        return $redactor->tokenize($matched);
                    }
                );
                if ($inputQuality === 'bound_sql' && !empty($binds)) {
                    $event['bind_tokens'] = $this->safeTokens($binds, 'bind_tokens');
                }
            }

            if ($this->config->captureText) {
                $event['statement_text'] = $statement;
            }

            $this->timeline[] = $event;
        } catch (\Throwable $e) {
            $this->addCaptureFailure('sql capture failed: ' . get_class($e), 'Collector::addSql');
        }
    }

    /**
     * @param array  $analysis
     * @param string $inputQuality
     * @param string $source
     * @return array
     */
    private function withInputQuality(array $analysis, $inputQuality, $source)
    {
        $analysis['input_quality'] = $inputQuality;

        if (!isset($analysis['warnings']) || !is_array($analysis['warnings'])) {
            $analysis['warnings'] = [];
        }

        if ($inputQuality === 'expanded_sql') {
            $analysis['warnings'][] = 'expanded_sql_may_fragment_statement_hash';

            $sourceLower = strtolower($source);
            if (strpos($sourceLower, 'query_history') !== false) {
                $analysis['warnings'][] = 'query_history_capture_has_no_bind_values';
            } elseif (strpos($sourceLower, 'last_query') !== false) {
                $analysis['warnings'][] = 'last_query_capture_has_no_bind_values';
            }
        }

        $analysis['warnings'] = array_values(array_unique($analysis['warnings']));

        return $analysis;
    }

    /**
     * SQL の層Bフィンガープリントを例外安全に生成する。
     *
     * @param string   $statement
     * @param string   $operation
     * @param string[] $tables
     * @param array    $analysis
     * @return array
     */
    private function buildSqlFingerprint($statement, $operation, array $tables, array $analysis)
    {
        $dialect = isset($analysis['dialect']) ? $analysis['dialect'] : null;
        try {
            return $this->fingerprinter->fingerprint($statement, $operation, $tables, $dialect);
        } catch (\Throwable $e) {
            try {
                return (new SqlFingerprinter())->fingerprint('', $operation, $tables, $dialect);
            } catch (\Throwable $fallbackError) {
                return [
                    'op'             => $operation,
                    'tables'         => $tables,
                    'filter_columns' => [],
                    'write_columns'  => [],
                    'fp_hash'        => 'fp1:' . hash('sha256', json_encode([
                        'op'             => $operation,
                        'tables'         => $tables,
                        'filter_columns' => [],
                        'write_columns'  => [],
                    ])),
                ];
            }
        }
    }

    public function addCustom($label, $data = null)
    {
        if (!$this->config->enabled) {
            return;
        }
        if ($this->traceId === null) {
            return;
        }
        if ($this->isTimelineFull()) {
            return;
        }

        try {
            $this->seq++;
            $event = [
                'seq'        => $this->seq,
                'type'       => 'custom',
                'label'      => $label,
                'data_shape' => $this->safeShape($data, 'custom.data_shape'),
            ];

            $this->timeline[] = $event;
        } catch (\Throwable $e) {
            $this->addCaptureFailure('custom capture failed: ' . get_class($e), 'Collector::addCustom');
        }
    }

    public function addError($type, $message = null, $at = null)
    {
        if (!$this->config->enabled) {
            return;
        }
        if ($this->traceId === null) {
            return;
        }
        $entry = ['type' => $type];
        if ($message !== null) {
            $entry['message'] = $message;
        }
        if ($at !== null) {
            $entry['at'] = $at;
        }
        $this->errors[] = $entry;
    }

    /**
     * @param string $message
     * @param string $at
     */
    private function addCaptureFailure($message, $at)
    {
        $this->addError('capture_failure', $message, $at);
    }

    public function finish(HttpResponse $response)
    {
        if (!$this->config->enabled) {
            return;
        }
        if ($this->traceId === null) {
            return;
        }

        $record = null;
        try {
            $record = $this->buildRecord($response);
        } catch (\Throwable $e) {
            $this->errors[] = [
                'type'    => 'capture_failure',
                'message' => 'record build failed: ' . get_class($e),
                'at'      => 'Collector::finish',
            ];
        }

        if ($record === null) {
            try {
                $record = $this->buildMinimalRecord();
            } catch (\Throwable $e) {
                error_log('tekagami: buildRecord and fallback both failed: ' . $e->getMessage());
                $this->resetState();
                return;
            }
        } else {
            // buildRecord 後に errors が追加されていた場合は更新
            $record['errors'] = $this->errors;
        }

        try {
            $this->sink->write($record);
        } catch (\Throwable $e) {
            error_log('tekagami: sink write failed: ' . $e->getMessage());
        } finally {
            $this->resetState();
        }
    }

    // -------------------------------------------------------------------------
    // 内部ヘルパー
    // -------------------------------------------------------------------------

    /**
     * timeline が上限に達しているか確認し、到達済みなら errors に追記して true を返す。
     *
     * @return bool  true = 上限到達（呼び出し元は処理をスキップする）
     */
    private function isTimelineFull()
    {
        if ($this->config->maxTimelineSize === null) {
            return false;
        }
        if (count($this->timeline) < $this->config->maxTimelineSize) {
            return false;
        }
        if (!$this->timelineTruncated) {
            $this->timelineTruncated = true;
            $this->addCaptureFailure(
                'timeline truncated: limit=' . $this->config->maxTimelineSize,
                'Collector'
            );
        }
        return true;
    }

    private function resetState()
    {
        $this->traceId           = null;
        $this->startedAt         = null;
        $this->http              = null;
        $this->flow              = null;
        $this->timeline          = [];
        $this->errors            = [];
        $this->seq               = 0;
        $this->timelineTruncated = false;
    }

    /**
     * 完全な tekagami-v1 レコードを組み立てる。
     *
     * @param HttpResponse $response
     * @return array
     */
    private function buildRecord(HttpResponse $response)
    {
        $record = [
            'schema_version' => 1,
            'trace_id'       => $this->traceId,
            'started_at'     => $this->startedAt,
            'flow'           => [
                'flow_id' => $this->flow ? $this->flow->flowId : null,
                'seq'     => $this->flow ? $this->flow->seq : null,
            ],
            'redaction' => [
                'tokenized'    => $this->config->secret !== null,
                'token_format' => $this->config->secret !== null
                    ? 'hmac-sha256:' . $this->config->tokenHmacLength
                    : null,
            ],
        ];

        $record['http']     = $this->buildHttpEnvelope($response);
        $record['timeline'] = $this->timeline;
        $record['effects']  = $this->config->captureEffects ? $this->buildEffects() : [];
        $record['errors']   = $this->errors;

        return $record;
    }

    /**
     * buildRecord が失敗したとき用の最小限フォールバックレコード。
     *
     * @return array
     */
    private function buildMinimalRecord()
    {
        return [
            'schema_version' => 1,
            'trace_id'       => $this->traceId,
            'started_at'     => $this->startedAt,
            'flow'           => [
                'flow_id' => $this->flow ? $this->flow->flowId : null,
                'seq'     => $this->flow ? $this->flow->seq : null,
            ],
            'redaction' => ['tokenized' => false, 'token_format' => null],
            'http'      => [
                'method' => $this->http ? $this->http->method : 'UNKNOWN',
                'path'   => $this->http ? $this->http->path   : '/',
            ],
            'timeline' => $this->timeline,
            'effects'  => [],
            'errors'   => $this->errors,
        ];
    }

    /**
     * HTTP エンベロープを構築する。
     *
     * @param HttpResponse $response
     * @return array
     */
    private function buildHttpEnvelope(HttpResponse $response)
    {
        $env = [
            'method' => $this->http->method,
            'path'   => $this->http->path,
        ];

        // パスパターンとトークン化パス
        if ($this->http->pathPattern !== null) {
            $env['path_pattern'] = $this->http->pathPattern;
            if ($this->config->secret !== null) {
                $env['path_tokens'] = $this->tokenizePath(
                    $this->http->path,
                    $this->http->pathPattern
                );
            }
        }

        $env['status']        = $response->status;
        $env['response_kind'] = $response->responseKind;
        $env['content_type']  = $response->contentType;

        // リクエストヘッダ
        if ($this->http->requestHeadersRaw !== null) {
            $headers = $this->redactor->buildHeaders($this->http->requestHeadersRaw);
            if (!empty($headers)) {
                $env['request_headers_shape'] = $this->safeShape($headers, 'request_headers_shape');
                if ($this->config->secret !== null) {
                    $env['request_headers_tokens'] = $this->safeTokens($headers, 'request_headers_tokens');
                }
            }
        }

        // レスポンスヘッダ
        if ($response->responseHeadersRaw !== null) {
            $headers = $this->redactor->buildResponseHeaders($response->responseHeadersRaw);
            if (!empty($headers)) {
                $env['response_headers_shape'] = $this->safeShape($headers, 'response_headers_shape');
                if ($this->config->secret !== null) {
                    $env['response_headers_tokens'] = $this->safeTokens($headers, 'response_headers_tokens');
                }
            }
        }

        // クエリパラメータ
        if ($this->http->queryRaw !== null) {
            $env['query_shape'] = $this->safeShape($this->http->queryRaw, 'query_shape');
            if ($this->config->secret !== null) {
                $env['query_tokens'] = $this->safeTokens($this->http->queryRaw, 'query_tokens');
            }
            $kept = $this->redactor->buildValues($this->http->queryRaw);
            if (!empty($kept)) {
                $env['query_values'] = $kept;
            }
        }

        // リクエストボディ
        if ($this->http->requestRaw !== null) {
            $env['request_shape'] = $this->safeShape($this->http->requestRaw, 'request_shape');
            if ($this->config->secret !== null) {
                $env['request_tokens'] = $this->safeTokens($this->http->requestRaw, 'request_tokens');
            }
            $kept = $this->redactor->buildValues((array)$this->http->requestRaw);
            if (!empty($kept)) {
                $env['request_values'] = $kept;
            }
        }

        // レスポンスボディ（JSON）
        if ($response->responseBodyRaw !== null) {
            $env['response_shape'] = $this->safeShape($response->responseBodyRaw, 'response_shape');
        }

        // ビュー（HTML レスポンス）
        if (!empty($response->views)) {
            $views = [];
            foreach ($response->views as $view) {
                $this->seq++;
                $viewEntry = [
                    'seq'      => $this->seq,
                    'template' => $view['template'],
                ];
                if (array_key_exists('vars_raw', $view)) {
                    $viewEntry['vars_shape'] = $this->safeShape($view['vars_raw'], 'view.vars_shape');
                }
                $views[] = $viewEntry;
            }
            $env['views'] = $views;
        }

        return $env;
    }

    /**
     * timeline の write ops から effects[] を集計する。
     *
     * @return array
     */
    private function buildEffects()
    {
        $writeOps   = ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE', 'UPSERT'];
        $effectsMap = [];

        foreach ($this->timeline as $event) {
            if ($event['type'] !== 'sql') {
                continue;
            }
            if (!in_array($event['operation'], $writeOps, true)) {
                continue;
            }

            $op     = $event['operation'];
            $hash   = $event['statement_hash'];
            $tables = !empty($event['tables']) ? $event['tables'] : [null];

            foreach ($tables as $table) {
                $key = json_encode([$op, $table, $hash]);
                if (!isset($effectsMap[$key])) {
                    $effectsMap[$key] = [
                        'op'             => $op,
                        'table'          => $table,
                        'statement_hash' => $hash,
                        'count'          => 0,
                    ];
                }
                $effectsMap[$key]['count']++;
            }
        }

        return array_values($effectsMap);
    }

    /**
     * @param mixed  $value
     * @param string $at
     * @return mixed
     */
    private function safeShape($value, $at)
    {
        try {
            $shape = $this->redactor->shape($value);
            $this->recordRedactorTruncation($at);
            return $shape;
        } catch (\Throwable $e) {
            $this->addCaptureFailure('shape capture failed: ' . get_class($e), $at);
            return '...';
        }
    }

    /**
     * @param mixed  $value
     * @param string $at
     * @return mixed
     */
    private function safeTokens($value, $at)
    {
        try {
            $tokens = $this->redactor->buildTokens($value);
            $this->recordRedactorTruncation($at);
            return $tokens;
        } catch (\Throwable $e) {
            $this->addCaptureFailure('token capture failed: ' . get_class($e), $at);
            return '...';
        }
    }

    /**
     * @param string $at
     */
    private function recordRedactorTruncation($at)
    {
        $reason = $this->redactor->lastTruncation();
        if ($reason !== null) {
            $this->addCaptureFailure($at . ' truncated: ' . $reason, $at);
        }
    }

    /**
     * path の動的セグメント（pathPattern の {xxx}）を HMAC トークンに置換する。
     *
     * @param string $path
     * @param string $pathPattern  例: '/products/{id}'
     * @return string|null
     */
    private function tokenizePath($path, $pathPattern)
    {
        $pathParts    = explode('/', $path);
        $patternParts = explode('/', $pathPattern);

        if (count($pathParts) !== count($patternParts)) {
            return null;
        }

        $result = [];
        foreach ($patternParts as $i => $segment) {
            if (preg_match('/^\\{.+\\}$/', $segment)) {
                $result[] = $this->redactor->tokenize($pathParts[$i]);
            } else {
                $result[] = $pathParts[$i];
            }
        }

        return implode('/', $result);
    }

    /**
     * UUID v4 を生成する。外部ライブラリ不要。
     *
     * @return string
     */
    private function generateUuid()
    {
        try {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);
            return sprintf(
                '%s-%s-%s-%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 12, 4),
                substr($hex, 16, 4),
                substr($hex, 20)
            );
        } catch (\Throwable $e) {
            // PHP 7 compatible fallback for rare entropy failures.
        }

        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
