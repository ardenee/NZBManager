<?php
require("nzbp.php");
require("merge.inc");
$nzb    = new nzb;

$folder = "";

if(isset($_POST["folder"]))
	$folder = urldecode($_POST["folder"]);

if(is_dir($folder) and count($_POST["folders"])>0)
{
	foreach($_POST["folders"] as $key => $val)
	{
		$FileList = $nzb->GetFiles(rtrim($val, '/'), null, true);
		
		foreach($FileList as $key2 => $val2)
			rename($val2, $folder."/".GetFileName($nzb->Settings[9], $val2, $val));
		
		shell_exec("rm -rf '$val/@eaDir'"); // delete files in synology hidden folder
		rmdir($val."/@eaDir");              // delete synology hidden folder
		rmdir($val);		
	}
}

header("location: merging.php");
?>