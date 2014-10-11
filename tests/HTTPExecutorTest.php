<?php
/**
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

use \FutoIn\RI\Invoker\AdvancedCCM;
use \FutoIn\RI\Executor\HTTP\Executor;

require_once __DIR__.'/ExecutorTest_SrvImpl.php';
 
/**
 * @ignore
 */
class HTTPExecutorTest extends PHPUnit_Framework_TestCase
{
    protected $as = null;
    protected $ccm = null;
    
    protected static $fpm_server = null;
    protected static $nginx_server = null;
    
    public static function setUpBeforeClass()
    {
        $tmlink = '/tmp/futoin_executor_test_dir';
        
        if ( file_exists( $tmlink ) ) unlink( $tmlink );
        symlink( __DIR__, $tmlink );
        
        if ( defined('HHVM_VERSION') )
        {
            self::$fpm_server = proc_open(
                "hhvm --mode server -vServer.Type=fastcgi -vServer.Port=9123",
                array(
                    0 => array("file", '/dev/null', "r"),
                    1 => array("file", '/dev/null', "w"),
                    2 => array("file", '/dev/null', "w"),
                ),
                $pipes
            );
        }
        else
        {
            self::$fpm_server = proc_open(
                "/usr/sbin/php5-fpm --fpm-config ".__DIR__."/misc/fpm.conf",
                array(
                    0 => array("file", '/dev/null', "r"),
                    1 => array("file", '/dev/null', "w"),
                    2 => array("file", '/dev/null', "w"),
                ),
                $pipes
            );
        }
        
        self::$nginx_server = proc_open(
            "/usr/sbin/nginx -c ".__DIR__."/misc/nginx.conf",
            array(
                0 => array("file", '/dev/null', "r"),
                1 => array("file", '/dev/null', "w"),
                2 => array("file", '/dev/null', "w"),
            ),
            $pipes
        );
        
        sleep( 1 ); // give it some time
    }
    
    public static function tearDownAfterClass()
    {
        $s = proc_get_status( self::$fpm_server );
        posix_kill( $s['pid'], SIGTERM );
        $s = proc_get_status( self::$nginx_server );
        posix_kill( $s['pid'], SIGTERM );

        proc_close( self::$fpm_server  );
        self::$fpm_server = null;
        proc_close( self::$nginx_server  );
        self::$nginx_server = null;
    }

    
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
        
        $this->as->add(function($as){
            $this->ccm->register( $this->as, 'base', 'exec.base:1.1', 'http://localhost:8080/Server_HTTPExecutorTest.php' );
            $this->ccm->register( $this->as, 'derived', 'exec.derived:1.3', 'http://localhost:8080/Server_HTTPExecutorTest.php/' );
            $this->ccm->register( $this->as, 'insecure', 'exec.secure:1.1', 'http://localhost:8080/Server_HTTPExecutorTest.php' );
            $this->ccm->register( $this->as, 'secure', 'exec.secure:1.1', 'secure+http://localhost:8080/Server_HTTPExecutorTest.php' );
        });
        $this->as->run();
    }
    
    public function tearDown()
    {
        $this->as = null;
        $this->ccm = null;
        gc_collect_cycles();
    }

    public function testSimpleCall()
    {
        $this->as->add(
            function($as){
                $bf = $this->ccm->iface( 'base' );
                $bf->call( $as, 'ping', array( 'ping' => 'PINGPING' ) );
            },
            function($as,$err){
                var_dump( $err );
                var_dump( $as->error_info );
                $this->assertFalse( true );
            }
        )->add(function($as,$rsp){
            $this->assertEquals( 'PINGPING', $rsp->ping );
            $this->assertEquals( 'PONGPONG', $rsp->pong );
        });
        $this->as->run();
    }
    
    public function testDataCall()
    {
        $this->as->add(
            function($as){
                $bf = $this->ccm->iface( 'base' );
                $bf->call( $as, 'data', array( 'ping' => 'PINGPING' ), 'MYDATA-HERE' );
            },
            function($as,$err){
                var_dump($err);
                var_dump($as->error_info);
                $this->assertFalse( true );
            }
        )->add(function($as,$rsp){
            $this->assertEquals( 'MYDATA-HERE', $rsp );
        });
        $this->as->run();
    }


}