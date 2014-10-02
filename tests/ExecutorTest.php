<?php
/**
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

use \FutoIn\RI\Invoker\AdvancedCCM;
use \FutoIn\RI\Executor\Executor;
use \FutoIn\RI\Executor\RequestInfo;

 
/**
 * @ignore
 */
class ExecutorTest extends PHPUnit_Framework_TestCase
{
    protected $as = null;
    protected $ccm = null;
    protected $executor = null;
    
    public function setUp()
    {
        $specdirs = [
            'tests/specs',
            'vendor/futoin/core-php-ri-invoker/tests/specs/'
        ];
        $this->as = new \FutoIn\RI\ScopedSteps();
        $this->ccm = new AdvancedCCM(array(
                AdvancedCCM::OPT_SPEC_DIRS => $specdirs
        ));
        $this->executor = new Executor(
            $this->ccm,
            array(
                Executor::OPT_SPEC_DIRS => $specdirs
            )
        );
    }
    
    public function tearDown()
    {
        $this->as = null;
        $this->ccm = null;
        $this->executor = null;
        gc_collect_cycles();
    }

    public function testRegistration()
    {
        $this->executor->register( $this->as, 'srv.test:1.1', 'SomeClass' );
        $this->assertTrue( true );
    }
    
    public function testReqInfo()
    {
        $req = new RequestInfo( $this->executor, 'Not Valid JSON' );
        $this->assertEquals( $this->executor, $req->context() );
        
        $req = new RequestInfo( $this->executor, '{"p":{}}' );

        $req->info();
        $this->assertEquals( $req->info()->{RequestInfo::INFO_RAW_REQUEST}->p, $req->params() );
        $this->assertEquals( $req->info()->{RequestInfo::INFO_RAW_RESPONSE}->r, $req->result() );
        $this->assertEquals( $req->{RequestInfo::INFO_RAW_REQUEST}->p, $req->params() );
        $this->assertEquals( $req->{RequestInfo::INFO_RAW_RESPONSE}->r, $req->result() );
        $this->assertTrue( isset( $req->{RequestInfo::INFO_RAW_RESPONSE}->r ) );
        $this->assertFalse( isset( $req->{RequestInfo::INFO_RAW_RESPONSE}->missing ) );
        $this->assertFalse( isset( $req->missing ) );
        
        $req->rawInput();
        $req->rawOutput();
        
        $req->ignoreInvokerAbort();
        
        if ( !defined('HHVM_VERSION') ) $this->assertEquals( 1, ignore_user_abort(true) );
        $req->ignoreInvokerAbort( false );
        if ( !defined('HHVM_VERSION') ) $this->assertEquals( 0, ignore_user_abort(false) );
    }
    
    public function testExecutor()
    {
        $req = new \StdClass;
        $req->f = 'srv.test:1.1:test';
        $req->p = new \StdClass;
        $req->p->ping = 'PINGPING';
        
        $this->as->executed = false;
        
        $this->as->add(
            function($as){
                $this->executor->register( $as, 'srv.test:1.1', '\ExecutorTest_SrvTestImpl' );
                $as->successStep();
            },
            function($as, $err) {
                var_dump( $err );
            }
        )->add(
            function($as) use ( $req ){
                $as->reqinfo = new RequestInfo( $this->executor, json_encode( $req, JSON_UNESCAPED_UNICODE ) );
                $this->executor->process( $as );
                $as->successStep();
            },
            function($as, $err) {
                var_dump( $err );
                var_dump($as->error_info);
            }
        )->add(
            function($as){
                $req = json_decode( $as->reqinfo->info()->{RequestInfo::INFO_RAW_RESPONSE} );
                $this->assertEquals( 'PINGPING', $req->r->ping );
                $this->assertEquals( 'PONGPONG', $req->r->pong );
                $as->executed = true;
                $as->success();
            },
            function($as, $err) {
                var_dump( $err );
            }
        );
        
        $this->as->run();
        $this->assertTrue($this->as->executed);
    }
}

class ExecutorTest_SrvTestImpl 
    implements \FutoIn\Executor\InterfaceImplementation,
        \FutoIn\Executor\AsyncImplementation
{
    public function test( $as, $reqinfo )
    {
        $as->success([
            'ping' => $reqinfo->params()->ping,
            'pong' => 'PONGPONG',
        ]);
    }
}