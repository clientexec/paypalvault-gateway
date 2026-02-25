<?php

$_GET['fuse']='billing';
$_GET['action'] = 'gatewaycallback';
$_GET['plugin'] = 'paypalvault';

chdir('../../..');

require_once dirname(__FILE__).'/../../../library/front.php';