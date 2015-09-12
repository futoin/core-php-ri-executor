<?php
/**
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

use \FutoIn\RI\Invoker\AdvancedCCM;
use \FutoIn\RI\Executor\Executor;
use \FutoIn\RI\Executor\RequestInfo;

require_once __DIR__.'/ExecutorTest_SrvImpl.php';
 
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
                'specDirs' => $specdirs
        ));
        $this->executor = new Executor(
            $this->ccm,
            array(
                'specDirs' => $specdirs
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
    
    /**
     * @expectedException \FutoIn\Error
     */
    public function testForbidCloneExecutor()
    {
        clone $this->executor;
        $this->assertTrue( true );
    }
    
    public function testRegistration()
    {
        $this->executor->register( $this->as, 'srv.test:1.1', 'SomeClass' );
        $this->assertTrue( true );
    }
    
    public function testDoubleReg()
    {
        $this->as->add(
            function($as){
                $this->executor->register( $as, 'srv.test:1.1', 'SomeClass' );
                $this->executor->register( $as, 'srv.test:1.1', 'SomeClass2' );
                $as->successStep();
            },
            function($as, $err)
            {
                $this->assertEquals( "Already registered", $as->error_info );
                $this->assertEquals( \FutoIn\Error::InternalError, $err );
            }
        )->add(function($as){
            $this->assertFalse( true );
        });
        
        $this->as->run();
    }
    
    public function testInheritReg()
    {
        $this->as->add(
            function($as){
                $this->executor->register( $as, 'exec.base:1.1', 'SomeClass' );
                $this->executor->register( $as, 'exec.derived:1.3', 'SomeClass2' );
                $as->successStep();
            },
            function($as, $err)
            {
                $this->assertEquals( "Conflict with inherited interfaces", $as->error_info );
                $this->assertEquals( \FutoIn\Error::InternalError, $err );
            }
        )->add(function($as){
            $this->assertTrue( false );
        });
        
        $this->as->run();
        $this->assertTrue( true );
    }
    
    public function testReqInfo()
    {
        $req = new RequestInfo( $this->executor, 'Not Valid JSON' );
        $this->assertEquals( $this->executor, $req->executor() );
        
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
    }
    
    /**
     * @expectedException \FutoIn\Error
     */
    public function testForbidCloneRequestInfo()
    {
        clone new RequestInfo( $this->executor, '{"p":{}}' );
        $this->assertTrue( true );
    }

    public function testExecutorStringImpl()
    {
        $this->commonTestExecutor( '\ExecutorTest_SrvImpl' );
    }
    
    public function testExecutorInstImpl()
    {
        $this->commonTestExecutor( new \ExecutorTest_SrvImpl( $this->executor ) );
    }
    
    public function testExecutorCallImpl()
    {
        $this->commonTestExecutor( [ $this, 'createSrvTestImpl' ] );
    }
    
    public function createSrvTestImpl( $executor )
    {
        return new \ExecutorTest_SrvImpl( $executor );
    }
    
    public function testExecutorClosureImpl()
    {
        $this->commonTestExecutor( function( $executor ){
            return new \ExecutorTest_SrvImpl( $executor );
        });
    }
    
    public function commonTestExecutor( $impl )
    {
        $req = new \StdClass;
        $req->f = 'exec.base:1.1:ping';
        $req->p = new \StdClass;
        $req->p->ping = 'PINGPING';

        $this->as->executed = false;
            
        $this->as->add(
            function($as) use ($impl) {
                $this->executor->register( $as, 'exec.base:1.1', $impl );
                $as->successStep();
            },
            function($as, $err) {
                var_dump( $err );
                var_dump( $as->error_info );
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
    
    public function testInvalidFunc()
    {
        $this->commonTestExecutorError(
            '{ "f" : "iface:1" }',
            \FutoIn\Error::InvalidRequest,
            "Invalid req->f"
        );
    }

    public function testInvalidVersion()
    {
        $this->commonTestExecutorError(
            '{ "f" : "iface:1:func" }',
            \FutoIn\Error::InvalidRequest,
            "Invalid req->f (version)"
        );
    }

    public function testUnknownIface()
    {
        $this->commonTestExecutorError(
            '{ "f" : "iface:1.1:func" }',
            \FutoIn\Error::UnknownInterface,
            "Unknown Interface"
        );
    }
    
    public function testNotSupportedMajor()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.base:2.1:func" }',
            \FutoIn\Error::NotSupportedVersion,
            "Different major version"
        );
    }
    
    public function testNotSupportedMinor()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.base:1.2:func" }',
            \FutoIn\Error::NotSupportedVersion,
            "Iface version is too old"
        );
    }
    
    public function testNotSupportedMinor2()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.derived:1.4:func" }',
            \FutoIn\Error::NotSupportedVersion,
            "Iface version is too old"
        );
    }
    
    public function testUnknownFunc()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.base:1.0:missing" }',
            \FutoIn\Error::InvalidRequest,
            "Not defined interface function"
        );
    }
    
    public function testSecureChannel()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.secure:1.0:ping" }',
            \FutoIn\Error::SecurityError,
            "Insecure channel"
        );
    }
    
    public function testAllowAnonymous()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.secure:1.0:ping" }',
            \FutoIn\Error::SecurityError,
            "Anonymous not allowed",
            [
                RequestInfo::INFO_SECURE_CHANNEL => true,
            ]
        );
    }

    public function testRawUpload()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.base:1.0:ping" }',
            \FutoIn\Error::InvalidRequest,
            "Raw upload is not allowed",
            [
                RequestInfo::INFO_HAVE_RAW_UPLOAD => true,
            ]
        );
    }
    
    public function testUnknownParameter()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.base:1.0:ping",
               "p" : {
                    "f" : "1234"
               }}',
            \FutoIn\Error::InvalidRequest,
            "Unknown parameter"
        );
    }
    
    public function testMissingParameter()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.base:1.0:advancedcall",
               "p" : {
                    "c" : 1
               }}',
            \FutoIn\Error::InvalidRequest,
            "Missing parameter"
        );
    }
    
    public function testMissingParameter2()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.base:1.0:advancedcall" }',
            \FutoIn\Error::InvalidRequest,
            "Missing parameter"
        );
    }
    
    public function testParameterType()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.base:1.0:advancedcall",
               "p" : {
                    "a" : "a",
                    "b" : 2,
                    "c" : 3
               }}',
            \FutoIn\Error::InvalidRequest,
            "Type mismatch for parameter"
        );
    }
    
    public function testDefaultValue()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.base:1.0:advancedcall",
               "p" : {
                    "a" : 1,
                    "b" : 2,
                    "c" : 3
               }}',
            \FutoIn\Error::InternalError,
            ""
        );
    }
    
    public function testNoDefaultValue()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.derived:1.0:advancedcall",
               "p" : {
                    "a" : 1,
                    "b" : 2,
                    "c" : 3,
                    "d" : "4"
               }}',
            \FutoIn\Error::InternalError,
            ""
        );
    }
    
    public function testMissingMethodImpl()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.derived:1.3:noimpl" }',
            \FutoIn\Error::InternalError,
            "Missing function implementation"
        );
    }

    public function testRawDataRequired()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.derived:1.2:wrongdata" }',
            \FutoIn\Error::InternalError,
            "Raw result is expected"
        );
    }
    
    public function testUnknownResultVar()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.derived:1.2:unknownresult" }',
            \FutoIn\Error::InternalError,
            "Unknown result variable 'ping'"
        );
    }
    
    public function testMissingResultVar()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.derived:1.2:missingresult" }',
            \FutoIn\Error::InternalError,
            "Missing result variables"
        );
    }
    
    public function testNoResultVar()
    {
        $this->commonTestExecutorError(
            '{ "f" : "exec.derived:1.2:noresult" }',
            \FutoIn\Error::InternalError,
            "No result variables are expected"
        );
    }



    public function commonTestExecutorError( $req_json, $exerr, $exerr_info, $add_info=[] )
    {
        $this->as->executed = false;
            
        $this->as->add(
            function($as) {
                $this->executor->register( $as, 'exec.derived:1.3', '\ExecutorTest_SrvImpl' );
                $this->executor->register( $as, 'exec.secure:1.1', '\ExecutorTest_SrvImpl' );
                $as->successStep();
            },
            function($as, $err) {
                var_dump( $err );
                var_dump( $as->error_info );
            }
        )->add(
            function($as) use ( $req_json, $add_info ){
                $as->reqinfo = new RequestInfo( $this->executor, $req_json );
                
                foreach( $add_info as $k => $v )
                {
                    $as->reqinfo->info()->{$k} = $v;
                }
                
                $this->executor->process( $as );
                $as->successStep();
            },
            function($as, $err) {
                var_dump( $err );
                var_dump($as->error_info);
            }
        )->add(
            function($as) use ( $exerr, $exerr_info ){
                $req = json_decode( $as->reqinfo->info()->{RequestInfo::INFO_RAW_RESPONSE} );
                
                $this->assertEquals( $exerr_info, $as->error_info );
                $this->assertEquals( $exerr, $req->e );
                $this->assertTrue( !isset( $req->r ) );

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
