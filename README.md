# tekagami

> [!NOTE]
> このライブラリは実験中になります。

稼働中の PHP ウェブアプリが実際に何をしているかを、1リクエスト1行の JSONL（`tekagami-v1`）として記録するライブラリ。

**目的**: 仕様発見のための「観測証拠」生成。AI や人間がその証拠から仕様候補を発見し、移行後は legacy / target の差分確認にも使えるようにする。

- フレームワーク非依存・PHP 7.0 以上・Composer 配布
- APM ではない（速度・レイテンシは測らない）
- 分析・命名・仕様断定はしない。出すのは観測された HTTP 入出力、SQL 時系列、副作用、custom event
- デフォルトで全値を shape（型構造）に変換（実値は出さない）。実値は白リスト（`keepKeys` / `sqlValueAllowlist`）で明示したものだけ残す
- HTTP ヘッダは存在情報も含めて白リスト（request: `keepHeaderKeys` / response: `keepResponseHeaderKeys`）で明示したものだけ残す
- HMAC 方式のトークン化で「同一値の追跡」を実値なしで可能に
- 書き込み失敗はアプリに伝播しない

仕様発見と移行調査は並列ではなく、次の順で扱います。

1. **仕様発見**: 現行アプリの観測証拠から、実際に到達した入口、分岐、副作用、レスポンス shape を確認する
2. **移行調査**: 見つけた仕様候補を前提に、移行先の観測証拠と決定論的に比較する

## 全体像

```mermaid
flowchart LR
    A["HTTP request"] --> B["Collector"]
    B --> C["JSONL\n1 request = 1 line"]
    C --> D["summary\nendpoint catalog"]
    C --> E["report/export\nfiltered evidence"]
    E --> F["AI / human\nspec discovery"]
    E --> G["diff\nlegacy vs target"]
    G --> H["migration review"]
```

JSONL には HTTP 入出力の shape、SQL の時系列、custom イベント、errors、effects が入ります。`summary` は観測された endpoint 地図を作り、`report` はエントリポイントと実行パターンを人間向けに集計し、`export` はSQL全文を辞書化して AI に渡しやすい小さな JSON にします。`diff` は legacy / target の export を比較して決定論的な差分を出します。

モノレポ上の上位レイヤとして、観測証拠とコード・DDL・マスタデータを合わせて仕様候補を作るための [spec workflow](../spec/README.md) と、言語非依存な [schema contract](../contracts/schema/README.md) があります。`tekagami` は PHP アプリ用 observer / Composer package です。

## ソースコード解析・RAGとの関係

tekagami は、ソースコードやテーブル定義を AI / RAG に読ませる分析の代替ではなく、そこに **実行時に観測された証拠データ** を足すためのライブラリです。

ソースコードと DDL からは「実装されている可能性がある処理」や「データ構造」は分かります。一方で、それだけでは、その処理が実際にどの入口から到達されているか、どの分岐が通っているか、SQL がどの順番で発行されるか、実際のリクエスト/レスポンス shape がどうなっているかは判断しづらいことがあります。

tekagami の JSONL / report / export を一緒に渡すと、AI や人間は次のように、推測と観測事実を分けて読めます。

- コード上の処理候補に対して、実際に観測された HTTP 入口・ステータス・SQL 時系列を照合できる
- テーブル定義だけでは分からない、実データ上の型ゆれ・欠損・レスポンス shape を確認できる
- レガシーと移行先で、SQL 文字列が違っても layer-B fingerprint による意味近似一致候補を見られる
- 観測されていないケースを「存在しない仕様」と断定せず、観測範囲の限界として扱える

つまり、RAG に渡す材料を「コードとDDL」だけでなく「実際に起きたリクエスト単位の事実」まで広げることで、仕様洗い出しや移行差分分析の裏取りをしやすくします。

## インストール

```bash
composer require coffee-r/tekagami-php
```

> 動作要件は PHP 7.0 以上。テストの実行は PHP 7.3 以上（dev 依存の PHPUnit ^9.5 が要求）。

## 基本的な使い方

```php
use CoffeeR\Tekagami\Collector;
use CoffeeR\Tekagami\Config;
use CoffeeR\Tekagami\Flow;
use CoffeeR\Tekagami\Http\HttpInput;
use CoffeeR\Tekagami\Http\HttpResponse;
use CoffeeR\Tekagami\Sink\JsonlSink;
use CoffeeR\Tekagami\Sql\SqliteSqlAnalyzer;

// Controllerの基底クラスのConstructorなど、1リクエスト単位で設定します
$collector = new Collector(
    new Config(getenv('TEKAGAMI_SECRET') ?: null),
    new JsonlSink('/var/log/tekagami/your-app-name.production.today-yyyymmdd.jsonl'), // JSONL ファイルへ書き出し
    new SqliteSqlAnalyzer()                                      // SQL 方言は必須・明示注入
);

// Httpレベルのリクエスト情報を指定します。
$http = new HttpInput($yourRequest->method(), $yourRequest->path());
$http->queryRaw          = $yourRequest->query();        // CI3: $this->input->get()  | Laravel: $request->query()
$http->requestRaw        = $yourRequest->requestBody();  // CI3: json_decode($this->input->raw_input_stream, true) | Laravel: $request->all()
$http->requestHeadersRaw = $yourRequest->headers();      // CI3: $this->input->request_headers() | Laravel: $request->headers->all()
$http->pathPattern       = '/products/{id}';             // フレームワークのルート定義などから

// これ以降、後続の証拠データ追加メソッドが使えます。
// flow を指定しない場合、flow_id / seq は null として記録されます。
$collector->start($http);

// 開発・QA の調査で明示的な相関が必要な場合だけ Flow を渡します。
// $collector->start($http, new Flow('qa-order-cod-001', 1));

// SQL（推奨: bind 前 SQL + binds）
$collector->addSql($sql, $binds, ['source' => 'db-wrapper']);

// last_query() など展開済み SQL しか取れない場合は低信頼 API を使います。
$collector->addExpandedSql($db->last_query(), ['source' => 'ci3-last_query']);

// カスタムイベント（業務分岐に名前を付ける観測アンカー）
$collector->addCustom('purchase_limit_rejected', ['reason' => 'one_time']);

// Httpレベルのレスポンス情報を指定します。
$response               = new HttpResponse();
$response->status       = http_response_code();
$response->responseKind = 'json';
$response->responseHeadersRaw = $yourResponse->headers(); // 任意。keepResponseHeaderKeys に一致したものだけ記録
$response->responseBodyRaw = $responseData;

// Controllerの基底クラスのDestructorやテンプレートファイルに変数を渡す直前などに差し込みます。
// このメソッドで指定したJSONLに書き込まれます。
$collector->finish($response);
```

## 設定オプション（Config）

コンストラクタ: `new Config($secret = null, array $options = [])`

`$secret` は第1引数（位置引数）、それ以外は `$options` 配列で渡す。

| オプション | 型 | デフォルト | 説明 |
|---|---|---|---|
| `secret` *(第1引数)* | string\|null | null | HMAC-SHA256 の共有シークレット。設定時に `*_tokens` フィールドを記録。null = トークン化なし |
| `keepKeys` | array | `[]` | 実値を残すキー名の白リスト（query/body、完全一致・大小無視）。ネストした配列も再帰的にスキャンし、同名キーが複数の深さにある場合は浅い方が優先。空 = 実値を一切残さない |
| `keepHeaderKeys` | array | `[]` | 記録するHTTPリクエストヘッダ名の白リスト（完全一致・大小無視）。空 = リクエストヘッダの存在情報も記録しない |
| `keepResponseHeaderKeys` | array | `[]` | 記録するHTTPレスポンスヘッダ名の白リスト（完全一致・大小無視）。空 = レスポンスヘッダの存在情報も記録しない |
| `sqlValueAllowlist` | array | `[]` | SQL の実値を残す列名の白リスト（`'table.column'` または `'column'`） |
| `enabled` | bool | true | false で全メソッドを即時スキップ（shape 生成・HMAC 等も行わない）。`NullSink` より低コストな無効化手段 |
| `captureText` | bool | false | 生 SQL テキストを `statement_text` に保存（**平文**・開発用。本番非推奨） |
| `captureEffects` | bool | true | INSERT/UPDATE/DELETE の集計を `effects[]` に出力 |
| `tokenHmacLength` | int | 12 | `*_tokens` フィールドの HMAC-SHA256 hex 桁数 |
| `maxDepth` | int | 10 | shape 生成の再帰深さ上限 |
| `maxShapeNodes` | int | 10000 | shape 生成のノード訪問数上限（メモリ対策） |
| `maxTimelineSize` | int\|null | 500 | timeline イベント数上限。超過で以降を無視し `errors[]` に記録（null = 無制限） |

`sqlValueAllowlist` は SQL 文字列から正規表現ベースで値を拾う best-effort 機能です。単純な `INSERT ... VALUES (...)`、`UPDATE ... SET ...`、`WHERE col = value` などを対象にしており、関数呼び出し、カンマを含む文字列、複雑なサブクエリ、DB 方言固有のリテラルでは抽出できないことがあります。抽出できない場合も観測自体は失敗扱いにせず、`statement_normalized` と shape を主な証拠として残します。

採取対象を絞る場合は、フレームワーク側の差し込み箇所や環境設定で Collector を呼ぶ範囲を制御します。このライブラリは仕様・移行調査用の証拠採取が目的で、低頻度の分岐やエッジケースを落とさないことを優先します。

## SQL 採取 API

SQL は入力品質で 2 つの API を使い分けます。

| API | 入力 | `analysis.input_quality` | 用途 |
|---|---|---|---|
| `addSql($sql, $binds, ['source' => '...'])` | プレースホルダ付き SQL + binds | `bound_sql` | DB ラッパー、Laravel `DB::listen`、Doctrine middleware、PDO wrapper、CI3 `DB_driver::query()` 改造など |
| `addExpandedSql($sql, ['source' => '...'])` | bind 展開済み SQL | `expanded_sql` | CI3 `last_query()` / query history など、bind 分離後の値が取れない場合 |

`addExpandedSql()` は `expanded_sql_may_fragment_statement_hash` warning を出します。source に `last_query` / `query_history` を含めると、bind 分離不能を示す warning も追加されます。観測ゼロよりは有用ですが、移行比較では層A `statement_hash` が割れやすいため、取れる環境では `addSql()` を優先してください。

## Custom event は仕様発見のアンカー

SQL flow だけでも「どのテーブルを読んだか」「どの更新が起きたか」は分かります。ただし、同じ `422` や同じ `INSERT` なしの分岐でも、それが購入制限なのか、配送制約なのか、ポイント不足なのかは SQL だけでは断定しにくいことがあります。

`addCustom()` は、業務ロジックへ大量に手を入れるためのログではなく、**業務分岐に名前を付ける観測アンカー**です。

| 観測 | SQLだけで読めること | custom event があると読めること |
|---|---|---|
| `POST /api/orders` が `422`、注文 `INSERT` なし | 注文作成前に拒否された | `purchase_limit_rejected` なら購入制限による拒否候補として読める |
| カート投入後に `SHOP_CART_ITEMS` が追加で `INSERT` | 何か同梱行が増えた | `gift_attached` ならギフト同梱として読める |
| checkout quote が `422` | 見積もりが拒否された | `payment_method_filtered` / `delivery_date_rejected` なら拒否理由の候補が分かる |

custom イベントの `data_shape` は実行パターンの signature には入りません。業務分岐を比較対象にしたい場合は、`payment_method_rejected_cod` のような安定した `label` で分岐理由を表してください。

## SQL 方言の選択

SQL の正規化・テーブル抽出・トークン化は RDBMS の方言ごとに挙動が変わる（文字列リテラルの `''` エスケープ、識別子クォート、Oracle の `q'[...]'` / `N'...'` / `FROM dual` / dblink、SQLite の `[ident]` など）。アナライザは **`Collector` の第3引数で明示注入する**（必須・null 不可）。同梱は `OracleSqlAnalyzer` / `SqliteSqlAnalyzer` の 2 種。

```php
$collector = new Collector(
    new Config(getenv('TEKAGAMI_SECRET') ?: null),
    new JsonlSink('/var/log/tekagami/legacy-shop.production.jsonl'),
    new OracleSqlAnalyzer()   // または new SqliteSqlAnalyzer()
);
```

注入したアナライザは各 SQL イベントの `analysis.dialect`（`oracle` / `sqlite`）に記録される。

他の RDBMS（PostgreSQL/MySQL/SQLServer など）を扱う場合は `SqlAnalyzerInterface` を実装（または `AbstractSqlAnalyzer` を継承して方言フックだけ上書き）し、同じく第3引数に渡す。

```php
$collector = new Collector($config, $sink, new MyPostgresSqlAnalyzer());
```

## SQL の2層シグネチャ

SQL イベントには、2種類の署名が出力されます。

- **層A `statement_hash`** — `statement_normalized` の `sha256:<hex>`。正規化SQL文字列の厳密同一性を見るための署名です。同じ実装・同じSQLパターンをまとめる用途に向いています。`NULL` 値と DB 生成時刻（`SYSTIMESTAMP` / `CURRENT_TIMESTAMP` / `CURRENT_DATE` / `SYSDATE` / `NOW()`）は比較ノイズを減らすため正規化されます。
- **層B `statement_fingerprint.fp_hash`** — 操作種別、対象テーブル、絞り込み列、書込列から作る `fp1:<hex>`。CI3 の生SQLと Laravel/Eloquent のSQLのように文字列が変わっても、意味レベルの比較材料として使います。

`statement_fingerprint` は現在の `tekagami-v1` では必須です。旧形式の JSONL（このフィールドがないもの）は新スキーマの検証対象外です。

## CLI ツール（bin/tekagami）

```bash
# 観測された endpoint 地図を作る
php bin/tekagami summary trace.jsonl
php bin/tekagami summary trace.jsonl --format json

# Markdown レポート生成（エントリポイント × 実行パターン集計）
php bin/tekagami report trace.jsonl
php bin/tekagami report --format json jan.jsonl feb.jsonl > report.json

# AI 送付用のコンパクト export（SQL 全文は辞書化し、各 step は S1/S2 と fp で参照）
php bin/tekagami export trace.jsonl > trace.export.json
php bin/tekagami export trace.jsonl --format md > trace.export.md

# 100 endpoint 級では endpoint / path / method で小さく切り出す
php bin/tekagami export trace.jsonl --entrypoint "POST /api/cart/items"
php bin/tekagami export trace.jsonl --path "/api/cart/*" --path "/api/products/*"
php bin/tekagami report trace.jsonl --method POST --path "/api/cart/*"

# 移行評価では旧系/新系をそれぞれ export して比較する
php bin/tekagami export legacy.jsonl > legacy.export.json
php bin/tekagami export target.jsonl > target.export.json

# 2つの export を比較（JSON 形式または Markdown 形式）
php bin/tekagami diff legacy.export.json target.export.json
php bin/tekagami diff legacy.export.json target.export.json --format md

# SQL 値の表示モードを指定（デフォルト: normalized / tokenized も選択可）
php bin/tekagami report --value-mode tokenized trace.jsonl

# 複数サーバのログをまとめてレポート（ロードバランス環境）
php bin/tekagami report server1.jsonl server2.jsonl server3.jsonl

```

`summary` は 100 endpoint 級の入口地図です。観測された endpoint ごとに `group_hint`、リクエスト数、status 分布、pattern 数、count=1 の pattern 数、custom event 数、write effects の有無、error/truncation を出します。`group_hint` は `/api/cart` のような URL prefix 由来の機械的なまとまりで、業務名ではありません。

レポートは HTTP エントリポイントと実行パターンを決定論的にまとめる証拠ビューです。業務ルールの命名や推論は行わず、必要な判断材料は `keepKeys` / `sqlValueAllowlist` / `addCustom()` で観測事実として残します。

`report` / `export` / `summary` は複数 JSONL ファイルを入力できます。ログファイル単位は観測範囲（期間・環境・調査回）を表し、`--entrypoint` / `--path` / `--method` は分析範囲を表します。元 JSONL は証拠原本として残し、filter 後の出力は分析用の派生成果物として扱います。

filter のルール:

- `--entrypoint` は `METHOD path_pattern` に対する `*` wildcard。複数指定は OR
- `--path` は `path_pattern` があればそれを優先し、なければ実 path に対する `*` wildcard。複数指定は OR
- `/api/cart/*` のように末尾が `/*` の場合は `/api/cart` 自体も含める
- `--entrypoint` と `--path` は OR
- `--method` は全体に対する AND

`diff` は 2 つの export.json を比較して決定論的な差分を出します。エントリポイントの追加/消失、実行パターンの変化、status_codes の差異、effects の変化、custom event の差、layer-B fp が一致するが SQL 文字列が異なる「意味近似一致候補（meaning_near_matches）」を検出します。

`report` は完全な時系列シグネチャ（`observed_flow_signature`）に加えて、連続する同一 SQL / custom イベントを `xN` でまとめた圧縮シグネチャ（`compressed_flow_signature`）も出力します。N+1 のように同じ SQL が件数分だけ繰り返されるケースを、件数差を残しながら読みやすくするためです。timeline が `maxTimelineSize` で打ち切られたトレースは、シグネチャ末尾に `TRUNCATED:<limit>` が付き、pattern に `truncated` / `truncation_limit` が出ます。

`export` は AI 送付用にトークン数を抑えた成果物を出します。SQL全文は `sql_dictionary` に一度だけ置き、各実行パターンの `sql_flow` は `S1` のような短IDと層B `fp` で参照します。JSON 出力はデフォルトで pretty print しません。

旧系/新系の移行評価では、legacy / target を別々に `export` し、必要に応じて両プロジェクトのコード、DDL、fixture と一緒に `diff` の結果を AI や人間へ渡します。層AのSQL文字列差分はフレームワーク移行で大きく変わりやすいため、`diff` は layer-B fp による意味近似一致候補も出力します。

## エラー・失敗の扱い

- キャプチャ中のエラーは JSONL の `errors[]` フィールドに記録される（`errors[]` が空 = クリーンキャプチャ）
- `sink->write()` の失敗のみ PHP の `error_log()` に出力し、アプリには伝播しない
- timeline が `maxTimelineSize` を超えた場合は以降を無視し `errors[]` に `capture_failure` を追記

## 値の扱い

実値はデフォルトでは残しません。構造と型は `*_shape` に残り、同じ値の相関だけ見たい場合は `secret` による不可逆 HMAC トークンを使います。AIや人間が実値として読む必要がある業務値だけ、`keepKeys` / `sqlValueAllowlist` に明示して `*_values` / `observed_values` に残します。

`keepKeys` / `sqlValueAllowlist` に入れた値は平文で JSONL に保存されます。`password`、`token`、メールアドレス、電話番号、住所、郵便番号、認証・決済情報、顧客IDや注文IDなど個人や取引に強く紐づく値は原則入れないでください。相関だけが必要なら `secret` による不可逆トークンを使います。

HTTP リクエストヘッダは `keepHeaderKeys`、HTTP レスポンスヘッダは `keepResponseHeaderKeys` に入れたものだけ記録します。ヘッダ実値の保存フィールドはなく、shape と、`secret` 設定時の HMAC token だけを出します。`Authorization`、`Cookie`、`Set-Cookie`、`Location`、`X-Api-Key` などは shape であっても存在自体が機微になりうるため、デフォルトでは記録しません。`X-Tekagami-Flow` も `keepHeaderKeys` に入れれば request header として token 化できますが、`flow.flow_id` は開発・QA が明示した非機密の調査IDとして生値で記録されます。

`addError()` に生の例外メッセージを渡すと、DSN、SQL、ファイルパス、個人情報が混ざる可能性があります。本番では固定メッセージやエラー種別だけを渡してください。

## ログボリュームの制御

本番でログが肥大化しないための主なレバー:

- **`maxTimelineSize`**（デフォルト 500）— N+1 等の暴走リクエストで 1 行が肥大化するのを防ぐ。
- **`maxShapeNodes`**（デフォルト 10000）— 巨大 JSON レスポンスの shape を打ち切る。
- **OS のログローテート**（logrotate 等）— JSONL ファイルの世代管理。

`maxDepth` / `maxShapeNodes` / `maxTimelineSize` に達した場合、tekagami の観測だけを打ち切り、アプリ本体の処理は止めません。打ち切りは `errors[]` に `capture_failure` として残ります。

res 単位での重複 in/out 削除のような重複排除は行わない。1 リクエスト 1 行という証拠データの性質と `flow` 相関が壊れるため、ボリュームは上記レバーで制御する。

## 出力スキーマ

`../contracts/schema/tekagami-v1.schema.json` を参照。

## ライセンス

MIT License — Copyright 2026 coffee-r
