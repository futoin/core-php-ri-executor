<?php

use \FutoIn\RI\Invoker\AdvancedCCM;
use \FutoIn\RI\Executor\HTTP\Executor;
use \FutoIn\RI\Executor\RequestInfo;


require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/ExecutorTest_SrvImpl.php';

//--
$specdirs = [
    __DIR__.'/../vendor/futoin/core-php-ri-invoker/tests/specs',
    __DIR__.'/specs'
];

//--
$as = new \FutoIn\RI\ScopedSteps();
$ccm = new AdvancedCCM(array(
        'specDirs' => $specdirs
));
$executor = new Executor(
    $ccm,
    array(
        'specDirs' => $specdirs,
        Executor::OPT_SUBPATH => '/Server_HTTPExecutorTest.php',
    )
);

$as->add(
    function($as) use ($executor) {
        $executor->register( $as, 'exec.derived:1.3', new ExecutorTest_SrvImpl( $executor ) );
        $executor->register( $as, 'exec.secure:1.1', new ExecutorTest_SrvImpl( $executor ) );
        $as->successStep();
    },
    function($as,$err){
        var_dump( $err );
        var_dump( $as->error_info );
    }
);
$as->run();
//--
$executor->handleRequest();
