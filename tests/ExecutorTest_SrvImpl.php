<?php

class ExecutorTest_SrvImpl 
    implements \FutoIn\Executor\InterfaceImplementation,
        \FutoIn\Executor\AsyncImplementation
{
    public function ping( $as, $reqinfo )
    {
        $as->success([
            'ping' => $reqinfo->params()->ping,
            'pong' => 'PONGPONG',
        ]);
    }
    
    public function advancedcall( $as, $reqinfo )
    {
        if ( is_null($reqinfo->params()->d) ||
             ( $reqinfo->params()->d === "4" ) )
        {
            $as->error( \FutoIn\Error::InternalError );
        }
        
        $as->success();
    }
    
    public function wrongdata( $as, $reqinfo )
    {
        $as->success([
            'ping' => 'abcd',
        ]);
    }
    
    public function unknownresult( $as, $reqinfo )
    {
        $as->success([
            'ping' => 'abcd',
            'pong' => 'abcd',
        ]);
    }
    
    public function missingresult( $as, $reqinfo )
    {
        $as->success();
    }
    
    public function noresult( $as, $reqinfo )
    {
        $as->success([
            'ping' => 'abcd',
        ]);
    }
    
    public function data( $as, $reqinfo )
    {
        $if = $reqinfo->rawInput();
        $of = $reqinfo->rawOutput();
        fwrite( $of, fread($if, 256) );
        $as->success();
    }
}