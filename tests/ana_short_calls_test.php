<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
spl_autoload_register(static function ($class) use ($root) {
    $prefix = 'GlpiPlugin\\G4freportsana\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
});
use GlpiPlugin\G4freportsana\Ana\Calculator;
use GlpiPlugin\G4freportsana\Ana\OfficePackage;
function verify($ok, $label) { if (!$ok) throw new RuntimeException($label); }
$start = strtotime('2026-11-01T00:00:00-03:00');
$input = ['month'=>'2026-11','end_epoch'=>$start+2500000,'timezone'=>'America/Sao_Paulo','clock_mode'=>'native_reference','methodology_confirmed'=>false,'queue_names'=>['ana']];
$calls = [];
foreach ([0,4,5] as $i=>$seconds) {
    $calls[] = ['id'=>'wait'.$i,'callid'=>'wait'.$i,'queue'=>'ana','entered_epoch'=>$start+1000+$i*100,'in_period'=>true,'answered'=>false,'abandoned'=>true,'wait_seconds'=>$seconds];
}
$calls[] = ['id'=>'answered','callid'=>'answered','queue'=>'ana','entered_epoch'=>$start+1400,'in_period'=>true,'answered'=>true,'wait_seconds'=>3];
$calls[] = ['id'=>'unknown','callid'=>'unknown','queue'=>'ana','entered_epoch'=>$start+1500,'in_period'=>true,'answered'=>false,'abandoned'=>true,'wait_seconds'=>null];
$s = Calculator::build($input, ['tickets'=>[],'calls'=>$calls,'calls_available'=>true]);
$ips6 = array_values(array_filter($s['metrics'], static fn($m)=>$m['id']==='IPS6'))[0];
verify($s['totals']['calls_received']===3 && $s['totals']['calls_answered']===1, '4s boundary: calls received/answered');
verify($s['totals']['calls_short_unanswered_excluded']===2, '4s boundary: exclusion counter');
verify(count($s['excluded_calls'])===2 && array_column($s['excluded_calls'],'wait_seconds')===[0,4], '4s boundary: audit evidence');
verify($ips6['denominator']===3 && $ips6['numerator']===1, '4s boundary: IPS6');
verify(OfficePackage::status(['status'=>'provisional'])==='Apurado', 'Word and Excel display label');
verify($ips6['status']==='provisional', 'Machine-readable provisional state preserved');
echo "OK: four-second exclusion, IPS6, audit and exporter label\n";
