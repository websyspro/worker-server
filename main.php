<?php

use Websyspro\Server\Request;
use Websyspro\Server\WorkerServer;

$ws = new WorkerServer(); 
$ws->get( "/test/:id", fn( Request $request ) => $request->body );
$ws->start();