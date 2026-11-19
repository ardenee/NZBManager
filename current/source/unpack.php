<?php
require("nzbp.php");
include("unpack.inc");
$nzb  = new nzb;
$file = "";

if(isset($_GET["file"]))
	$file = urldecode($_GET["file"]);
	
if(strlen($file)>0) // single file
{	
	$cmd      = "";
	$fileext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
	$log      = $nzb->Settings[3].strtolower(pathinfo($file, PATHINFO_FILENAME));
	
	switch($fileext)
	{
		case "rar" : $cmd = "/opt/bin/unrar x -o+ \"".substr($file, 0, (strlen($file)-4)).".rar\" \"".substr($file, 0, (strrpos($file, "/")))."/\""; break;
		case "zip" : $cmd = "/opt/bin/unzip \"".substr($file, 0, (strlen($file)-4)).".zip\" -d \"".substr($file, 0, (strrpos($file, "/")))."/\""; break;
	//	case "7ip" : $cmd = "/opt/bin/zup x -o+ \"".substr($file, 0, (strlen($file)-4)).".7ip\" \"".substr($file, 0, (strrpos($file, "/")))."/\""; break;
		case "tar" : $cmd = "tar -x -f \"".substr($file, 0, (strlen($file)-4)).".tar\" -C \"".substr($file, 0, (strrpos($file, "/")))."/\""; break;
		case "spk" : $cmd = "tar -x -f \"".substr($file, 0, (strlen($file)-4)).".spk\" -C \"".substr($file, 0, (strrpos($file, "/")))."/\""; break;
		case "tgz" : $cmd = "tar -xzv -f \"".substr($file, 0, (strlen($file)-4)).".tgz\" -C \"".substr($file, 0, (strrpos($file, "/")))."/\""; break;
		default    : $cmd = "/opt/bin/unrar x -o+ \"".substr($file, 0, (strlen($file)-4)).".rar\" \"".substr($file, 0, (strrpos($file, "/")))."/\""; break;
	}

	if(is_file($file))
		$d = shell_exec($cmd." > \"".$log."\" 2>&1 &");		

	header("Location: unpackreport.php?log=$log&cmd=$cmd");
}
else if(count($_POST["files"])>0)
{
	echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
	echo "<html>\n";
	echo " <head>\n";
	echo "  <title>NZB</title>\n";
	echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
	echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
	echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
	echo " </head>\n";
		
	echo " <form action=unpackreport.php method=post id=unpack name=unpack>\n";

	foreach($_POST["files"] as $key => $val)
	{	
		if($key==0) // always unpack first file then send to report with additional files
		{
			$cmd      = "";					
			$fileext  = strtolower(pathinfo($val, PATHINFO_EXTENSION));

			switch($fileext)
			{
				case "rar" : $cmd = "/opt/bin/unrar x -o+ \"".substr($val, 0, (strlen($val)-4)).".rar\" \"".substr($val, 0, (strrpos($val, "/")))."/\""; break;
				case "zip" : $cmd = "/opt/bin/unzip \"".substr($val, 0, (strlen($val)-4)).".zip\" -d \"".substr($val, 0, (strrpos($val, "/")))."/\""; break;
			//	case "7ip" : $cmd = "/opt/bin/zup x -o+ \"".substr($val, 0, (strlen($val)-4)).".7ip\" \"".substr($val, 0, (strrpos($val, "/")))."/\""; break;
				case "tar" : $cmd = "tar -x -f \"".substr($val, 0, (strlen($val)-4)).".tar\" -C \"".substr($val, 0, (strrpos($val, "/")))."/\""; break;
				case "spk" : $cmd = "tar -x -f \"".substr($val, 0, (strlen($val)-4)).".spk\" -C \"".substr($val, 0, (strrpos($val, "/")))."/\""; break;
				case "tgz" : $cmd = "tar -xzv -f \"".substr($val, 0, (strlen($val)-4)).".tgz\" -C \"".substr($val, 0, (strrpos($val, "/")))."/\""; break;
				default    : $cmd = "/opt/bin/unrar x -o+ \"".substr($val, 0, (strlen($val)-4)).".rar\" \"".substr($val, 0, (strrpos($val, "/")))."/\""; break;	
			}
			
			if(is_file($val))
				$d = shell_exec($cmd." > \"".LogName($val)."\" 2>&1 &");
					
			echo "  <input type=hidden name=\"log\" value=\"".LogName($val)."\">\n";
			echo "  <input type=hidden name=\"cmd\" value=\"".htmlspecialchars($cmd)."\">\n";
		}
		else
			echo "  <input type=hidden name=\"files[]\" value=\"$val\">\n";	
	}
	
	echo " </form>\n";		
	echo "<body onload=\"ReSubmitUnpack('unpack')\">\n";
}
else
	header("Location: unpacking.php");
?>