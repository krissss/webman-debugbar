<?php

namespace Kriss\WebmanDebugBar\DataCollector;

use DebugBar\DataCollector\TimeDataCollector as DebugBarTimeDataCollector;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Kriss\WebmanDebugBar\DebugBar;
use Kriss\WebmanDebugBar\Helper\ArrayHelper;
use Kriss\WebmanDebugBar\Laravel\DataCollector\QueryCollector;
use Kriss\WebmanDebugBar\Laravel\DataFormatter\QueryFormatter;

class LaravelQueryCollector extends QueryCollector
{
    protected array $config = [
        'with_params' => true, // 将参数绑定上
        'backtrace' => true, // 显示 sql 来源
        'backtrace_exclude_paths' => [], // 排除 sql 来源
        'timeline' => true, // 将 sql 显示到 timeline 上
        'duration_background' => true, // 相对于执行所需的时间，在每个查询上显示阴影背景
        'explain' => [
            'enabled' => false,
            'types' => ['SELECT'], // 废弃的设置，目前只支持 SELECT
        ],
        'hints' => false, // 显示常见错误提示
        'show_copy' => true, // 显示复制
        'slow_threshold' => false, // 仅显示慢sql，设置毫秒时间来启用
    ];

    public function __construct(array $config = [], DebugBarTimeDataCollector $timeCollector = null)
    {
        $this->config = ArrayHelper::merge($this->config, $config);

        if (!$this->config['timeline']) {
            $timeCollector = null;
        }

        parent::__construct($timeCollector);

        $this->setConfig();
    }

    /**
     * @inheritDoc
     */
    public function getAssets()
    {
        $assets = parent::getAssets();
        $assets['js'] = 'laravel/sqlqueries/widget.js';
        return $assets;
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return 'laravelDB';
    }

    /**
     * @inheritDoc
     */
    function getWidgets()
    {
        $name = $this->getName();
        return [
            $name => [
                "icon" => "database",
                "widget" => "PhpDebugBar.Widgets.LaravelSQLQueriesWidget",
                "map" => $name,
                "default" => "[]"
            ],
            "{$name}:badge" => [
                "map" => "{$name}.nb_statements",
                "default" => 0,
            ],
        ];
    }

    protected function setConfig()
    {
        $this->mergeBacktraceExcludePaths([
            '/webman-debugbar/',
            '/vendor/kriss/webman-debugbar',
            '/vendor/illuminate/support',
            '/vendor/illuminate/database',
            '/vendor/illuminate/events',
        ]);

        $this->setDataFormatter(new QueryFormatter());

        if ($this->config['with_params']) {
            $this->setRenderSqlWithParams(true);
        }

        if ($this->config['backtrace']) {
            $middleware = [];
            $this->setFindSource(true, $middleware);
        }

        if ($this->config['backtrace_exclude_paths']) {
            $excludePaths = $this->config['backtrace_exclude_paths'];
            $this->mergeBacktraceExcludePaths($excludePaths);
        }

        $this->setDurationBackground($this->config['duration_background']);

        if ($this->config['explain']['enabled']) {
            $types = $this->config['explain']['types'];
            $this->setExplainSource(true, $types);
        }

        if ($this->config['hints']) {
            $this->setShowHints(true);
        }

        if ($this->config['show_copy']) {
            $this->setShowCopyButton(true);
        }
    }

    public function addListener(Connection $db)
    {
        $db->listen(function (QueryExecuted $event) {
            $bindings = $event->bindings;
            $time = $event->time;
            $connection = $event->connection;
            $query = $event->sql;

            $threshold = $this->config['slow_threshold'];
            if (!$threshold || $time > $threshold) {
                if ($collector = $this->getRequestThisCollector()) {
                    $collector->addQuery($query, $bindings, $time, $connection);
                }
            }
        });

        $db->getEventDispatcher()->listen(TransactionBeginning::class, function (TransactionBeginning $transaction) {
            if ($collector = $this->getRequestThisCollector()) {
                $collector->collectTransactionEvent('Begin Transaction', $transaction->connection);
            }
        });

        $db->getEventDispatcher()->listen(TransactionCommitted::class, function (TransactionCommitted $transaction) {
            if ($collector = $this->getRequestThisCollector()) {
                $collector->collectTransactionEvent('Commit Transaction', $transaction->connection);
            }
        });

        $db->getEventDispatcher()->listen(TransactionRolledBack::class, function (TransactionRolledBack $transaction) {
            if ($collector = $this->getRequestThisCollector()) {
                $collector->collectTransactionEvent('Rollback Transaction', $transaction->connection);
            }
        });

        $db->getEventDispatcher()->listen('connection.*.beganTransaction', function ($event, $params) {
            if ($collector = $this->getRequestThisCollector()) {
                $collector->collectTransactionEvent('Begin Transaction', $params[0]);
            }
        });

        $db->getEventDispatcher()->listen('connection.*.committed', function ($event, $params) {
            if ($collector = $this->getRequestThisCollector()) {
                $collector->collectTransactionEvent('Commit Transaction', $params[0]);
            }
        });

        $db->getEventDispatcher()->listen('connection.*.rollingBack', function ($event, $params) {
            if ($collector = $this->getRequestThisCollector()) {
                $collector->collectTransactionEvent('Rollback Transaction', $params[0]);
            }
        });
    }

    /**
     * 获取 request 下每次新的当前 collector 对象
     * @return $this|null
     */
    protected function getRequestThisCollector(): ?self
    {
        $debugBar = DebugBar::instance();
        if (!$debugBar->hasCollector($this->getName())) {
            return null;
        }
        /** @var static $collector */
        $collector = $debugBar->getCollector($this->getName());
        return $collector;
    }
}