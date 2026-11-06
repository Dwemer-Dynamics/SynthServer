<?php declare(strict_types=1);
// Disposable cli-server fixture. It runs real main.php admission/ACK and production sql locking.
if (PHP_SAPI!=='cli-server' || getenv('PHPUNIT_TEST') || getenv('SYNTH_DB_NAME')!=='testdb') {
    http_response_code(503);exit;
}
$root=dirname(__DIR__,2);
if (str_starts_with($_SERVER['REQUEST_URI']??'','/lock-check')) {
    require_once $root.'/lib/postgresql.class.php';
    require_once $root.'/lib/request.php';
    $event=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event]+$event;
    $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=($_SERVER['HTTP_X_FIXTURE_CLAIMED']??'1')==='1';
    $GLOBALS['db']=new sql();
    try {
        $value=$GLOBALS['db']->withNativeRequestLock(static fn()=>true,2000,
            ($_SERVER['HTTP_X_FIXTURE_PUBLICATION']??'1')==='1');
        echo json_encode(['accepted'=>$value]);
    } catch(Throwable $error) {
        echo json_encode(['accepted'=>false,'exception'=>get_class($error),'code'=>$error->getMessage()]);
    }
    exit;
}
if (str_starts_with($_SERVER['REQUEST_URI']??'','/offer-failure')) {
    require_once $root.'/lib/postgresql.class.php';
    final class ContextAckFailureSql extends sql {
        public function withNativeRequestLock(callable $operation,int $statementTimeoutMs=0,bool $contextPublication=false) {
            if ($contextPublication)throw new RuntimeException('synthetic_offer_failure');
            return parent::withNativeRequestLock($operation,$statementTimeoutMs,$contextPublication);
        }
    }
    $GLOBALS['db']=new ContextAckFailureSql();
}
require $root.'/main.php';
