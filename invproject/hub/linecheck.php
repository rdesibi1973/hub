<?php
define('BASE_URL','https://hub.savannahexplorers.com');
ob_start();
include __DIR__.'/modules/operations/index.php';
$out=ob_get_clean();
$lines=explode("\n",$out);
echo 'Total lines: '.count($lines)."\n";
for($i=1047;$i<=1052;$i++){
    echo "Line ".($i+1).": ".htmlspecialchars(substr($lines[$i]??'',0,120))."\n";
}
