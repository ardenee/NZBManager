<?php
require("nzbp.php");
include("unpack.inc");
$nzb  = new nzb;
$file = "";

if(isset($_GET["file"]))
	$file = $_GET["file"];// urldecode() not needed??
	
if(strlen($file)>0) // single file
{	
	$cmd = "/opt/bin/par2 r \"$file\"";
	$log = LogName($file);
		
	if(is_file($file))
		$d = shell_exec($cmd." > \"".$log."\" 2>&1 &");
		
	header("Location: repairreport.php?log=".urlencode($log)."&cmd=".urlencode($cmd));
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
	
	echo " <form action=repairreport.php method=post id=repair name=repair>\n";

	foreach($_POST["files"] as $key => $val)
	{
		if($key==0) // always repair first file then send to report with additional files
		{
			$cmd = "/opt/bin/par2 r \"$val\"";
	
			if(is_file($val))
				$d = shell_exec($cmd." > \"".LogName($val)."\" 2>&1 &");
					
			echo "  <input type=hidden name=\"log\" value=\"".LogName($val)."\">\n";
			echo "  <input type=hidden name=\"cmd\" value=\"".htmlspecialchars($cmd)."\">\n";
		}
		else
			echo "  <input type=hidden name=\"files[]\" value=\"$val\">\n";	
	}
	
	echo " </form>\n";		
	echo "<body onload=\"ReSubmitReport('repair')\">\n";
	
}
else
	header("Location: par2.php");
?>