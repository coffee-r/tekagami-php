<?php

namespace CoffeeR\Tekagami\Redaction;

use CoffeeR\Tekagami\Config;

/**
 * shape生成・HMACトークン化・マスキングを担う内部クラス。
 * Collector が唯一のインスタンス化箇所。
 */
class Redactor
{
    /** @var Config */
    private $config;

    /** @var string|null */
    private $lastTruncation = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * キーが keepKeys に完全一致するかを判定する（大小無視）。
     *
     * @param string $key
     * @return bool
     */
    public function isKept($key)
    {
        return $this->matchesKey($key, $this->config->keepKeys);
    }

    /**
     * キーが keepHeaderKeys に完全一致するかを判定する（大小無視）。
     *
     * @param string $key
     * @return bool
     */
    public function isHeaderKept($key)
    {
        return $this->matchesKey($key, $this->config->keepHeaderKeys);
    }

    /**
     * キーが keepResponseHeaderKeys に完全一致するかを判定する（大小無視）。
     *
     * @param string $key
     * @return bool
     */
    public function isResponseHeaderKept($key)
    {
        return $this->matchesKey($key, $this->config->keepResponseHeaderKeys);
    }

    /**
     * @param string $key
     * @param array  $keys
     * @return bool
     */
    private function matchesKey($key, array $keys)
    {
        $lower = strtolower((string)$key);
        foreach ($keys as $keep) {
            if (strtolower($keep) === $lower) {
                return true;
            }
        }
        return false;
    }

    /**
     * 値の shape（構造と型）を再帰的に生成する。
     *
     * インデックス配列は重複 shape を除去して圧縮する（[1,2,3] → ["number"]）。
     *
     * @param mixed       $value
     * @param string|null $key
     * @return mixed
     */
    public function shape($value, $key = null)
    {
        $this->lastTruncation = null;
        $nodesLeft = $this->config->maxShapeNodes;
        return $this->shapeInternal($value, $key, 0, $nodesLeft);
    }

    /**
     * 直前の shape/token 生成で打ち切りが起きた場合、その理由を返す。
     *
     * @return string|null
     */
    public function lastTruncation()
    {
        return $this->lastTruncation;
    }

    /**
     * @param mixed  $value
     * @param string|null $key
     * @param int    $depth
     * @param int    &$nodesLeft
     * @return mixed
     */
    private function shapeInternal($value, $key, $depth, &$nodesLeft)
    {
        if ($nodesLeft <= 0) {
            $this->lastTruncation = 'maxShapeNodes';
            return '...';
        }
        $nodesLeft--;

        if ($depth >= $this->config->maxDepth) {
            $this->lastTruncation = 'maxDepth';
            return '...';
        }

        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_object($value)) {
            $value = (array)$value;
        }
        if (is_array($value)) {
            if (empty($value)) {
                return [];
            }
            $keys   = array_keys($value);
            $isList = $keys === range(0, count($value) - 1);

            if ($isList) {
                $shapes = [];
                foreach ($value as $item) {
                    if ($nodesLeft <= 0) {
                        $this->lastTruncation = 'maxShapeNodes';
                        $shapes[] = '...';
                        break;
                    }
                    $shapes[] = $this->shapeInternal($item, null, $depth + 1, $nodesLeft);
                }
                return $this->uniqueShapes($shapes);
            } else {
                $result = [];
                foreach ($value as $k => $v) {
                    if ($nodesLeft <= 0) {
                        $this->lastTruncation = 'maxShapeNodes';
                        $result[$k] = '...';
                        break;
                    }
                    $result[$k] = $this->shapeInternal($v, $k, $depth + 1, $nodesLeft);
                }
                return $result;
            }
        }

        return 'string';
    }

    /**
     * serialize を使った深い比較による重複排除。
     * array_unique はネストした配列を 'Array' に変換して比較するため使えない。
     *
     * @param array $shapes
     * @return array
     */
    private function uniqueShapes(array $shapes)
    {
        $unique = [];
        $seen   = [];
        foreach ($shapes as $shape) {
            $key = serialize($shape);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[]   = $shape;
            }
        }
        return array_values($unique);
    }

    /**
     * 値を HMAC トークンに変換する。同じ値 → 同じトークン。
     *
     * @param mixed $value
     * @return string  例: '{p-a1b2c3d4ef12}'
     */
    public function tokenize($value)
    {
        return '{p-' . substr(
            hash_hmac('sha256', (string)$value, $this->config->secret),
            0,
            $this->config->tokenHmacLength
        ) . '}';
    }

    /**
     * 配列（またはスカラー）の全リーフ値を HMAC トークンに変換する。
     *
     * @param mixed $data
     * @return mixed
     */
    public function buildTokens($data)
    {
        $this->lastTruncation = null;
        $nodesLeft = $this->config->maxShapeNodes;
        return $this->buildTokensInternal($data, 0, $nodesLeft);
    }

    /**
     * @param mixed $data
     * @param int   $depth
     * @param int   &$nodesLeft
     * @return mixed
     */
    private function buildTokensInternal($data, $depth, &$nodesLeft)
    {
        if ($nodesLeft <= 0) {
            $this->lastTruncation = 'maxShapeNodes';
            return '...';
        }
        $nodesLeft--;

        if ($depth >= $this->config->maxDepth) {
            $this->lastTruncation = 'maxDepth';
            return '...';
        }

        if (!is_array($data) && !is_object($data)) {
            return $this->tokenize($data);
        }

        $data   = (array)$data;
        $keys   = array_keys($data);
        $isList = !empty($data) && $keys === range(0, count($data) - 1);

        if ($isList) {
            $result = [];
            foreach (array_values($data) as $value) {
                if ($nodesLeft <= 0) {
                    $this->lastTruncation = 'maxShapeNodes';
                    $result[] = '...';
                    break;
                }
                $result[] = $this->buildTokensInternal($value, $depth + 1, $nodesLeft);
            }
            return $result;
        }

        $result = [];
        foreach ($data as $key => $value) {
            if ($nodesLeft <= 0) {
                $this->lastTruncation = 'maxShapeNodes';
                $result[$key] = '...';
                break;
            }
            if (is_array($value) || is_object($value)) {
                $result[$key] = $this->buildTokensInternal($value, $depth + 1, $nodesLeft);
            } else {
                $result[$key] = $this->buildTokensInternal($value, $depth + 1, $nodesLeft);
            }
        }
        return $result;
    }

    /**
     * 配列から keepKeys（白リスト）に一致するキーの実値だけを再帰的に抽出する。
     *
     * ネストした配列も走査する。同名キーが複数の深さに存在する場合は浅い方が優先。
     *
     * @param array $data
     * @return array
     */
    public function buildValues(array $data)
    {
        $result = [];
        $this->collectValues($data, $result);
        return $result;
    }

    /**
     * @param array $data
     * @param array &$result
     */
    private function collectValues(array $data, array &$result)
    {
        foreach ($data as $key => $value) {
            if ($this->isKept($key) && !array_key_exists($key, $result)) {
                $result[$key] = $value;
            }
            if (is_array($value)) {
                $this->collectValues($value, $result);
            }
        }
    }

    /**
     * 配列から keepHeaderKeys に一致するヘッダだけを抽出する。
     *
     * @param array $headers
     * @return array
     */
    public function buildHeaders(array $headers)
    {
        $result = [];
        foreach ($headers as $key => $value) {
            if ($this->isHeaderKept($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 配列から keepResponseHeaderKeys に一致するレスポンスヘッダだけを抽出する。
     *
     * @param array $headers
     * @return array
     */
    public function buildResponseHeaders(array $headers)
    {
        $result = [];
        foreach ($headers as $key => $value) {
            if ($this->isResponseHeaderKept($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
