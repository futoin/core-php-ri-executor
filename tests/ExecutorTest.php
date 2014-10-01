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
        $this->assertEquals( 1, ignore_user_abort(true) );
        $req->ignoreInvokerAbort( false );
        $this->assertEquals( 0, ignore_user_abort(false) );
    }
}